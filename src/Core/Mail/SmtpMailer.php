<?php
declare(strict_types=1);

namespace Core\Mail;

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * SMTP transport wrapping the vendored PHPMailer (vendor/phpmailer, LGPL 2.1).
 * One connection is kept open across a batch (SMTPKeepAlive) and closed in
 * the destructor.
 */
final class SmtpMailer implements Mailer
{
    private ?PHPMailer $mail = null;

    /**
     * @param array{host:string, port:int, encryption:string, username:string, password:string, timeout?:int} $smtp
     *        encryption: 'tls' (STARTTLS), 'ssl' (implicit TLS) or 'none'
     */
    public function __construct(
        private readonly array $smtp,
        private readonly string $fromEmail,
        private readonly string $fromName,
    ) {
    }

    public static function load(): void
    {
        foreach (['Exception', 'PHPMailer', 'SMTP'] as $f) {
            require_once BASE . '/vendor/phpmailer/src/' . $f . '.php';
        }
    }

    private function mailer(): PHPMailer
    {
        if ($this->mail === null) {
            self::load();
            $m = new PHPMailer(true);
            $m->isSMTP();
            $m->Host = $this->smtp['host'];
            $m->Port = $this->smtp['port'];
            $m->Timeout = $this->smtp['timeout'] ?? 20;
            $m->SMTPKeepAlive = true;
            $m->SMTPAutoTLS = $this->smtp['encryption'] !== 'none';
            $m->SMTPSecure = match ($this->smtp['encryption']) {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'tls' => PHPMailer::ENCRYPTION_STARTTLS,
                default => '',
            };
            if ($this->smtp['username'] !== '') {
                $m->SMTPAuth = true;
                $m->Username = $this->smtp['username'];
                $m->Password = $this->smtp['password'];
            }
            $m->CharSet = PHPMailer::CHARSET_UTF8;
            $m->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
            $this->mail = $m;
        }
        return $this->mail;
    }

    public function send(Message $message): void
    {
        $m = $this->mailer();
        try {
            $m->clearAllRecipients();
            $m->clearReplyTos();
            $m->clearCustomHeaders();
            $m->setFrom($this->fromEmail, $this->fromName, false);
            $m->addAddress($message->toEmail, $message->toName);
            if ($message->replyTo !== null && filter_var($message->replyTo, FILTER_VALIDATE_EMAIL)) {
                $m->addReplyTo($message->replyTo);
            }
            foreach ($message->headers as $name => $value) {
                if (preg_match('/^[A-Za-z-]+$/', $name) && !preg_match('/[\r\n]/', $value)) {
                    $m->addCustomHeader($name, $value);
                }
            }
            $m->Subject = $message->subject;
            if ($message->html !== null) {
                $m->isHTML(true);
                $m->Body = $message->html;
                $m->AltBody = $message->text;
            } else {
                $m->isHTML(false);
                $m->Body = $message->text;
                $m->AltBody = '';
            }
            $m->send();
        } catch (MailerException $e) {
            // Drop the connection so the next message starts clean.
            $m->smtpClose();
            throw new \RuntimeException('SMTP: ' . $e->getMessage(), 0, $e);
        }
    }

    public function __destruct()
    {
        $this->mail?->smtpClose();
    }
}
