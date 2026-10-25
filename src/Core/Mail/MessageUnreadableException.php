<?php
declare(strict_types=1);

namespace Core\Mail;

/**
 * One mailbox message cannot be read (the server sent nothing usable, or it
 * is bigger than the limit). The session is still usable: the caller moves
 * the message aside instead of retrying it on every run.
 */
class MessageUnreadableException extends MailboxException
{
}
