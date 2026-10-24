<?php
declare(strict_types=1);

namespace Core\Mail;

/** The bounce mailbox could not be read (connection, TLS, login or protocol error). */
final class MailboxException extends \RuntimeException
{
}
