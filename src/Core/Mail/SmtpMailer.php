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
            // Envelope sender: the VERP bounce address when one is set, otherwise the From address.
            $m->Sender = $message->returnPath ?? '';
            $m->MessageID = '';
            $m->addAddress($message->toEmail, $message->toName);
            if ($message->replyTo !== null && filter_var($message->replyTo, FILTER_VALIDATE_EMAIL)) {
                $m->addReplyTo($message->replyTo);
            }
            foreach ($message->headers as $name => $value) {
                if ($name === 'Message-ID') {
                    $m->MessageID = $value;
                } elseif (preg_match('/^[A-Za-z-]+$/', $name) && !preg_match('/[\r\n]/', $value)) {
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
            // The server's reply code for the failed command (RCPT TO / DATA), read before closing.
            $err = $m->getSMTPInstance()->getError();
            // Drop the connection so the next message starts clean.
            $m->smtpClose();
            $rejection = self::rejection($e->getMessage(), (string) ($err['smtp_code'] ?? ''), (string) ($err['smtp_code_ex'] ?? ''), (string) ($err['detail'] ?? ''));
            if ($rejection !== null) {
                throw new RecipientRejectedException($rejection[0], $rejection[1], $rejection[2], 'SMTP: ' . $e->getMessage(), $e);
            }
            if (self::isTransportProblem($e->getMessage())) {
                throw new TransportException('SMTP: ' . $e->getMessage(), 0, $e);
            }
            throw new \RuntimeException('SMTP: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * A recipient or message refused by the relay: [code, enhanced code, detail]
     * or null. Only for "recipients failed" / "data not accepted"; a 421
     * (service closing) is a server problem, not a rejection.
     * @return array{0:int,1:string,2:string}|null
     */
    public static function rejection(string $error, string $code, string $enhanced, string $detail): ?array
    {
        if (!preg_match('/recipients? failed|data not accepted/i', $error)) {
            return null;
        }
        $n = (int) $code;
        if ($n === 0 && preg_match('/\b([45]\d\d)[ -]/', $error . ' ' . $detail, $mm)) {
            $n = (int) $mm[1];
        }
        if ($n < 400 || $n > 599 || $n === 421) {
            return null;
        }
        if ($enhanced === '' && preg_match('/\b([45]\.\d{1,3}\.\d{1,3})\b/', $detail . ' ' . $error, $mm)) {
            $enhanced = $mm[1];
        }
        return [$n, $enhanced, trim(mb_substr($detail !== '' ? $detail : $error, 0, 400))];
    }

    /**
     * Connection, TLS and login failures (the server is down or the settings
     * are wrong) as opposed to a problem with one message or recipient.
     */
    public static function isTransportProblem(string $error): bool
    {
        if (preg_match('/recipients? failed|data not accepted|invalid address/i', $error)) {
            return false;
        }
        return (bool) preg_match('/connect|authenticat|timed? ?out|STARTTLS|TLS|SSL|connection (refused|reset|closed)|network|getaddrinfo|could not resolve|421 /i', $error);
    }

    public function __destruct()
    {
        $this->mail?->smtpClose();
    }
}
