<?php
declare(strict_types=1);

namespace Core\Mail;

/** A mailbox message is bigger than the processing limit (it is never read in full). */
final class MessageTooLargeException extends MessageUnreadableException
{
}
