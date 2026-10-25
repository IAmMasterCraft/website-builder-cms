<?php
declare(strict_types=1);

namespace Core\Mail;

/**
 * A mailbox that receives bounces and feedback reports (ImapClient,
 * Pop3Client, or a fake in tests). Message ids are opaque strings valid for
 * one session.
 */
interface BounceMailbox
{
    /** Connect, log in and open the inbox. */
    public function open(): void;

    /**
     * Up to $limit messages, oldest first, with their size in bytes as the
     * server reports it (-1 when unknown), so oversized messages can be set
     * aside without being downloaded.
     * @return array<string,int> id => size
     */
    public function list(int $limit): array;

    /**
     * The raw message, never more than $maxBytes (MessageTooLargeException
     * when it is bigger; MessageUnreadableException when the server sends
     * nothing usable; the session stays usable in both cases).
     */
    public function fetch(string $id, int $maxBytes): string;

    /** Mark for deletion (removed on close). */
    public function delete(string $id): void;

    /** Move to the "unprocessed" folder; false when the protocol has no folders (POP3). */
    public function moveAside(string $id): bool;

    /** Keep the unprocessed folder under $maxCount messages and $maxAgeDays days; returns how many were removed. */
    public function pruneAside(int $maxCount, int $maxAgeDays): int;

    /** Apply deletions and log out. */
    public function close(): void;
}
