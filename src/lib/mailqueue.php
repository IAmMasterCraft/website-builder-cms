<?php
declare(strict_types=1);

use Core\Mail\Message;
use Core\Mail\RecipientRejectedException;
use Core\Mail\TransportException;

/*
 * Email queue (§5): campaign recipients and queued messages are sent by
 * cron in batches limited per minute. A row is claimed (queued -> sending)
 * before it is sent, so two runs never send it twice; a row left in
 * "sending" by a crashed run is marked failed rather than re-sent, so a
 * recipient never gets the same campaign twice (§12). Queued rows simply
 * resume on the next run.
 */

const MAIL_MAX_ATTEMPTS = 5;
/** First retry delay; later ones follow mail_retry_delay(). */
const MAIL_RETRY_SECONDS = 300;
const MAIL_STUCK_SECONDS = 600;
/** Pause after the mail server is unreachable: doubles up to an hour. */
const MAIL_PAUSE_MIN_SECONDS = 300;
const MAIL_PAUSE_MAX_SECONDS = 3600;
/** Kinds in email_queue that are bulk mail (preferences and suppression re-checked at send time). */
const MAIL_BULK_KINDS = ['notice'];

function mail_per_minute(): int
{
    return max(1, min(10000, (int) setting('mail.per_minute', 60)));
}

/** Delay before retry number $attempt (1-based): 5 min, 30 min, 2 h, 6 h. */
function mail_retry_delay(int $attempt): int
{
    return [1 => MAIL_RETRY_SECONDS, 2 => 1800, 3 => 7200][$attempt] ?? 21600;
}

/** Put a ready message in the queue. Returns false when $dedupe was already queued. */
function mail_queue_message(Message $m, string $kind = 'transactional', ?string $dedupe = null, int $attempts = 0, string $error = '', ?int $now = null): bool
{
    $at = gmdate('Y-m-d H:i:s', ($now ?? time()) + ($attempts > 0 ? mail_retry_delay($attempts) : 0));
    $st = q(
        'INSERT IGNORE INTO email_queue (kind, dedupe_key, to_email, to_name, subject, text_body, html_body, headers, reply_to, attempts, last_error, next_attempt_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$kind, $dedupe, $m->toEmail, mb_substr($m->toName, 0, 120), mb_substr($m->subject, 0, 250), $m->text, $m->html,
            $m->headers ? json_encode($m->headers, JSON_THROW_ON_ERROR) : null, $m->replyTo, $attempts, $error, $at]
    );
    return $st->rowCount() === 1;
}

/** Messages sent in the 60 seconds before $now (both tables). */
function mail_sent_last_minute(int $now): int
{
    $since = gmdate('Y-m-d H:i:s', $now - 60);
    return (int) q_val('SELECT (SELECT COUNT(*) FROM email_queue WHERE sent_at > ?) + (SELECT COUNT(*) FROM campaign_recipients WHERE sent_at > ?)', [$since, $since]);
}

/**
 * Why a bulk email (campaign, notice copy) must not go to this address any
 * more, or null. Checked when the row is sent, not only when it was queued:
 * the person may have unsubscribed, switched newsletters off, or the account
 * may have been suspended since.
 */
function mail_bulk_block_reason(string $email, bool $essential = false): ?string
{
    $email = strtolower($email);
    if (($sup = email_suppression($email)) !== null) {
        return 'Not sent: ' . email_suppression_label((string) $sup['reason']) . '.';
    }
    $u = q_one('SELECT u.status, u.role, m.prefs FROM users u LEFT JOIN members m ON m.user_id = u.id WHERE u.email = ?', [$email]);
    if ($u === null) {
        return null;
    }
    if ($u['status'] !== 'active') {
        return 'Not sent: the account is no longer active.';
    }
    // Essential membership notices (A2) ignore the newsletter preference, nothing else.
    if (!$essential && $u['role'] === 'member' && $u['prefs'] !== null && !member_pref($u, 'newsletters')) {
        return 'Not sent: news emails were switched off after the email was queued.';
    }
    return null;
}

/** Current pause after a mail server failure: [until, seconds, error] or null. */
function mail_pause_state(?int $now = null): ?array
{
    $p = setting('mail.pause');
    if (!is_array($p) || (int) ($p['until'] ?? 0) <= 0) {
        return null;
    }
    return ['until' => (int) $p['until'], 'seconds' => (int) ($p['seconds'] ?? MAIL_PAUSE_MIN_SECONDS), 'error' => (string) ($p['error'] ?? ''), 'active' => (int) $p['until'] > ($now ?? time())];
}

/** Domain part of an address, lower case. */
function email_domain(string $email): string
{
    return strtolower(substr((string) strrchr($email, '@'), 1));
}

/** Messages per domain sent in the 60 seconds before $now (both tables). @return array<string,int> */
function mail_sent_last_minute_by_domain(int $now): array
{
    $since = gmdate('Y-m-d H:i:s', $now - 60);
    $out = [];
    foreach ([['email_queue', 'to_email'], ['campaign_recipients', 'email']] as [$table, $col]) {
        foreach (q_all('SELECT LOWER(SUBSTRING_INDEX(' . db_ident($col) . ", '@', -1)) AS d, COUNT(*) AS n FROM " . db_ident($table) . ' WHERE sent_at > ? GROUP BY d', [$since]) as $r) {
            $out[(string) $r['d']] = ($out[(string) $r['d']] ?? 0) + (int) $r['n'];
        }
    }
    return $out;
}

/**
 * Why a transactional email must not go to this address, or null: the
 * address bounced (hard, or soft three times). Unsubscribes and complaints
 * only stop bulk mail.
 */
function mail_undeliverable_reason(string $email): ?string
{
    $sup = email_suppression($email);
    return $sup !== null && in_array($sup['reason'], EMAIL_UNDELIVERABLE_REASONS, true) ? 'Not sent: ' . email_suppression_label((string) $sup['reason']) . '.' : null;
}

/**
 * Send one batch. Returns counts: sent, failed, retry, interrupted,
 * suppressed, budget, paused (1 when sending is paused after the mail
 * server could not be reached), rejected (refused for good by the relay:
 * hard bounce or block), throttled (left for later by the per-domain limit).
 * @return array<string,int>
 */
function mail_queue_run(?int $now = null, ?int $perMinute = null): array
{
    $now ??= time();
    $nowSql = gmdate('Y-m-d H:i:s', $now);
    $stats = ['sent' => 0, 'failed' => 0, 'retry' => 0, 'interrupted' => 0, 'suppressed' => 0, 'budget' => 0, 'paused' => 0, 'rejected' => 0, 'throttled' => 0];

    // Rows a crashed run left in "sending": delivery unknown, never re-sent (not even by "retry failed").
    $stuck = gmdate('Y-m-d H:i:s', $now - MAIL_STUCK_SECONDS);
    $msg = 'Interrupted while sending; not retried to avoid sending twice.';
    $stats['interrupted'] += q("UPDATE email_queue SET status = 'failed', interrupted = 1, last_error = ? WHERE status = 'sending' AND claimed_at < ?", [$msg, $stuck])->rowCount();
    $stats['interrupted'] += q("UPDATE campaign_recipients SET status = 'failed', interrupted = 1, error = ? WHERE status = 'sending' AND claimed_at < ?", [$msg, $stuck])->rowCount();

    $pause = mail_pause_state($now);
    if ($pause !== null && $pause['active']) {
        $stats['paused'] = 1;
        campaigns_finish($now);
        return $stats;
    }

    $budget = max(0, ($perMinute ?? mail_per_minute()) - mail_sent_last_minute($now));
    $stats['budget'] = $budget;
    // A5: at most N per minute to one domain (0 = no limit), so a big list never looks like abuse to one provider.
    $perDomain = bounce_settings()['per_domain_minute'];
    $domainSent = $perDomain > 0 ? mail_sent_last_minute_by_domain($now) : [];
    $throttled = static function (string $email) use (&$domainSent, $perDomain): bool {
        return $perDomain > 0 && ($domainSent[email_domain($email)] ?? 0) >= $perDomain;
    };
    $fetch = (int) min(5000, $budget * 4 + 20);
    $down = false;
    if ($budget > 0) {
        $rows = q_all("SELECT * FROM email_queue WHERE status = 'queued' AND next_attempt_at <= ? ORDER BY id LIMIT " . $fetch, [$nowSql]);
        foreach ($rows as $row) {
            if ($budget <= 0) {
                break;
            }
            if ($throttled((string) $row['to_email'])) {
                $stats['throttled']++;
                continue;
            }
            if (!mail_claim('email_queue', (int) $row['id'], $nowSql)) {
                continue;
            }
            $reason = in_array($row['kind'], MAIL_BULK_KINDS, true) ? mail_bulk_block_reason((string) $row['to_email']) : mail_undeliverable_reason((string) $row['to_email']);
            if ($reason !== null) {
                q("UPDATE email_queue SET status = 'suppressed', last_error = ? WHERE id = ?", [$reason, $row['id']]);
                $stats['suppressed']++;
                continue;
            }
            $headers = json_decode((string) ($row['headers'] ?? ''), true);
            try {
                $m = new Message((string) $row['to_email'], (string) $row['to_name'], (string) $row['subject'], (string) $row['text_body'], $row['html_body'], $row['reply_to'], is_array($headers) ? $headers : []);
                mailer()->send(mail_with_envelope($m, verp_tag('q', (int) $row['id'], (string) $row['to_email'])));
                q("UPDATE email_queue SET status = 'sent', sent_at = ?, last_error = '' WHERE id = ?", [$nowSql, $row['id']]);
                $stats['sent']++;
                $domainSent[email_domain((string) $row['to_email'])] = ($domainSent[email_domain((string) $row['to_email'])] ?? 0) + 1;
            } catch (TransportException $e) {
                mail_release('email_queue', 'last_error', $row, $e, $now);
                mail_pause($e, $now);
                $down = true;
                break;
            } catch (RecipientRejectedException $e) {
                $stats[mail_rejected('email_queue', 'last_error', $row, $e, $now)]++;
            } catch (Throwable $e) {
                $stats[mail_fail('email_queue', 'last_error', $row, $e, $now)]++;
            }
            $budget--;
        }
    }
    if ($budget > 0 && !$down) {
        $rows = q_all(
            "SELECT r.* FROM campaign_recipients r JOIN campaigns c ON c.id = r.campaign_id
              WHERE c.status = 'sending' AND r.status = 'queued' AND r.next_attempt_at <= ? ORDER BY r.campaign_id, r.id LIMIT " . $fetch,
            [$nowSql]
        );
        $campaigns = [];
        foreach ($rows as $row) {
            if ($budget <= 0) {
                break;
            }
            $cid = (int) $row['campaign_id'];
            $campaigns[$cid] ??= q_one('SELECT * FROM campaigns WHERE id = ?', [$cid]);
            if (($campaigns[$cid]['status'] ?? '') !== 'sending') {
                continue; // paused by a block earlier in this run
            }
            if ($throttled((string) $row['email'])) {
                $stats['throttled']++;
                continue;
            }
            if (!mail_claim('campaign_recipients', (int) $row['id'], $nowSql)) {
                continue;
            }
            if (($reason = mail_bulk_block_reason((string) $row['email'], (bool) $campaigns[$cid]['essential'])) !== null) {
                q("UPDATE campaign_recipients SET status = 'suppressed', error = ? WHERE id = ?", [$reason, $row['id']]);
                $stats['suppressed']++;
                continue;
            }
            try {
                mailer()->send(mail_with_envelope(campaign_message_for($campaigns[$cid], $row), verp_tag('c', (int) $row['id'], (string) $row['email']), $cid));
                q("UPDATE campaign_recipients SET status = 'sent', sent_at = ?, error = '' WHERE id = ?", [$nowSql, $row['id']]);
                $stats['sent']++;
                $domainSent[email_domain((string) $row['email'])] = ($domainSent[email_domain((string) $row['email'])] ?? 0) + 1;
            } catch (TransportException $e) {
                mail_release('campaign_recipients', 'error', $row, $e, $now);
                mail_pause($e, $now);
                $down = true;
                break;
            } catch (RecipientRejectedException $e) {
                $stats[mail_rejected('campaign_recipients', 'error', $row, $e, $now)]++;
                $campaigns[$cid]['status'] = (string) q_val('SELECT status FROM campaigns WHERE id = ?', [$cid]);
            } catch (Throwable $e) {
                $stats[mail_fail('campaign_recipients', 'error', $row, $e, $now)]++;
            }
            $budget--;
        }
    }
    if ($down) {
        $stats['paused'] = 1;
    } elseif ($stats['sent'] > 0 && $pause !== null) {
        setting_set('mail.pause', null); // the server answers again
    }
    campaigns_finish($now);
    return $stats;
}

/**
 * The relay refused the recipient or message (A5 "at SMTP time"):
 * 5xx -> hard bounce or block, failed at once and recorded (no retries);
 * 4xx -> soft: retried with back-off, recorded as a soft bounce when the
 * attempts run out. Returns the stats key.
 */
function mail_rejected(string $table, string $errorCol, array $row, RecipientRejectedException $e, int $now): string
{
    $type = bounce_classify($e->enhancedCode, $e->smtpCode, $e->detail);
    $email = (string) ($row['email'] ?? $row['to_email']);
    // Our own relay refused this row: the event belongs to it (no tag parsing needed).
    $event = ['email' => $email, 'status' => $e->enhancedCode, 'smtp_code' => $e->smtpCode, 'detail' => $e->detail, 'source' => 'smtp', 'row' => bounce_queue_row($table, (int) $row['id']), 'now' => $now];
    if (!$e->permanent()) {
        $key = mail_fail($table, $errorCol, $row, $e, $now);
        if ($key === 'failed') {
            bounce_record($event + ['type' => 'soft']);
        }
        return $key;
    }
    $type = in_array($type, ['hard', 'block'], true) ? $type : 'hard';
    q('UPDATE ' . db_ident($table) . ' SET status = \'failed\', ' . db_ident($errorCol) . ' = ? WHERE id = ?', [mb_substr(($type === 'block' ? 'Blocked: ' : 'Rejected: ') . $e->smtpCode . ' ' . $e->enhancedCode . ' ' . $e->detail, 0, 500), $row['id']]);
    bounce_record($event + ['type' => $type]);
    return 'rejected';
}

/** queued -> sending; true when this run owns the row. */
function mail_claim(string $table, int $id, string $nowSql): bool
{
    $table = in_array($table, ['email_queue', 'campaign_recipients'], true) ? $table : throw new InvalidArgumentException('table');
    return q('UPDATE ' . db_ident($table) . " SET status = 'sending', claimed_at = ?, attempts = attempts + 1 WHERE id = ? AND status = 'queued'", [$nowSql, $id])->rowCount() === 1;
}

/** Record a failure: retry later (5 min, 30 min, 2 h, 6 h), or give up after MAIL_MAX_ATTEMPTS. Returns the stats key. */
function mail_fail(string $table, string $errorCol, array $row, Throwable $e, int $now): string
{
    $attempts = (int) $row['attempts'] + 1;
    $error = mb_substr($e->getMessage(), 0, 500);
    error_log('Mail queue ' . $table . ' #' . $row['id'] . ': ' . $error);
    $permanent = $e instanceof InvalidArgumentException;
    if ($attempts >= MAIL_MAX_ATTEMPTS || $permanent) {
        q('UPDATE ' . db_ident($table) . ' SET status = \'failed\', ' . db_ident($errorCol) . ' = ? WHERE id = ?', [$error, $row['id']]);
        return 'failed';
    }
    q('UPDATE ' . db_ident($table) . ' SET status = \'queued\', ' . db_ident($errorCol) . ' = ?, next_attempt_at = ? WHERE id = ?',
        [$error, gmdate('Y-m-d H:i:s', $now + mail_retry_delay($attempts)), $row['id']]);
    return 'retry';
}

/** The server was unreachable: put the row back without using up an attempt. */
function mail_release(string $table, string $errorCol, array $row, Throwable $e, int $now): void
{
    q('UPDATE ' . db_ident($table) . ' SET status = \'queued\', attempts = GREATEST(attempts - 1, 0), ' . db_ident($errorCol) . ' = ? WHERE id = ?',
        [mb_substr($e->getMessage(), 0, 500), $row['id']]);
}

/** Pause all sending after a transport failure; the pause doubles while the server stays down. */
function mail_pause(Throwable $e, int $now): void
{
    $prev = mail_pause_state($now);
    $seconds = $prev === null ? MAIL_PAUSE_MIN_SECONDS : min(MAIL_PAUSE_MAX_SECONDS, $prev['seconds'] * 2);
    error_log('Mail server unreachable, sending paused for ' . $seconds . ' s: ' . $e->getMessage());
    setting_set('mail.pause', ['until' => $now + $seconds, 'seconds' => $seconds, 'error' => mb_substr($e->getMessage(), 0, 300), 'since' => $prev['since'] ?? $now]);
}

/**
 * "Retry failed recipients": failed rows that were never handed to the mail
 * server go back to the queue. Rows interrupted mid-send stay failed, so
 * nobody can get the campaign twice. Returns how many were re-queued.
 */
function campaign_retry_failed(int $campaignId, ?int $now = null): int
{
    $nowSql = gmdate('Y-m-d H:i:s', $now ?? time());
    return db_tx(static function () use ($campaignId, $nowSql): int {
        $c = q_one('SELECT status FROM campaigns WHERE id = ? FOR UPDATE', [$campaignId]);
        if ($c === null || !in_array($c['status'], ['sending', 'sent', 'paused'], true)) {
            return 0;
        }
        // Bounced addresses are never retried; recipients refused as spam/policy (block) may be, once fixed.
        $n = q("UPDATE campaign_recipients SET status = 'queued', attempts = 0, error = '', next_attempt_at = ? WHERE campaign_id = ? AND status = 'failed' AND interrupted = 0 AND (bounce IS NULL OR bounce = 'block')", [$nowSql, $campaignId])->rowCount();
        if ($n > 0) {
            q("UPDATE campaigns SET status = 'sending', pause_reason = '', completed_at = NULL WHERE id = ?", [$campaignId]);
        }
        return $n;
    });
}

// ---- Unsubscribe (signed token) and suppression -----------------------------------------

function b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function b64url_decode(string $s): ?string
{
    $d = base64_decode(strtr($s, '-_', '+/'), true);
    return $d === false ? null : $d;
}

function unsubscribe_token(string $email): string
{
    $email = strtolower(trim($email));
    return b64url($email) . '.' . substr(app_sign('unsubscribe|' . $email), 0, 32);
}

/** The email address a token was made for, or null when the signature is wrong. */
function unsubscribe_verify(string $token): ?string
{
    if (!preg_match('/^([A-Za-z0-9_-]{4,300})\.([a-f0-9]{32})$/', $token, $m)) {
        return null;
    }
    $email = b64url_decode($m[1]);
    if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    $email = strtolower($email);
    // Links in emails sent before the signing key was split keep working.
    $ok = hash_equals(substr(app_sign('unsubscribe|' . $email), 0, 32), $m[2])
        || hash_equals(substr(app_sign_legacy('unsubscribe|' . $email), 0, 32), $m[2]);
    return $ok ? $email : null;
}

function unsubscribe_url(string $email): string
{
    return base_url('/unsubscribe/' . unsubscribe_token($email));
}

function email_suppressed(string $email): bool
{
    return q_val('SELECT 1 FROM email_suppressions WHERE email = ?', [strtolower($email)]) !== null;
}

/** The suppression row for an address, or null. */
function email_suppression(string $email): ?array
{
    return q_one('SELECT * FROM email_suppressions WHERE email = ?', [strtolower($email)]);
}

/**
 * Suppression reasons. The person's own choices (unsubscribe, complaint) are
 * lifted only by the person switching news emails on again; bounce
 * suppressions are lifted by an admin once the address is fixed.
 */
function email_suppression_reasons(): array
{
    return [
        'unsubscribe' => 'the address unsubscribed',
        'complaint' => 'the recipient reported an email as spam',
        'hard_bounce' => 'the address bounced (it does not exist or cannot receive mail)',
        'soft_bounce' => 'the address bounced repeatedly (for example a full mailbox)',
    ];
}

function email_suppression_label(string $reason): string
{
    return email_suppression_reasons()[$reason] ?? 'the address is on the suppression list';
}

/** Reasons that mean mail cannot be delivered at all (also skips most transactional mail). */
const EMAIL_UNDELIVERABLE_REASONS = ['hard_bounce', 'soft_bounce'];

/** Footer line with the preferences link (A2: in every email, transactional too). */
function email_prefs_text(): string
{
    return 'Manage email preferences: ' . base_url('/account/preferences');
}

/** Stop newsletters/campaigns/notice copies to this address. */
function email_unsubscribe(string $email): void
{
    $email = strtolower($email);
    q('INSERT IGNORE INTO email_suppressions (email, reason) VALUES (?, ?)', [$email, 'unsubscribe']);
    $m = q_one('SELECT m.id, m.prefs FROM members m JOIN users u ON u.id = m.user_id WHERE u.email = ?', [$email]);
    if ($m !== null) {
        $prefs = member_prefs($m);
        $prefs['newsletters'] = false;
        q('UPDATE members SET prefs = ? WHERE id = ?', [json_encode($prefs, JSON_THROW_ON_ERROR), $m['id']]);
    }
}

/** The person opted in again: lifts their own unsubscribe or complaint (never a bounce). */
function email_resubscribe(string $email): void
{
    q("DELETE FROM email_suppressions WHERE email = ? AND reason IN ('unsubscribe','complaint')", [strtolower($email)]);
}

/** Headers for bulk (non-transactional) mail: one-click unsubscribe (RFC 8058). */
function bulk_headers(string $email): array
{
    return [
        'List-Unsubscribe' => '<' . unsubscribe_url($email) . '>',
        'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        'Precedence' => 'bulk',
        'Auto-Submitted' => 'auto-generated',
    ];
}

/** Delivery overview for the admin log. */
function mail_queue_counts(): array
{
    $out = ['queued' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'suppressed' => 0];
    foreach (q_all('SELECT status, COUNT(*) AS n FROM email_queue GROUP BY status') as $r) {
        $out[$r['status']] = (int) $r['n'];
    }
    return $out;
}
