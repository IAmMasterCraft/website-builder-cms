<?php
declare(strict_types=1);

namespace Core\Mail;

/** Writes each message as an RFC 5322 .eml file (open with any mail client). */
final class FileMailer implements Mailer
{
    public function __construct(
        private readonly string $dir,
        private readonly string $fromEmail,
        private readonly string $fromName,
    ) {
    }

    public function send(Message $message): void
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new TransportException('Mail folder is not writable');
        }
        $file = sprintf('%s/%s-%s.eml', rtrim($this->dir, '/'), (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd-His-u'), bin2hex(random_bytes(4)));
        if (file_put_contents($file, self::toMime($message, $this->fromEmail, $this->fromName)) === false) {
            throw new TransportException('Could not write mail file');
        }
    }

    public static function toMime(Message $m, string $fromEmail, string $fromName): string
    {
        $h = [];
        $h[] = 'Date: ' . gmdate(DATE_RFC2822);
        $h[] = 'From: ' . self::address($fromEmail, $fromName);
        $h[] = 'To: ' . self::address($m->toEmail, $m->toName);
        if ($m->replyTo !== null) {
            $h[] = 'Reply-To: ' . $m->replyTo;
        }
        $h[] = 'Subject: ' . mb_encode_mimeheader($m->subject, 'UTF-8', 'Q');
        $h[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', $fromEmail)[1] ?? 'localhost') . '>';
        foreach ($m->headers as $name => $value) {
            if (preg_match('/^[A-Za-z-]+$/', $name) && !preg_match('/[\r\n]/', $value)) {
                $h[] = $name . ': ' . $value;
            }
        }
        $h[] = 'MIME-Version: 1.0';
        $text = quoted_printable_encode(str_replace("\n", "\r\n", str_replace("\r\n", "\n", $m->text)));
        if ($m->html === null) {
            $h[] = 'Content-Type: text/plain; charset=UTF-8';
            $h[] = 'Content-Transfer-Encoding: quoted-printable';
            return implode("\r\n", $h) . "\r\n\r\n" . $text . "\r\n";
        }
        $b = 'b' . bin2hex(random_bytes(8));
        $h[] = 'Content-Type: multipart/alternative; boundary="' . $b . '"';
        return implode("\r\n", $h) . "\r\n\r\n"
            . "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . $text . "\r\n"
            . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($m->html) . "\r\n--$b--\r\n";
    }

    private static function address(string $email, string $name): string
    {
        return $name === '' ? $email : mb_encode_mimeheader($name, 'UTF-8', 'Q') . ' <' . $email . '>';
    }
}
