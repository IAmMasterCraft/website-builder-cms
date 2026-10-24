<?php
declare(strict_types=1);

namespace Core\Mail;

/**
 * Our relay refused this recipient or this message (RCPT TO or DATA answered
 * with a 4xx/5xx code). Unlike a TransportException the server is fine; the
 * code says whether trying again can help (4xx) or not (5xx).
 */
final class RecipientRejectedException extends \RuntimeException
{
    public function __construct(
        public readonly int $smtpCode,
        public readonly string $enhancedCode,
        public readonly string $detail,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : trim($smtpCode . ' ' . $enhancedCode . ' ' . $detail), 0, $previous);
    }

    public function permanent(): bool
    {
        return $this->smtpCode >= 500;
    }
}
