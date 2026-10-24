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

    /** @return list<string> up to $limit message ids, oldest first */
    public function list(int $limit): array;

    public function fetch(string $id): string;

    /** Mark for deletion (removed on close). */
    public function delete(string $id): void;

    /** Move to the "unprocessed" folder; false when the protocol has no folders (POP3). */
    public function moveAside(string $id): bool;

    /** Keep the unprocessed folder under $maxCount messages and $maxAgeDays days; returns how many were removed. */
    public function pruneAside(int $maxCount, int $maxAgeDays): int;

    /** Apply deletions and log out. */
    public function close(): void;
}
