<?php
declare(strict_types=1);

use Core\Mail\FileMailer;
use Core\Mail\Mailer;
use Core\Mail\Message;
use Core\Mail\RecipientRejectedException;
use Core\Mail\SmtpMailer;

/*
 * Mail transport. The driver comes from the super-admin email settings
 * (mail.driver = file|smtp); config.php's mail.driver is the default, and
 * 'file' (writes storage/mail/*.eml) is used for development and tests.
 */

/** SMTP settings with defaults (password decrypted). */
function mail_smtp_settings(): array
{
    secret_upgrade_smtp_password();
    $s = setting('mail.smtp', []);
    $s = is_array($s) ? $s : [];
    return [
        'host' => (string) ($s['host'] ?? ''),
        'port' => (int) ($s['port'] ?? 587),
        'encryption' => in_array($s['encryption'] ?? 'tls', ['tls', 'ssl', 'none'], true) ? (string) ($s['encryption'] ?? 'tls') : 'tls',
        'username' => (string) ($s['username'] ?? ''),
        'password' => secret_open((string) ($s['password'] ?? '')),
        'has_password' => (string) ($s['password'] ?? '') !== '',
    ];
}

function mail_driver(): string
{
    $d = setting('mail.driver');
    $d = is_string($d) && $d !== '' ? $d : (string) config('mail.driver', 'file');
    return in_array($d, ['file', 'smtp'], true) ? $d : 'file';
}

function mail_from(): array
{
    $email = (string) setting('mail.from_email', '');
    $name = (string) setting('mail.from_name', '');
    return [
        $email !== '' ? $email : (string) config('mail.from_email', 'no-reply@localhost'),
        $name !== '' ? $name : (string) config('mail.from_name', (string) setting('site.name')),
    ];
}

/** Build a transport from the current settings. */
function mailer_from_settings(?string $driver = null): Mailer
{
    [$fromEmail, $fromName] = mail_from();
    if (($driver ?? mail_driver()) === 'smtp') {
        $s = mail_smtp_settings();
        if ($s['host'] === '') {
            throw new RuntimeException('SMTP is selected but no SMTP server is configured.');
        }
        return new SmtpMailer($s, $fromEmail, $fromName);
    }
    return new FileMailer(BASE . '/storage/mail', $fromEmail, $fromName);
}

/** The configured transport. Pass one in to replace it (tests); pass a reset flag to rebuild after settings change. */
function mailer(?Mailer $replace = null, bool $reset = false): Mailer
{
    static $mailer = null;
    if ($reset) {
        $mailer = null;
    }
    if ($replace !== null) {
        $mailer = $replace;
    }
    return $mailer ??= mailer_from_settings();
}

// ---- Secrets stored in settings (SMTP password) ------------------------------------

/** Encryption key for stored secrets (HKDF from app_key, separate from the signing key). */
function secret_key(): string
{
    return app_subkey('secret-storage');
}

/** Key used before the keys were split ("sb1:" values); read-only, for upgrading old values. */
function secret_key_legacy(): string
{
    return hash('sha256', 'gather-secret|' . (string) config('app_key', '') . '|' . (string) config('db.name', ''), true);
}

/** Encrypt a value for storage in the settings table (libsodium secretbox). */
function secret_seal(string $plain): string
{
    if ($plain === '') {
        return '';
    }
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'sb2:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, secret_key()));
}

function secret_open(string $sealed): string
{
    $version = substr($sealed, 0, 4);
    if ($version !== 'sb1:' && $version !== 'sb2:') {
        return '';
    }
    $raw = base64_decode(substr($sealed, 4), true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return '';
    }
    $key = $version === 'sb2:' ? secret_key() : secret_key_legacy();
    $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
    return $plain === false ? '' : $plain;
}

/**
 * Re-encrypt a stored SMTP password sealed with the old key ("sb1:") under
 * the new storage key. Run by bin/migrate and on reading the settings, so
 * an upgraded site keeps sending without re-entering the password.
 * Returns true when a value was upgraded.
 */
function secret_upgrade_smtp_password(): bool
{
    $s = setting('mail.smtp', []);
    if (!is_array($s) || !str_starts_with((string) ($s['password'] ?? ''), 'sb1:')) {
        return false;
    }
    $plain = secret_open((string) $s['password']);
    if ($plain === '') {
        return false;
    }
    $s['password'] = secret_seal($plain);
    setting_set('mail.smtp', $s);
    return true;
}

// ---- Sending ----------------------------------------------------------------------------

/**
 * Send one message now (transactional mail, inbox replies). A5: each one is
 * logged as an email_queue row first (subject only, no body kept once it is
 * sent) so it gets a VERP tag and later bounces map to it. A permanent
 * refusal by the relay is recorded as a bounce and never retried; other
 * failures put the full message in the queue for cron to retry, unless
 * $queueOnFailure is false. 'test' sends are not logged. Returns true when
 * delivered now.
 */
function mail_send_now(Message $m, string $kind = 'transactional', bool $queueOnFailure = true): bool
{
    if ($kind === 'test') {
        try {
            mailer()->send($m->withEnvelope(verp_return_path(null), []));
            return true;
        } catch (Throwable $e) {
            error_log('Mail failed (test): ' . $e->getMessage());
            return false;
        }
    }
    $nowSql = gmdate('Y-m-d H:i:s');
    $id = db_insert('email_queue', [
        'kind' => mb_substr($kind, 0, 20), 'to_email' => $m->toEmail, 'to_name' => mb_substr($m->toName, 0, 120), 'subject' => mb_substr($m->subject, 0, 250),
        'text_body' => '', 'html_body' => null, 'reply_to' => $m->replyTo, 'status' => 'sending', 'attempts' => 1, 'claimed_at' => $nowSql, 'next_attempt_at' => $nowSql,
    ]);
    try {
        mailer()->send(mail_with_envelope($m, verp_tag('q', $id, $m->toEmail)));
        q("UPDATE email_queue SET status = 'sent', sent_at = ? WHERE id = ?", [gmdate('Y-m-d H:i:s'), $id]);
        return true;
    } catch (RecipientRejectedException $e) {
        error_log('Mail refused (' . $kind . '): ' . $e->getMessage());
        if (!$e->permanent() && $queueOnFailure) {
            mail_requeue_direct($id, $m, $e);
        } else {
            $row = q_one('SELECT * FROM email_queue WHERE id = ?', [$id]);
            mail_rejected('email_queue', 'last_error', ['attempts' => MAIL_MAX_ATTEMPTS] + $row, $e, time());
        }
        return false;
    } catch (Throwable $e) {
        error_log('Mail failed (' . $kind . '): ' . $e->getMessage());
        if ($queueOnFailure) {
            mail_requeue_direct($id, $m, $e);
        } else {
            q("UPDATE email_queue SET status = 'failed', last_error = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 500), $id]);
        }
        return false;
    }
}

/** A direct send failed for a temporary reason: keep the full message for cron to retry (first retry after 5 minutes). */
function mail_requeue_direct(int $id, Message $m, Throwable $e): void
{
    q("UPDATE email_queue SET status = 'queued', text_body = ?, html_body = ?, headers = ?, last_error = ?, next_attempt_at = ? WHERE id = ?", [
        $m->text, $m->html, $m->headers ? json_encode($m->headers, JSON_THROW_ON_ERROR) : null, mb_substr($e->getMessage(), 0, 500),
        gmdate('Y-m-d H:i:s', time() + mail_retry_delay(1)), $id,
    ]);
}

/**
 * Templates still sent to an address that bounced: the person asked for
 * them just now on the site (a working address proves itself by the link
 * being used), or must reach them whatever happened before (the notice that
 * an admin viewed their account, B2). Automated mail (welcome, birthday,
 * replies, job news…) is skipped for bounced addresses.
 */
const MAIL_BOUNCE_BYPASS_TEMPLATES = ['verify_email', 'password_reset', 'email_change', 'admin_invite', 'signup_existing', 'event_registration', 'contact_copy', 'contact_reply', 'impersonation_notice'];

/**
 * Send a transactional email built from an editable template
 * (src/lib/email_templates.php). Failures are logged and queued for retry,
 * never shown to the visitor.
 */
function send_template_mail(string $toEmail, string $toName, string $template, array $vars = []): bool
{
    if (!in_array($template, MAIL_BOUNCE_BYPASS_TEMPLATES, true) && ($reason = mail_undeliverable_reason($toEmail)) !== null) {
        error_log('Mail not sent (' . $template . '): ' . $reason);
        return false;
    }
    $parts = email_template_render($template, $vars + ['first_name' => $toName]);
    try {
        $message = new Message($toEmail, $toName, $parts['subject'], $parts['text'], $parts['html'], $parts['reply_to'] ?? null);
    } catch (InvalidArgumentException $e) {
        error_log('Mail not sent (' . $template . '): ' . $e->getMessage());
        return false;
    }
    return mail_send_now($message, 'transactional');
}
