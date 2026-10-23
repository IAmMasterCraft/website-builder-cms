<?php
declare(strict_types=1);

namespace Core\Mail;

/** One outgoing email. Plain text is required; HTML is optional. */
final class Message
{
    /** @param array<string,string> $headers extra headers, e.g. List-Unsubscribe */
    public function __construct(
        public readonly string $toEmail,
        public readonly string $toName,
        public readonly string $subject,
        public readonly string $text,
        public readonly ?string $html = null,
        public readonly ?string $replyTo = null,
        public readonly array $headers = [],
    ) {
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid recipient address');
        }
        if (preg_match('/[\r\n]/', $subject . $toName . (string) $replyTo)) {
            throw new \InvalidArgumentException('Header injection attempt');
        }
    }
}
