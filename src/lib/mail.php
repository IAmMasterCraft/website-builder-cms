<?php
declare(strict_types=1);

use Core\Mail\FileMailer;
use Core\Mail\Mailer;
use Core\Mail\Message;

/** The configured transport. Pass one in to replace it (tests). */
function mailer(?Mailer $replace = null): Mailer
{
    static $mailer = null;
    if ($replace !== null) {
        $mailer = $replace;
    }
    if ($mailer === null) {
        $driver = (string) config('mail.driver', 'file');
        $mailer = match ($driver) {
            // 'smtp' => new SmtpMailer(...)   phase 5: wraps vendored PHPMailer
            default => new FileMailer(BASE . '/storage/mail', (string) config('mail.from_email', 'no-reply@localhost'), (string) config('mail.from_name', (string) setting('site.name'))),
        };
    }
    return $mailer;
}

/**
 * Send a transactional email rendered from templates/email/{name}.php.
 * The template returns ['subject' => ..., 'text' => ..., 'html' => ...].
 * Failures are logged, never shown to the visitor.
 */
function send_template_mail(string $toEmail, string $toName, string $template, array $vars = []): bool
{
    if (!preg_match('/^[a-z0-9_]+$/', $template)) {
        throw new InvalidArgumentException('Bad mail template');
    }
    $vars += ['site_name' => (string) setting('site.name'), 'base_url' => base_url()];
    $parts = (static function (string $__file, array $vars): array {
        return (array) require $__file;
    })(BASE . '/templates/email/' . $template . '.php', $vars);
    $html = isset($parts['html']) ? view('email/_layout', ['body' => $parts['html'], 'site_name' => $vars['site_name']]) : null;
    try {
        mailer()->send(new Message($toEmail, $toName, (string) $parts['subject'], (string) $parts['text'], $html));
        return true;
    } catch (Throwable $e) {
        error_log('Mail failed (' . $template . '): ' . $e->getMessage());
        return false;
    }
}
