<?php
declare(strict_types=1);

use Core\Mail\FileMailer;
use Core\Mail\Mailer;
use Core\Mail\Message;
use Core\Mail\SmtpMailer;

/*
 * Mail transport. The driver comes from the super-admin email settings
 * (mail.driver = file|smtp); config.php's mail.driver is the default, and
 * 'file' (writes storage/mail/*.eml) is used for development and tests.
 */

/** SMTP settings with defaults (password decrypted). */
function mail_smtp_settings(): array
{
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

function secret_key(): string
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
    return 'sb1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, secret_key()));
}

function secret_open(string $sealed): string
{
    if (!str_starts_with($sealed, 'sb1:')) {
        return '';
    }
    $raw = base64_decode(substr($sealed, 4), true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return '';
    }
    $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), secret_key());
    return $plain === false ? '' : $plain;
}

// ---- Sending ----------------------------------------------------------------------------

/**
 * Send one message now. On failure it goes to the email queue (retried by
 * cron) unless $queueOnFailure is false. Returns true when delivered now.
 */
function mail_send_now(Message $m, string $kind = 'transactional', bool $queueOnFailure = true): bool
{
    try {
        mailer()->send($m);
        return true;
    } catch (Throwable $e) {
        error_log('Mail failed (' . $kind . '): ' . $e->getMessage());
        if ($queueOnFailure) {
            mail_queue_message($m, $kind, null, 1, mb_substr($e->getMessage(), 0, 500));
        }
        return false;
    }
}

/**
 * Send a transactional email built from an editable template
 * (src/lib/email_templates.php). Failures are logged and queued for retry,
 * never shown to the visitor.
 */
function send_template_mail(string $toEmail, string $toName, string $template, array $vars = []): bool
{
    $parts = email_template_render($template, $vars + ['first_name' => $toName]);
    try {
        $message = new Message($toEmail, $toName, $parts['subject'], $parts['text'], $parts['html'], $parts['reply_to'] ?? null);
    } catch (InvalidArgumentException $e) {
        error_log('Mail not sent (' . $template . '): ' . $e->getMessage());
        return false;
    }
    return mail_send_now($message, 'transactional');
}
