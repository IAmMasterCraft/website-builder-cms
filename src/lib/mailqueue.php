<?php
declare(strict_types=1);

use Core\Mail\Message;

/*
 * Email queue (§5): campaign recipients and queued messages are sent by
 * cron in batches limited per minute. A row is claimed (queued -> sending)
 * before it is sent, so two runs never send it twice; a row left in
 * "sending" by a crashed run is marked failed rather than re-sent, so a
 * recipient never gets the same campaign twice (§12). Queued rows simply
 * resume on the next run.
 */

const MAIL_MAX_ATTEMPTS = 3;
const MAIL_RETRY_SECONDS = 300;
const MAIL_STUCK_SECONDS = 600;

function mail_per_minute(): int
{
    return max(1, min(10000, (int) setting('mail.per_minute', 60)));
}

/** Put a ready message in the queue. Returns false when $dedupe was already queued. */
function mail_queue_message(Message $m, string $kind = 'transactional', ?string $dedupe = null, int $attempts = 0, string $error = '', ?int $now = null): bool
{
    $at = gmdate('Y-m-d H:i:s', ($now ?? time()) + ($attempts > 0 ? MAIL_RETRY_SECONDS : 0));
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
 * Send one batch. Returns counts: sent, failed, retry, interrupted, budget.
 * @return array<string,int>
 */
function mail_queue_run(?int $now = null, ?int $perMinute = null): array
{
    $now ??= time();
    $nowSql = gmdate('Y-m-d H:i:s', $now);
    $stats = ['sent' => 0, 'failed' => 0, 'retry' => 0, 'interrupted' => 0, 'budget' => 0];

    // Rows a crashed run left in "sending": delivery unknown, never re-sent.
    $stuck = gmdate('Y-m-d H:i:s', $now - MAIL_STUCK_SECONDS);
    $msg = 'Interrupted while sending; not retried to avoid sending twice.';
    $stats['interrupted'] += q("UPDATE email_queue SET status = 'failed', last_error = ? WHERE status = 'sending' AND claimed_at < ?", [$msg, $stuck])->rowCount();
    $stats['interrupted'] += q("UPDATE campaign_recipients SET status = 'failed', error = ? WHERE status = 'sending' AND claimed_at < ?", [$msg, $stuck])->rowCount();

    $budget = max(0, ($perMinute ?? mail_per_minute()) - mail_sent_last_minute($now));
    $stats['budget'] = $budget;
    if ($budget > 0) {
        $rows = q_all("SELECT * FROM email_queue WHERE status = 'queued' AND next_attempt_at <= ? ORDER BY id LIMIT " . (int) $budget, [$nowSql]);
        foreach ($rows as $row) {
            if (!mail_claim('email_queue', (int) $row['id'], $nowSql)) {
                continue;
            }
            $headers = json_decode((string) ($row['headers'] ?? ''), true);
            try {
                $m = new Message((string) $row['to_email'], (string) $row['to_name'], (string) $row['subject'], (string) $row['text_body'], $row['html_body'], $row['reply_to'], is_array($headers) ? $headers : []);
                mailer()->send($m);
                q("UPDATE email_queue SET status = 'sent', sent_at = ?, last_error = '' WHERE id = ?", [$nowSql, $row['id']]);
                $stats['sent']++;
            } catch (Throwable $e) {
                $stats[mail_fail('email_queue', 'last_error', $row, $e, $now)]++;
            }
            $budget--;
        }
    }
    if ($budget > 0) {
        $rows = q_all(
            "SELECT r.* FROM campaign_recipients r JOIN campaigns c ON c.id = r.campaign_id
              WHERE c.status = 'sending' AND r.status = 'queued' AND r.next_attempt_at <= ? ORDER BY r.campaign_id, r.id LIMIT " . (int) $budget,
            [$nowSql]
        );
        $campaigns = [];
        foreach ($rows as $row) {
            if (!mail_claim('campaign_recipients', (int) $row['id'], $nowSql)) {
                continue;
            }
            $cid = (int) $row['campaign_id'];
            $campaigns[$cid] ??= q_one('SELECT * FROM campaigns WHERE id = ?', [$cid]);
            try {
                mailer()->send(campaign_message_for($campaigns[$cid], $row));
                q("UPDATE campaign_recipients SET status = 'sent', sent_at = ?, error = '' WHERE id = ?", [$nowSql, $row['id']]);
                $stats['sent']++;
            } catch (Throwable $e) {
                $stats[mail_fail('campaign_recipients', 'error', $row, $e, $now)]++;
            }
        }
    }
    campaigns_finish($now);
    return $stats;
}

/** queued -> sending; true when this run owns the row. */
function mail_claim(string $table, int $id, string $nowSql): bool
{
    $table = in_array($table, ['email_queue', 'campaign_recipients'], true) ? $table : throw new InvalidArgumentException('table');
    return q('UPDATE ' . db_ident($table) . " SET status = 'sending', claimed_at = ?, attempts = attempts + 1 WHERE id = ? AND status = 'queued'", [$nowSql, $id])->rowCount() === 1;
}

/** Record a failure: retry later, or give up after MAIL_MAX_ATTEMPTS. Returns the stats key. */
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
        [$error, gmdate('Y-m-d H:i:s', $now + MAIL_RETRY_SECONDS * $attempts), $row['id']]);
    return 'retry';
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

function email_resubscribe(string $email): void
{
    q('DELETE FROM email_suppressions WHERE email = ?', [strtolower($email)]);
}

/** Headers for bulk (non-transactional) mail: one-click unsubscribe (RFC 8058). */
function bulk_headers(string $email): array
{
    return [
        'List-Unsubscribe' => '<' . unsubscribe_url($email) . '>',
        'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        'Precedence' => 'bulk',
    ];
}

/** Delivery overview for the admin log. */
function mail_queue_counts(): array
{
    $out = ['queued' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0];
    foreach (q_all('SELECT status, COUNT(*) AS n FROM email_queue GROUP BY status') as $r) {
        $out[$r['status']] = (int) $r['n'];
    }
    return $out;
}
