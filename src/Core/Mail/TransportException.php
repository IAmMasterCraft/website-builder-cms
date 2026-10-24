<?php
declare(strict_types=1);

namespace Core\Mail;

/**
 * The mail server could not be reached or refused our login: nothing is
 * wrong with the message itself. The queue pauses instead of using up the
 * message's attempts.
 */
final class TransportException extends \RuntimeException
{
}
