<?php
declare(strict_types=1);

namespace Core\Mail;

/**
 * Transport interface. Implementations: FileMailer (development: writes .eml
 * files) and, in phase 5, an SMTP mailer wrapping the vendored PHPMailer.
 * send() throws \RuntimeException when delivery fails.
 */
interface Mailer
{
    public function send(Message $message): void;
}
