<?php
declare(strict_types=1);

namespace Core;

/** Thrown by redirect(); the front controller sends the Location header. */
final class Redirect extends \RuntimeException
{
    public function __construct(public readonly string $location, public readonly int $status = 303)
    {
        parent::__construct('Redirect to ' . $location);
    }
}
