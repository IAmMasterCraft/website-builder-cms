<?php
declare(strict_types=1);

use Core\Mail\BounceParser;
use Core\Mail\Message;

/*
 * Bounce and complaint handling (Addendum A5, hardened after the second
 * security review).
 *
 * Every outgoing queued message gets a signed per-message tag ("c123-…" for
 * a campaign recipient row, "q45-…" for an email_queue row; the HMAC covers
 * the row and the address it was sent to). It is used in the VERP envelope
 * sender (bounces+TAG@domain), the Message-ID and an X-Gather-Message-Id
 * header, so a delivery report maps to exactly one row.
 *
 * Who is trusted for what:
 * - SMTP-time rejections (our own relay) and authenticated provider
 *   webhooks act on the address they name, but only when we sent a message
 *   to it in the last 30 days (otherwise: recorded for review).
 * - The bounce mailbox is public: anyone can email it. A mailbox report
 *   (DSN, ARF, legacy text bounce) acts only when it carries a valid tag for
 *   a message that was actually sent, and then only on the address stored
 *   in that row, never on an address written in the report. Untagged or
 *   forged reports are recorded with review = 'pending' for an admin
 *   (Suppression list) and change nothing. At most
 *   BOUNCE_REPORT_MAX_RECIPIENTS events are recorded per report.
 *
 * Rules once a report is trusted: hard -> suppressed at once; soft ->
 * suppressed after 3 within 30 days; complaint -> suppressed from bulk mail
 * at once; block -> the campaign is paused (SMTP-time or provider block, or
 * mailbox blocks from BOUNCE_BLOCK_PAUSE_DOMAINS different recipient
 * domains) and the health page warns: a reputation/content problem, not a
 * bad address.
 */

const BOUNCE_SOFT_LIMIT = 3;
const BOUNCE_SOFT_DAYS = 30;
const BOUNCE_RATE_WARN = 0.02;
const COMPLAINT_RATE_WARN = 0.001;
const BOUNCE_TAG_SIG_CHARS = 12;
/** How long after sending an authenticated report without a tag may still be matched to a message by address. */
const BOUNCE_MATCH_DAYS = 30;
/** Events recorded from one mailbox report at most (a forged report could list thousands of addresses). */
const BOUNCE_REPORT_MAX_RECIPIENTS = 5;
/** Sources that are unauthenticated email to the public bounce mailbox. */
const BOUNCE_MAILBOX_SOURCES = ['dsn', 'arf'];
/** Mailbox-reported blocks pause a campaign only when they come from this many different recipient domains. */
const BOUNCE_BLOCK_PAUSE_DOMAINS = 3;

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

/**
 * Signed tag for a queue row: kind 'c' (campaign_recipients) or 'q'
 * (email_queue). The signature covers the row and the address it is sent
 * to, so a tag cannot be moved to another row or address.
 */
function verp_tag(string $kind, int $id, string $email): string
{
    if ($kind !== 'c' && $kind !== 'q') {
        throw new InvalidArgumentException('tag kind');
    }
    return $kind . $id . '-' . verp_signature($kind, $id, $email);
}

function verp_signature(string $kind, int $id, string $email): string
{
    return substr(app_sign('verp2|' . $kind . $id . '|' . strtolower(trim($email))), 0, BOUNCE_TAG_SIG_CHARS);
}

/** A queue row by table and id. @return array{table:string,id:int,email:string,campaign_id:?int,sent_at:?string}|null */
function bounce_queue_row(string $table, int $id): ?array
{
    $row = match ($table) {
        'campaign_recipients' => q_one('SELECT id, email, campaign_id, sent_at FROM campaign_recipients WHERE id = ?', [$id]),
        'email_queue' => q_one('SELECT id, to_email AS email, NULL AS campaign_id, sent_at FROM email_queue WHERE id = ?', [$id]),
        default => throw new InvalidArgumentException('table'),
    };
    if ($row === null) {
        return null;
    }
    return ['table' => $table, 'id' => (int) $row['id'], 'email' => strtolower((string) $row['email']),
        'campaign_id' => $row['campaign_id'] !== null ? (int) $row['campaign_id'] : null, 'sent_at' => $row['sent_at'] !== null ? (string) $row['sent_at'] : null];
}

/**
 * The queue row a tag points to, from any text that contains it (a VERP
 * address, a Message-ID, a header value). Forged or altered tags, tags for
 * rows that do not exist, and tags signed before the address was part of
 * the signature are ignored. At most 10 different candidates are checked.
 * @return array{table:string,id:int,email:string,campaign_id:?int,sent_at:?string}|null
 */
function verp_parse(string $text): ?array
{
    if (!preg_match_all('/\b([qc])(\d{1,12})-([a-f0-9]{' . BOUNCE_TAG_SIG_CHARS . '})\b/i', $text, $m, PREG_SET_ORDER)) {
        return null;
    }
    $seen = [];
    foreach ($m as $hit) {
        $key = strtolower($hit[0]);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        if (count($seen) > 10) {
            break;
        }
        $kind = strtolower($hit[1]);
        $row = bounce_queue_row($kind === 'c' ? 'campaign_recipients' : 'email_queue', (int) $hit[2]);
        if ($row !== null && hash_equals(verp_signature($kind, $row['id'], $row['email']), strtolower($hit[3]))) {
            return $row;
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
 * The queue row a report is about: by tag first; for authenticated sources
 * ($byAddress) otherwise the latest message sent to the address in the last
 * 30 days.
 * @return array{table:string,id:int,email:string,campaign_id:?int,sent_at:?string}|null
 */
function bounce_find_row(?string $tagText, string $email, bool $byAddress = true): ?array
{
    $hit = $tagText !== null && $tagText !== '' ? verp_parse($tagText) : null;
    if ($hit !== null) {
        return $hit;
    }
    $email = strtolower(trim($email));
    if (!$byAddress || $email === '') {
        return null;
    }
    $since = gmdate('Y-m-d H:i:s', time() - BOUNCE_MATCH_DAYS * 86400);
    $c = q_one('SELECT id, email, campaign_id, sent_at FROM campaign_recipients WHERE email = ? AND sent_at > ? ORDER BY sent_at DESC, id DESC LIMIT 1', [$email, $since]);
    $q = q_one('SELECT id, to_email AS email, sent_at FROM email_queue WHERE to_email = ? AND sent_at > ? ORDER BY sent_at DESC, id DESC LIMIT 1', [$email, $since]);
    if ($c !== null && ($q === null || $c['sent_at'] >= $q['sent_at'])) {
        return ['table' => 'campaign_recipients', 'id' => (int) $c['id'], 'email' => $email, 'campaign_id' => (int) $c['campaign_id'], 'sent_at' => (string) $c['sent_at']];
    }
    if ($q !== null) {
        return ['table' => 'email_queue', 'id' => (int) $q['id'], 'email' => $email, 'campaign_id' => null, 'sent_at' => (string) $q['sent_at']];
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
 * source, tag (text that may contain a tag), row (a queue row already
 * looked up from a verified tag), ext_id (dedupe key), now.
 *
 * A report acts only when it is tied to a message we sent: by its verified
 * tag, or (SMTP time and authenticated webhooks only) by the address. The
 * address acted on is always the one in our row. Everything else is stored
 * with review = 'pending' and changes nothing.
 * Returns recorded | review | duplicate | ignored.
 */
function bounce_record(array $ev): string
{
    $type = (string) ($ev['type'] ?? '');
    if (!in_array($type, ['hard', 'soft', 'block', 'complaint'], true)) {
        return 'ignored';
    }
    $now = (int) ($ev['now'] ?? time());
    $nowSql = gmdate('Y-m-d H:i:s', $now);
    $source = (string) ($ev['source'] ?? 'smtp');
    $mailbox = in_array($source, BOUNCE_MAILBOX_SOURCES, true);
    $row = isset($ev['row']) && is_array($ev['row']) ? $ev['row']
        : bounce_find_row(isset($ev['tag']) ? (string) $ev['tag'] : null, (string) ($ev['email'] ?? ''), !$mailbox);
    if ($row !== null && $mailbox && $row['sent_at'] === null) {
        $row = null; // a tag for a message that never left: not a real report
    }
    // The address we sent to (a report may name the final, forwarded address instead).
    $email = $row['email'] ?? strtolower(trim((string) ($ev['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'ignored';
    }
    $review = $row === null ? 'pending' : '';
    $detail = mb_substr(trim((string) ($ev['detail'] ?? '')), 0, 500);
    $ins = q('INSERT IGNORE INTO email_events (email, type, status_code, smtp_code, detail, source, queue_table, queue_id, campaign_id, ext_id, review, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $email, $type, mb_substr((string) ($ev['status'] ?? ''), 0, 12), ($ev['smtp_code'] ?? null) ? (int) $ev['smtp_code'] : null, $detail, $source,
        $row['table'] ?? null, $row['id'] ?? null, $row['campaign_id'] ?? null, isset($ev['ext_id']) && $ev['ext_id'] !== '' ? mb_substr((string) $ev['ext_id'], 0, 190) : null,
        $review, $nowSql,
    ]);
    if ($ins->rowCount() !== 1) {
        return 'duplicate';
    }
    if ($row === null) {
        return 'review';
    }
    // Keep the most serious outcome on the row: complaint > hard > block > soft.
    $rank = "FIELD(bounce, 'soft', 'block', 'hard', 'complaint')";
    $newRank = ['soft' => 1, 'block' => 2, 'hard' => 3, 'complaint' => 4][$type];
    q('UPDATE ' . db_ident($row['table']) . " SET bounce = ?, bounced_at = ? WHERE id = ? AND (bounce IS NULL OR $rank < ?)", [$type, $nowSql, $row['id'], $newRank]);
    bounce_apply_rules($email, $type, $detail, $source, $now, $row['campaign_id'], $mailbox);
    return 'recorded';
}

/** The suppression/pause rules for a trusted event (also used when an admin applies a reviewed report). */
function bounce_apply_rules(string $email, string $type, string $detail, string $source, int $now, ?int $campaignId, bool $mailbox): void
{
    switch ($type) {
        case 'hard':
            email_suppress($email, 'hard_bounce', $detail, $source);
            break;
        case 'complaint':
            email_suppress($email, 'complaint', $detail !== '' ? $detail : 'spam complaint', $source);
            break;
        case 'soft':
            $since = gmdate('Y-m-d H:i:s', $now - BOUNCE_SOFT_DAYS * 86400);
            if ((int) q_val("SELECT COUNT(*) FROM email_events WHERE email = ? AND type = 'soft' AND review IN ('', 'applied') AND created_at > ?", [$email, $since]) >= BOUNCE_SOFT_LIMIT) {
                email_suppress($email, 'soft_bounce', $detail, $source);
            }
            break;
        case 'block':
            // Any recipient can send us a DSN with their own tag, so mailbox blocks pause only
            // when several recipient domains report them; SMTP-time and provider blocks pause at once.
            if ($campaignId !== null && (!$mailbox || bounce_block_domains($campaignId) >= BOUNCE_BLOCK_PAUSE_DOMAINS)) {
                campaign_pause($campaignId, 'A receiving server refused a message as spam or for policy reasons (' . ($detail !== '' ? mb_substr($detail, 0, 200) : $email) . '). Check the content and your SPF/DKIM/DMARC set-up, then resume.');
            }
            break;
    }
}

/** Different recipient domains that reported a block for a campaign. */
function bounce_block_domains(int $campaignId): int
{
    return (int) q_val("SELECT COUNT(DISTINCT SUBSTRING_INDEX(email, '@', -1)) FROM email_events WHERE campaign_id = ? AND type = 'block' AND review IN ('', 'applied')", [$campaignId]);
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
 * Process one raw message from the bounce mailbox. A report with a valid tag
 * for a sent message records one event for that message's address; one
 * without records up to BOUNCE_REPORT_MAX_RECIPIENTS events for review.
 * Returns recorded (an event was applied), review (only events waiting for
 * an admin), ignored (auto-reply, delay notice, duplicate) or unparsed.
 */
function bounce_process_message(string $raw, ?int $now = null): string
{
    $now ??= time();
    if (strlen($raw) > BOUNCE_MAX_MESSAGE_BYTES) {
        return 'unparsed';
    }
    $r = BounceParser::parse($raw);
    if ($r['kind'] === 'auto-reply') {
        return 'ignored';
    }
    if ($r['kind'] === 'unknown' || ($r['kind'] === 'text-bounce' && $r['recipients'] === [])) {
        return 'unparsed';
    }
    $row = verp_parse(implode(' ', array_merge($r['envelope'], $r['gather_ids'])));
    if ($row !== null && $row['sent_at'] === null) {
        $row = null;
    }
    $hash = hash('sha256', $raw);
    $results = [];
    if ($r['kind'] === 'arf') {
        if (in_array($r['feedback_type'], ['not-spam', 'auth-failure'], true)) {
            return 'ignored';
        }
        $ev = ['type' => 'complaint', 'detail' => 'Feedback report: ' . $r['feedback_type'], 'source' => 'arf', 'now' => $now];
        if ($row !== null) {
            $results[] = bounce_record($ev + ['row' => $row, 'ext_id' => 'mbox:' . $hash . ':t']);
        } else {
            foreach (array_slice($r['recipients'], 0, BOUNCE_REPORT_MAX_RECIPIENTS) as $i => $rc) {
                $results[] = bounce_record($ev + ['email' => $rc['email'], 'tag' => '', 'ext_id' => 'mbox:' . $hash . ':' . $i]);
            }
        }
        return bounce_process_result($results);
    }
    $failures = [];
    foreach ($r['recipients'] as $rc) {
        if (in_array($rc['action'], ['delayed', 'delivered', 'relayed', 'expanded'], true)) {
            continue;
        }
        $type = bounce_classify($rc['status'], 0, $rc['diagnostic']);
        if (in_array($type, ['hard', 'soft', 'block'], true)) {
            $failures[] = $rc + ['type' => $type];
        }
    }
    if ($failures === []) {
        return $r['recipients'] !== [] ? 'ignored' : 'unparsed';
    }
    $event = static function (array $rc) use ($now): array {
        $code = preg_match('/\b([45]\d\d)\b/', $rc['diagnostic'], $m) ? (int) $m[1] : null;
        return ['email' => $rc['email'], 'type' => $rc['type'], 'status' => $rc['status'], 'smtp_code' => $code,
            'detail' => $rc['diagnostic'] !== '' ? $rc['diagnostic'] : $rc['status'], 'source' => 'dsn', 'now' => $now];
    };
    if ($row !== null) {
        // The tag names exactly one message: one event, for the address in our row. The report's
        // line for that address gives the diagnosis (or the first failure when it names another one).
        $mine = array_values(array_filter($failures, static fn (array $f): bool => strtolower($f['email']) === $row['email']));
        $results[] = bounce_record($event($mine[0] ?? $failures[0]) + ['row' => $row, 'ext_id' => 'mbox:' . $hash . ':t']);
    } else {
        foreach (array_slice($failures, 0, BOUNCE_REPORT_MAX_RECIPIENTS) as $i => $rc) {
            $results[] = bounce_record($event($rc) + ['tag' => '', 'ext_id' => 'mbox:' . $hash . ':' . $i]);
        }
    }
    return bounce_process_result($results);
}

/** @param list<string> $results bounce_record() answers */
function bounce_process_result(array $results): string
{
    return in_array('recorded', $results, true) ? 'recorded' : (in_array('review', $results, true) ? 'review' : 'ignored');
}

/** Reports waiting for an admin (untagged mailbox reports, provider reports for addresses we did not mail). */
function bounce_review_count(): int
{
    return (int) q_val("SELECT COUNT(*) FROM email_events WHERE review = 'pending'");
}

/**
 * An admin's decision on a report waiting for review: apply (the rules run
 * as for a trusted report, with source "review"; a block never pauses) or
 * dismiss. Returns the event, or null when it is not waiting.
 */
function bounce_review_decide(int $id, string $decision, ?int $now = null): ?array
{
    if (!in_array($decision, ['apply', 'dismiss'], true)) {
        return null;
    }
    $ev = q_one("SELECT * FROM email_events WHERE id = ? AND review = 'pending'", [$id]);
    if ($ev === null) {
        return null;
    }
    q('UPDATE email_events SET review = ? WHERE id = ?', [$decision === 'apply' ? 'applied' : 'dismissed', $id]);
    if ($decision === 'apply' && $ev['type'] !== 'block') {
        bounce_apply_rules((string) $ev['email'], (string) $ev['type'], (string) $ev['detail'], 'review', $now ?? time(), null, true);
    }
    return $ev;
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
    foreach (q_all("SELECT type, COUNT(*) AS n FROM email_events WHERE created_at > ? AND review IN ('', 'applied') GROUP BY type", [$since]) as $r) {
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
        'unparsed' => (int) ($s['unparsed'] ?? 0), 'oversized' => (int) ($s['oversized'] ?? 0), 'last_result' => (string) ($s['last_result'] ?? '')];
}

// ---- Bounce mailbox polling (cron) -------------------------------------------------------

/** Messages read per cron run, so one run never takes too long. */
const BOUNCE_POLL_LIMIT = 200;
/** Real delivery reports are small; anything bigger is set aside without being downloaded. */
const BOUNCE_MAX_MESSAGE_BYTES = 2 * 1024 * 1024;

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
 * Messages over BOUNCE_MAX_MESSAGE_BYTES (by the size the server reports,
 * or found while reading) are moved aside (IMAP) or deleted (POP3) without
 * being downloaded or parsed, so no message can stay at the head of the
 * mailbox and stop the ones behind it.
 * @return array{read:int, recorded:int, review:int, ignored:int, unparsed:int, oversized:int, pruned:int, error:string}
 */
function bounce_mailbox_poll(?Core\Mail\BounceMailbox $mb = null, int $limit = BOUNCE_POLL_LIMIT, ?int $now = null): array
{
    $now ??= time();
    $stats = ['read' => 0, 'recorded' => 0, 'review' => 0, 'ignored' => 0, 'unparsed' => 0, 'oversized' => 0, 'pruned' => 0, 'error' => ''];
    $mb ??= bounce_mailbox_client();
    if ($mb === null) {
        $stats['error'] = 'off';
        return $stats;
    }
    $cfg = bounce_settings()['mailbox'];
    // Set aside (IMAP folder) or, without folders, delete; the raw message is kept locally only when we have it.
    $aside = static function (string $id, ?string $raw) use ($mb, $now): void {
        if (!$mb->moveAside($id)) {
            if ($raw !== null) {
                bounce_save_aside($raw, $now);
            }
            $mb->delete($id);
        }
    };
    try {
        $mb->open();
        foreach ($mb->list($limit) as $id => $size) {
            $id = (string) $id;
            $stats['read']++;
            if ($size > BOUNCE_MAX_MESSAGE_BYTES) {
                error_log('Bounce message ' . $id . ' set aside unread: ' . $size . ' bytes');
                $stats['oversized']++;
                $aside($id, null);
                continue;
            }
            try {
                $raw = $mb->fetch($id, BOUNCE_MAX_MESSAGE_BYTES);
            } catch (Core\Mail\MessageUnreadableException $e) {
                error_log('Bounce message ' . $id . ' set aside: ' . $e->getMessage());
                $stats[$e instanceof Core\Mail\MessageTooLargeException ? 'oversized' : 'unparsed']++;
                $aside($id, null);
                continue;
            }
            try {
                $result = bounce_process_message($raw, $now);
            } catch (Throwable $e) {
                error_log('Bounce message could not be processed: ' . $e->getMessage());
                $result = 'unparsed';
            }
            $stats[$result]++;
            if ($result === 'unparsed') {
                $aside($id, $raw);
            } else {
                $mb->delete($id);
            }
            unset($raw);
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
        'last_poll' => $now, 'last_error' => $stats['error'], 'processed' => $state['processed'] + $stats['read'],
        'unparsed' => $state['unparsed'] + $stats['unparsed'] + $stats['oversized'], 'oversized' => $state['oversized'] + $stats['oversized'],
        'last_result' => sprintf('%d read, %d recorded, %d to review, %d ignored, %d unprocessed (%d too large)', $stats['read'], $stats['recorded'], $stats['review'], $stats['ignored'], $stats['unparsed'] + $stats['oversized'], $stats['oversized']),
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
