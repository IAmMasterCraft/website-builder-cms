<?php
declare(strict_types=1);

namespace Core\Mail;

/** One outgoing email. Plain text is required; HTML is optional. */
final class Message
{
    /**
     * @param array<string,string> $headers extra headers, e.g. List-Unsubscribe (a "Message-ID" entry sets the message id)
     * @param ?string $returnPath envelope sender (Return-Path), e.g. a VERP bounce address
     */
    public function __construct(
        public readonly string $toEmail,
        public readonly string $toName,
        public readonly string $subject,
        public readonly string $text,
        public readonly ?string $html = null,
        public readonly ?string $replyTo = null,
        public readonly array $headers = [],
        public readonly ?string $returnPath = null,
    ) {
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid recipient address');
        }
        if (preg_match('/[\r\n]/', $subject . $toName . (string) $replyTo)) {
            throw new \InvalidArgumentException('Header injection attempt');
        }
        if ($returnPath !== null && !filter_var($returnPath, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid return path');
        }
    }

    /** A copy with a different envelope sender and extra headers (VERP and tracking headers). */
    public function withEnvelope(?string $returnPath, array $headers): self
    {
        return new self($this->toEmail, $this->toName, $this->subject, $this->text, $this->html, $this->replyTo, $headers + $this->headers, $returnPath);
    }
}
