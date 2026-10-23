<?php
declare(strict_types=1);

namespace Core;

/** Thrown to stop a request with an HTTP error; the front controller renders it. */
final class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message !== '' ? $message : self::reason($status), $status);
    }

    public static function reason(int $status): string
    {
        return match ($status) {
            400 => 'Bad request',
            403 => 'You do not have access to this page',
            404 => 'Page not found',
            405 => 'Method not allowed',
            419 => 'Your session expired. Go back, reload the page and try again.',
            422 => 'Please check the form',
            429 => 'Too many attempts. Please wait a few minutes and try again.',
            default => 'Something went wrong',
        };
    }
}
