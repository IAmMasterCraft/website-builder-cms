<?php
declare(strict_types=1);

use Core\Mail\BounceParser;
use Core\Mail\Message;

/*
 * Bounce and complaint handling (Addendum A5).
 *
 * Every outgoing queued message gets a signed per-message tag ("c123-…" for
 * a campaign recipient row, "q45-…" for an email_queue row). It is used in
 * the VERP envelope sender (bounces+TAG@domain), the Message-ID and an
 * X-Gather-Message-Id header, so a delivery report maps to exactly one row.
 * Failures are classified as hard, soft, block (policy/spam) or complaint;
 * bounce_record() stores the event and applies the suppression rules:
 *   hard -> suppressed at once; soft -> suppressed after 3 within 30 days;
 *   complaint -> suppressed from bulk mail at once; block -> the campaign is
 *   paused and the health page warns (a reputation/content problem, not a
 *   bad address).
 */

const BOUNCE_SOFT_LIMIT = 3;
const BOUNCE_SOFT_DAYS = 30;
const BOUNCE_RATE_WARN = 0.02;
const COMPLAINT_RATE_WARN = 0.001;
const BOUNCE_TAG_SIG_CHARS = 12;
/** How long after sending a report without a tag may still be matched to a message by address. */
const BOUNCE_MATCH_DAYS = 30;

/** Bounce settings (super admin, Email settings) with defaults; secrets stay sealed here. */
function bounce_settings(): array
{
    $d = [
        'address' => '',
        'mailbox' => ['protocol' => 'off', 'host' => '', 'port' => 993, 'encryption' => 'ssl', 'username' => '', 'password' => '',
            'folder' => 'INBOX', 'aside_folder' => 'Gather-Unprocessed', 'max_messages' => 500, 'max_age_days' => 30, 'cafile' => ''],
        'per_domain_minute' => 30,
        'webhooks' => ['ses' => false, 'ses_topics' => '', 'mailgun_key' => '', 'postmark_user' => '', 'postmark_password' => '', 'postmark_token' => '',
            'sendgrid_key' => '', 'brevo_token' => ''],
    ];
    $s = setting('mail.bounce', []);
    $s = is_array($s) ? $s : [];
    $out = $d;
    $out['address'] = is_string($s['address'] ?? null) ? $s['address'] : '';
    $out['per_domain_minute'] = max(0, min(10000, (int) ($s['per_domain_minute'] ?? $d['per_domain_minute'])));
    foreach (['mailbox', 'webhooks'] as $group) {
        foreach ($d[$group] as $k => $v) {
            if (isset($s[$group][$k]) && gettype($s[$group][$k]) === gettype($v)) {
                $out[$group][$k] = $s[$group][$k];
            }
        }
    }
    return $out;
}

/** A sealed bounce secret (mailbox password, webhook keys), decrypted. */
function bounce_secret(string $group, string $key): string
{
    return secret_open((string) (bounce_settings()[$group][$key] ?? ''));
}

// ---- Per-message tags and envelope -----------------------------------------------------

/** Signed tag for a queue row: kind 'c' (campaign_recipients) or 'q' (email_queue). */
function verp_tag(string $kind, int $id): string
{
    if ($kind !== 'c' && $kind !== 'q') {
        throw new InvalidArgumentException('tag kind');
    }
    return $kind . $id . '-' . substr(app_sign('verp|' . $kind . $id), 0, BOUNCE_TAG_SIG_CHARS);
}

/**
 * The queue row a tag points to, from any text that contains it (a VERP
 * address, a Message-ID, a header value). Forged or altered tags are ignored.
 * @return array{0:string,1:int}|null [table, id]
 */
function verp_parse(string $text): ?array
{
    if (!preg_match_all('/\b([qc])(\d{1,12})-([a-f0-9]{' . BOUNCE_TAG_SIG_CHARS . '})\b/i', $text, $m, PREG_SET_ORDER)) {
        return null;
    }
    foreach ($m as $hit) {
        $kind = strtolower($hit[1]);
        $id = (int) $hit[2];
        if (hash_equals(substr(app_sign('verp|' . $kind . $id), 0, BOUNCE_TAG_SIG_CHARS), strtolower($hit[3]))) {
            return [$kind === 'c' ? 'campaign_recipients' : 'email_queue', $id];
        }
    }
    return null;
}

/** bounces+TAG@domain for the configured bounce address, or null when none is set. */
function verp_return_path(?string $tag): ?string
{
    $addr = strtolower(trim(bounce_settings()['address']));
    if ($addr === '' || !filter_var($addr, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    if ($tag === null) {
        return $addr;
    }
    [$local, $domain] = explode('@', $addr, 2);
    $local = explode('+', $local, 2)[0];
    $out = $local . '+' . $tag . '@' . $domain;
    return strlen($local . '+' . $tag) <= 64 && filter_var($out, FILTER_VALIDATE_EMAIL) ? $out : $addr;
}

/**
 * Headers that let reports find the message: our tag, a unique Message-ID
 * that contains it, the provider's own metadata header when the SMTP host
 * is that provider (so webhooks echo the tag back), and for campaigns a
 * Feedback-ID (Gmail feedback loop).
 */
function mail_tracking_headers(string $tag, ?int $campaignId = null): array
{
    [$fromEmail] = mail_from();
    $domain = strtolower(explode('@', $fromEmail)[1] ?? 'localhost');
    $h = [
        'X-Gather-Message-Id' => $tag,
        'Message-ID' => '<' . $tag . '.' . bin2hex(random_bytes(6)) . '@' . (preg_match('/^[a-z0-9.-]+$/', $domain) ? $domain : 'localhost') . '>',
    ];
    if ($campaignId !== null) {
        $h['Feedback-ID'] = 'c' . $campaignId . ':campaign:members:gather';
    }
    $host = strtolower((string) (((array) setting('mail.smtp', []))['host'] ?? ''));
    if (mail_driver() === 'smtp' && $host !== '') {
        if (str_contains($host, 'mailgun')) {
            $h['X-Mailgun-Variables'] = json_encode(['gather_id' => $tag], JSON_THROW_ON_ERROR);
        } elseif (str_contains($host, 'sendgrid')) {
            $h['X-SMTPAPI'] = json_encode(['unique_args' => ['gather_id' => $tag]], JSON_THROW_ON_ERROR);
        } elseif (str_contains($host, 'postmark')) {
            $h['X-PM-Metadata-gather-id'] = $tag;
        } elseif (str_contains($host, 'brevo') || str_contains($host, 'sendinblue')) {
            $h['X-Mailin-custom'] = $tag;
        } elseif (str_contains($host, 'amazonaws.com')) {
            $h['X-SES-MESSAGE-TAGS'] = 'gather_id=' . $tag;
        }
    }
    return $h;
}

/** The message with its VERP envelope sender and tracking headers for queue row $tag. */
function mail_with_envelope(Message $m, string $tag, ?int $campaignId = null): Message
{
    return $m->withEnvelope(verp_return_path($tag), mail_tracking_headers($tag, $campaignId));
}

// ---- Classification ------------------------------------------------------------------------

/**
 * Classify a failure from its enhanced status (RFC 3463), SMTP reply code
 * and diagnostic text: hard | soft | block | none (a success/delay notice)
 * | unknown.
 */
function bounce_classify(string $status, int $smtpCode, string $text): string
{
    $t = strtolower($text);
    if ($status === '' && preg_match('/\b([245]\.\d{1,3}\.\d{1,3})\b/', $text, $m)) {
        $status = $m[1];
    }
    if ($smtpCode === 0 && preg_match('/\b([245]\d\d)[ -]/', $text, $m)) {
        $smtpCode = (int) $m[1];
    }
    $full = (bool) preg_match('/mailbox (is )?full|over ?quota|quota exceeded|exceeded (the )?(storage|quota)|insufficient (system )?storage|mailbox size limit|out of storage/', $t);
    $unknown = (bool) preg_match("/user unknown|unknown (user|recipient)|no such (user|mailbox|recipient|address)|does ?n[o']t exist|not exist|invalid (recipient|mailbox|address)|recipient (address )?rejected|address rejected|mailbox (is )?unavailable|mailbox not found|recipient not found|recipientnotfound|account (has been |is )?(disabled|inactive|suspended|deleted)|no mailbox|unrouteable|domain (not found|does not exist)|host (or domain name )?not found|no mx|bad destination/", $t);
    $spam = (bool) preg_match('/spam|blocked|block ?list|blacklist|listed (at|in|on|by)|reputation|polic(y|ies)|banned|abuse|not authori[sz]ed to send|dmarc|\bspf\b|dkim|unsolicited|rate that prevents/', $t);
    if (preg_match('/^([245])\.(\d{1,3})\.(\d{1,3})$/', $status, $s)) {
        [$cls, $sub, $det] = [(int) $s[1], (int) $s[2], (int) $s[3]];
        if ($cls === 2) {
            return 'none';
        }
        if ($cls === 4) {
            return 'soft';
        }
        switch ($sub) {
            case 7:
                return 'block';
            case 1:
                return 'hard';
            case 2:
                // 5.2.1 mailbox disabled = hard; 5.2.2 mailbox full and 5.2.3 message too big = soft.
                return $det === 1 ? 'hard' : ($det === 2 || $det === 3 || $full ? 'soft' : 'hard');
            case 4:
                // 5.4.4 unable to route (no MX), 5.4.1 recipient rejected (Exchange Online) = hard; the rest gave up on a temporary problem.
                return $det === 4 || $det === 1 ? 'hard' : 'soft';
        }
    }
    if ($full) {
        return 'soft';
    }
    if ($smtpCode >= 400 && $smtpCode < 500) {
        return 'soft';
    }
    if ($spam && !$unknown) {
        return 'block';
    }
    if ($unknown) {
        return 'hard';
    }
    if ($smtpCode >= 500) {
        return $smtpCode === 552 ? 'soft' : 'hard';
    }
    if (preg_match('/timed? ?out|temporar|try again|deferred|greylist/', $t)) {
        return 'soft';
    }
    if ($status !== '') {
        return $status[0] === '5' ? 'hard' : 'soft';
    }
    return 'unknown';
}

// ---- Recording and suppression -------------------------------------------------------------

/**
 * The queue row a report is about: by tag first, otherwise the latest
 * message sent to the address in the last 30 days.
 * @return array{table:string,id:int,email:string,campaign_id:?int}|null
 */
function bounce_find_row(?string $tagText, string $email): ?array
{
    $hit = $tagText !== null && $tagText !== '' ? verp_parse($tagText) : null;
    if ($hit !== null) {
        [$table, $id] = $hit;
        $row = $table === 'campaign_recipients'
            ? q_one('SELECT id, email, campaign_id FROM campaign_recipients WHERE id = ?', [$id])
            : q_one('SELECT id, to_email AS email, NULL AS campaign_id FROM email_queue WHERE id = ?', [$id]);
        if ($row !== null) {
            return ['table' => $table, 'id' => (int) $row['id'], 'email' => strtolower((string) $row['email']), 'campaign_id' => $row['campaign_id'] !== null ? (int) $row['campaign_id'] : null];
        }
    }
    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }
    $since = gmdate('Y-m-d H:i:s', time() - BOUNCE_MATCH_DAYS * 86400);
    $c = q_one('SELECT id, email, campaign_id, sent_at FROM campaign_recipients WHERE email = ? AND sent_at > ? ORDER BY sent_at DESC, id DESC LIMIT 1', [$email, $since]);
    $q = q_one('SELECT id, to_email AS email, sent_at FROM email_queue WHERE to_email = ? AND sent_at > ? ORDER BY sent_at DESC, id DESC LIMIT 1', [$email, $since]);
    if ($c !== null && ($q === null || $c['sent_at'] >= $q['sent_at'])) {
        return ['table' => 'campaign_recipients', 'id' => (int) $c['id'], 'email' => $email, 'campaign_id' => (int) $c['campaign_id']];
    }
    if ($q !== null) {
        return ['table' => 'email_queue', 'id' => (int) $q['id'], 'email' => $email, 'campaign_id' => null];
    }
    return null;
}

/** Strength of a suppression reason: a stronger one replaces a weaker one. */
function suppression_rank(string $reason): int
{
    return ['unsubscribe' => 1, 'soft_bounce' => 2, 'complaint' => 3, 'hard_bounce' => 4][$reason] ?? 0;
}

/** Add or strengthen a suppression. */
function email_suppress(string $email, string $reason, string $detail = '', string $source = ''): void
{
    $email = strtolower(trim($email));
    $current = email_suppression($email);
    if ($current === null) {
        q('INSERT IGNORE INTO email_suppressions (email, reason, detail, source) VALUES (?, ?, ?, ?)', [$email, $reason, mb_substr($detail, 0, 500), $source]);
        return;
    }
    if (suppression_rank($reason) > suppression_rank((string) $current['reason'])) {
        q('UPDATE email_suppressions SET reason = ?, detail = ?, source = ? WHERE email = ?', [$reason, mb_substr($detail, 0, 500), $source, $email]);
    }
}

/**
 * Record one bounce or complaint and apply the suppression rules.
 * $ev: email, type (hard|soft|block|complaint), status, smtp_code, detail,
 * source, tag (text that may contain a tag), ext_id (dedupe key), now.
 * Returns recorded | duplicate | ignored.
 */
function bounce_record(array $ev): string
{
    $type = (string) ($ev['type'] ?? '');
    if (!in_array($type, ['hard', 'soft', 'block', 'complaint'], true)) {
        return 'ignored';
    }
    $now = (int) ($ev['now'] ?? time());
    $nowSql = gmdate('Y-m-d H:i:s', $now);
    $row = bounce_find_row(isset($ev['tag']) ? (string) $ev['tag'] : null, (string) ($ev['email'] ?? ''));
    // The address we sent to (a report may name the final, forwarded address instead).
    $email = $row['email'] ?? strtolower(trim((string) ($ev['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'ignored';
    }
    $detail = mb_substr(trim((string) ($ev['detail'] ?? '')), 0, 500);
    $source = (string) ($ev['source'] ?? 'smtp');
    $ins = q('INSERT IGNORE INTO email_events (email, type, status_code, smtp_code, detail, source, queue_table, queue_id, campaign_id, ext_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $email, $type, mb_substr((string) ($ev['status'] ?? ''), 0, 12), ($ev['smtp_code'] ?? null) ? (int) $ev['smtp_code'] : null, $detail, $source,
        $row['table'] ?? null, $row['id'] ?? null, $row['campaign_id'] ?? null, isset($ev['ext_id']) && $ev['ext_id'] !== '' ? mb_substr((string) $ev['ext_id'], 0, 190) : null, $nowSql,
    ]);
    if ($ins->rowCount() !== 1) {
        return 'duplicate';
    }
    if ($row !== null) {
        // Keep the most serious outcome on the row: complaint > hard > block > soft.
        $rank = "FIELD(bounce, 'soft', 'block', 'hard', 'complaint')";
        $newRank = ['soft' => 1, 'block' => 2, 'hard' => 3, 'complaint' => 4][$type];
        q('UPDATE ' . db_ident($row['table']) . " SET bounce = ?, bounced_at = ? WHERE id = ? AND (bounce IS NULL OR $rank < ?)", [$type, $nowSql, $row['id'], $newRank]);
    }
    switch ($type) {
        case 'hard':
            email_suppress($email, 'hard_bounce', $detail, $source);
            break;
        case 'complaint':
            email_suppress($email, 'complaint', $detail !== '' ? $detail : 'spam complaint', $source);
            break;
        case 'soft':
            $since = gmdate('Y-m-d H:i:s', $now - BOUNCE_SOFT_DAYS * 86400);
            if ((int) q_val("SELECT COUNT(*) FROM email_events WHERE email = ? AND type = 'soft' AND created_at > ?", [$email, $since]) >= BOUNCE_SOFT_LIMIT) {
                email_suppress($email, 'soft_bounce', $detail, $source);
            }
            break;
        case 'block':
            if (($row['campaign_id'] ?? null) !== null) {
                campaign_pause((int) $row['campaign_id'], 'A receiving server refused a message as spam or for policy reasons (' . ($detail !== '' ? mb_substr($detail, 0, 200) : $email) . '). Check the content and your SPF/DKIM/DMARC set-up, then resume.');
            }
            break;
    }
    return 'recorded';
}

/** Pause a sending campaign after a block (waiting recipients stay queued). */
function campaign_pause(int $id, string $reason): bool
{
    $ok = q("UPDATE campaigns SET status = 'paused', pause_reason = ? WHERE id = ? AND status = 'sending'", [mb_substr($reason, 0, 500), $id])->rowCount() === 1;
    if ($ok) {
        error_log('Campaign #' . $id . ' paused: ' . $reason);
    }
    return $ok;
}

function campaign_resume(int $id): bool
{
    return q("UPDATE campaigns SET status = 'sending', pause_reason = '' WHERE id = ? AND status = 'paused'", [$id])->rowCount() === 1;
}

// ---- Delivery reports from the bounce mailbox --------------------------------------------

/**
 * Process one raw message from the bounce mailbox.
 * Returns recorded (at least one event), ignored (auto-reply, delay notice,
 * duplicate, a report about an address we do not know) or unparsed.
 */
function bounce_process_message(string $raw, ?int $now = null): string
{
    $now ??= time();
    if (strlen($raw) > 10 * 1024 * 1024) {
        return 'unparsed';
    }
    $r = BounceParser::parse($raw);
    if ($r['kind'] === 'auto-reply') {
        return 'ignored';
    }
    if ($r['kind'] === 'unknown' || ($r['kind'] === 'text-bounce' && $r['recipients'] === [])) {
        return 'unparsed';
    }
    $tagText = implode(' ', array_merge($r['envelope'], $r['gather_ids']));
    $hash = hash('sha256', $raw);
    $recorded = 0;
    if ($r['kind'] === 'arf') {
        if (in_array($r['feedback_type'], ['not-spam', 'auth-failure'], true)) {
            return 'ignored';
        }
        $recipients = $r['recipients'] !== [] ? $r['recipients'] : [['email' => '', 'diagnostic' => $r['feedback_type']]];
        foreach ($recipients as $i => $rc) {
            $res = bounce_record(['email' => $rc['email'], 'type' => 'complaint', 'detail' => 'Feedback report: ' . $r['feedback_type'], 'source' => 'arf',
                'tag' => $tagText, 'ext_id' => 'mbox:' . $hash . ':' . $i, 'now' => $now]);
            $recorded += $res === 'recorded' ? 1 : 0;
        }
        return $recorded > 0 ? 'recorded' : 'ignored';
    }
    $anyFailure = false;
    foreach ($r['recipients'] as $i => $rc) {
        if (in_array($rc['action'], ['delayed', 'delivered', 'relayed', 'expanded'], true)) {
            continue;
        }
        $type = bounce_classify($rc['status'], 0, $rc['diagnostic']);
        if (!in_array($type, ['hard', 'soft', 'block'], true)) {
            continue;
        }
        $anyFailure = true;
        $code = preg_match('/\b([45]\d\d)\b/', $rc['diagnostic'], $m) ? (int) $m[1] : null;
        // With several recipients only an untagged report needs the address; a tag names our one recipient.
        $res = bounce_record(['email' => $rc['email'], 'type' => $type, 'status' => $rc['status'], 'smtp_code' => $code,
            'detail' => $rc['diagnostic'] !== '' ? $rc['diagnostic'] : $rc['status'], 'source' => 'dsn',
            'tag' => count($r['recipients']) === 1 ? $tagText : '', 'ext_id' => 'mbox:' . $hash . ':' . $i, 'now' => $now]);
        $recorded += $res === 'recorded' ? 1 : 0;
    }
    if ($recorded > 0) {
        return 'recorded';
    }
    return $anyFailure || $r['recipients'] !== [] ? 'ignored' : 'unparsed';
}

// ---- Reporting ----------------------------------------------------------------------------

/**
 * Sent messages and bounce/complaint events over the last $days days.
 * @return array{sent:int, hard:int, soft:int, block:int, complaint:int, bounce_rate:float, complaint_rate:float}
 */
function bounce_stats(int $days, ?int $now = null): array
{
    $since = gmdate('Y-m-d H:i:s', ($now ?? time()) - $days * 86400);
    $sent = (int) q_val('SELECT (SELECT COUNT(*) FROM email_queue WHERE sent_at > ?) + (SELECT COUNT(*) FROM campaign_recipients WHERE sent_at > ?)', [$since, $since]);
    $out = ['sent' => $sent, 'hard' => 0, 'soft' => 0, 'block' => 0, 'complaint' => 0];
    foreach (q_all('SELECT type, COUNT(*) AS n FROM email_events WHERE created_at > ? GROUP BY type', [$since]) as $r) {
        $out[$r['type']] = (int) $r['n'];
    }
    $out['bounce_rate'] = $sent > 0 ? ($out['hard'] + $out['soft']) / $sent : 0.0;
    $out['complaint_rate'] = $sent > 0 ? $out['complaint'] / $sent : 0.0;
    return $out;
}

/** Bounce mailbox status (last poll, error, counters). */
function bounce_mailbox_state(): array
{
    $s = setting('mail.bounce_state', []);
    $s = is_array($s) ? $s : [];
    return ['last_poll' => (int) ($s['last_poll'] ?? 0), 'last_error' => (string) ($s['last_error'] ?? ''), 'processed' => (int) ($s['processed'] ?? 0),
        'unparsed' => (int) ($s['unparsed'] ?? 0), 'last_result' => (string) ($s['last_result'] ?? '')];
}

// ---- Bounce mailbox polling (cron) -------------------------------------------------------

/** Messages read per cron run, so one run never takes too long. */
const BOUNCE_POLL_LIMIT = 200;

/** Folder for unprocessed messages when the mailbox has no folders (POP3). */
function bounce_local_aside_dir(): string
{
    return BASE . '/storage/bounces';
}

/** The configured mailbox client, or null when polling is off. */
function bounce_mailbox_client(): ?Core\Mail\BounceMailbox
{
    $mb = bounce_settings()['mailbox'];
    if (!in_array($mb['protocol'], ['imap', 'pop3'], true) || $mb['host'] === '') {
        return null;
    }
    $cfg = ['host' => $mb['host'], 'port' => (int) $mb['port'], 'encryption' => $mb['encryption'], 'username' => $mb['username'],
        'password' => secret_open($mb['password']), 'folder' => $mb['folder'] !== '' ? $mb['folder'] : 'INBOX',
        'aside_folder' => $mb['aside_folder'] !== '' ? $mb['aside_folder'] : 'Gather-Unprocessed', 'cafile' => $mb['cafile'], 'timeout' => 20];
    return $mb['protocol'] === 'imap' ? new Core\Mail\ImapClient($cfg) : new Core\Mail\Pop3Client($cfg);
}

/**
 * Read new messages from the bounce mailbox, record bounces/complaints, and
 * delete what was processed so the mailbox never fills up. Messages that
 * cannot be understood are moved to the "unprocessed" folder (IMAP) or
 * saved under storage/bounces/ and deleted (POP3); both are kept under the
 * configured maximum count and age, and counted on the health page.
 * @return array{read:int, recorded:int, ignored:int, unparsed:int, pruned:int, error:string}
 */
function bounce_mailbox_poll(?Core\Mail\BounceMailbox $mb = null, int $limit = BOUNCE_POLL_LIMIT, ?int $now = null): array
{
    $now ??= time();
    $stats = ['read' => 0, 'recorded' => 0, 'ignored' => 0, 'unparsed' => 0, 'pruned' => 0, 'error' => ''];
    $mb ??= bounce_mailbox_client();
    if ($mb === null) {
        $stats['error'] = 'off';
        return $stats;
    }
    $cfg = bounce_settings()['mailbox'];
    try {
        $mb->open();
        foreach ($mb->list($limit) as $id) {
            $raw = $mb->fetch($id);
            $stats['read']++;
            try {
                $result = bounce_process_message($raw, $now);
            } catch (Throwable $e) {
                error_log('Bounce message could not be processed: ' . $e->getMessage());
                $result = 'unparsed';
            }
            $stats[$result]++;
            if ($result === 'unparsed') {
                if (!$mb->moveAside($id)) {
                    bounce_save_aside($raw, $now);
                    $mb->delete($id);
                }
            } else {
                $mb->delete($id);
            }
        }
        $stats['pruned'] = $mb->pruneAside((int) $cfg['max_messages'], (int) $cfg['max_age_days']) + bounce_prune_local((int) $cfg['max_messages'], (int) $cfg['max_age_days'], $now);
        $mb->close();
    } catch (Throwable $e) {
        $stats['error'] = mb_substr($e->getMessage(), 0, 300);
        error_log('Bounce mailbox: ' . $stats['error']);
        try {
            $mb->close();
        } catch (Throwable) {
            // already disconnected
        }
    }
    $state = bounce_mailbox_state();
    setting_set('mail.bounce_state', [
        'last_poll' => $now, 'last_error' => $stats['error'], 'processed' => $state['processed'] + $stats['read'], 'unparsed' => $state['unparsed'] + $stats['unparsed'],
        'last_result' => sprintf('%d read, %d recorded, %d ignored, %d unprocessed', $stats['read'], $stats['recorded'], $stats['ignored'], $stats['unparsed']),
    ]);
    return $stats;
}

/** Keep an unprocessed message locally (POP3 has no folders). */
function bounce_save_aside(string $raw, int $now): void
{
    $dir = bounce_local_aside_dir();
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        return;
    }
    file_put_contents($dir . '/' . gmdate('Ymd-His', $now) . '-' . bin2hex(random_bytes(4)) . '.eml', $raw);
}

/** Unprocessed messages kept locally, newest first. @return list<string> */
function bounce_local_aside_files(): array
{
    $files = glob(bounce_local_aside_dir() . '/*.eml') ?: [];
    rsort($files, SORT_STRING);
    return $files;
}

function bounce_prune_local(int $maxCount, int $maxAgeDays, int $now): int
{
    $n = 0;
    foreach (bounce_local_aside_files() as $i => $f) {
        $tooOld = $maxAgeDays > 0 && (int) filemtime($f) < $now - $maxAgeDays * 86400;
        if (($maxCount > 0 && $i >= $maxCount) || $tooOld) {
            unlink($f);
            $n++;
        }
    }
    return $n;
}
