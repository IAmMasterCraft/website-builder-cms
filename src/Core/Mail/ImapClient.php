<?php
declare(strict_types=1);

namespace Core\Mail;

/**
 * Minimal IMAP4rev1 client (RFC 3501) for the bounce mailbox: LOGIN,
 * SELECT, UID SEARCH, UID FETCH RFC822.SIZE, UID FETCH BODY.PEEK[]<0.n>
 * (a partial fetch, so a message is never downloaded beyond the limit),
 * UID STORE \Deleted, UID COPY to an "unprocessed" folder, EXPUNGE. Handles
 * literals in responses (capped); ASCII credentials are sent as quoted
 * strings, others as literals.
 */
final class ImapClient extends MailboxSocket
{
    /** Literals other than message bodies (headers, search results) are small. */
    private const MAX_LITERAL = 65536;
    /** Lines of one response (without literals), in bytes. */
    private const MAX_RESPONSE_BYTES = 8 * 1024 * 1024;
    private int $tag = 0;
    private bool $asideReady = false;
    private int $deleted = 0;

    public function open(): void
    {
        $this->connect();
        $greeting = $this->readLine();
        if (!str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) {
            throw new MailboxException('Unexpected IMAP greeting: ' . mb_substr($greeting, 0, 120));
        }
        if ($this->cfg['encryption'] === 'starttls') {
            [$untagged] = $this->command('CAPABILITY');
            if (!preg_match('/\bSTARTTLS\b/i', implode(' ', $untagged))) {
                throw new MailboxException('The IMAP server does not offer STARTTLS. Use SSL/TLS (port 993).');
            }
            $this->command('STARTTLS');
            $this->startTls();
        }
        [, $status] = $this->command('LOGIN ' . $this->astring($this->cfg['username']) . ' ' . $this->astring($this->cfg['password']), false);
        if (!str_starts_with($status, 'OK')) {
            throw new MailboxException('The IMAP login was refused. Check the user name and password.');
        }
        $this->select((string) ($this->cfg['folder'] ?? 'INBOX'));
    }

    private function select(string $folder): void
    {
        $this->command('SELECT ' . $this->astring($folder));
    }

    public function list(int $limit): array
    {
        $uids = $this->searchUids();
        $uids = array_slice($uids, 0, max(0, $limit));
        if ($uids === []) {
            return [];
        }
        $sizes = array_fill_keys(array_map('strval', $uids), -1);
        [$untagged] = $this->command('UID FETCH ' . implode(',', $uids) . ' (RFC822.SIZE)');
        foreach ($untagged as $line) {
            if (preg_match('/^\* \d+ FETCH \((.*)\)$/i', $line, $m) && preg_match('/\bUID (\d+)/i', $m[1], $u) && preg_match('/\bRFC822\.SIZE (\d+)/i', $m[1], $sz)
                && array_key_exists($u[1], $sizes)) {
                $sizes[$u[1]] = (int) $sz[1];
            }
        }
        return $sizes;
    }

    /** @return list<int> every UID in the selected folder, ascending */
    private function searchUids(): array
    {
        [$untagged] = $this->command('UID SEARCH ALL');
        $uids = array_map('intval', $this->searchResult($untagged));
        sort($uids);
        return $uids;
    }

    public function fetch(string $id, int $maxBytes): string
    {
        $this->uid($id);
        $maxBytes = max(1, $maxBytes);
        // Partial fetch: the server sends at most $maxBytes + 1 bytes, so "more than $maxBytes" means too large.
        [, , $literals] = $this->command('UID FETCH ' . $id . ' (BODY.PEEK[]<0.' . ($maxBytes + 1) . '>)', true, $maxBytes + 1);
        if ($literals === []) {
            throw new MessageUnreadableException('Message ' . $id . ' could not be read.');
        }
        if (strlen($literals[0]) > $maxBytes) {
            throw new MessageTooLargeException('Message ' . $id . ' is larger than ' . $maxBytes . ' bytes.');
        }
        return $literals[0];
    }

    public function delete(string $id): void
    {
        $this->uid($id);
        $this->command('UID STORE ' . $id . ' +FLAGS.SILENT (\\Deleted)');
        $this->deleted++;
    }

    public function moveAside(string $id): bool
    {
        $this->uid($id);
        $folder = (string) ($this->cfg['aside_folder'] ?? 'Gather-Unprocessed');
        if (!$this->asideReady) {
            $this->command('CREATE ' . $this->astring($folder), false); // NO when it already exists: fine
            $this->asideReady = true;
        }
        [, $status] = $this->command('UID COPY ' . $id . ' ' . $this->astring($folder), false);
        if (!str_starts_with($status, 'OK')) {
            return false;
        }
        $this->delete($id);
        return true;
    }

    public function pruneAside(int $maxCount, int $maxAgeDays): int
    {
        $folder = (string) ($this->cfg['aside_folder'] ?? 'Gather-Unprocessed');
        if ($this->deleted > 0) {
            $this->command('EXPUNGE');
            $this->deleted = 0;
        }
        [, $status] = $this->command('SELECT ' . $this->astring($folder), false);
        if (!str_starts_with($status, 'OK')) {
            $this->select((string) ($this->cfg['folder'] ?? 'INBOX'));
            return 0; // no unprocessed folder yet
        }
        $remove = [];
        if ($maxAgeDays > 0) {
            $before = gmdate('j-M-Y', time() - $maxAgeDays * 86400);
            [$untagged] = $this->command('UID SEARCH BEFORE ' . $before);
            $remove = $this->searchResult($untagged);
        }
        $all = array_map('strval', $this->searchUids());
        $keep = array_values(array_diff($all, $remove));
        if ($maxCount > 0 && count($keep) > $maxCount) {
            $remove = array_merge($remove, array_slice($keep, 0, count($keep) - $maxCount));
        }
        foreach (array_unique($remove) as $uid) {
            $this->command('UID STORE ' . $uid . ' +FLAGS.SILENT (\\Deleted)');
        }
        if ($remove !== []) {
            $this->command('EXPUNGE');
        }
        $this->select((string) ($this->cfg['folder'] ?? 'INBOX'));
        return count(array_unique($remove));
    }

    public function close(): void
    {
        if ($this->stream === null) {
            return;
        }
        try {
            if ($this->deleted > 0) {
                $this->command('EXPUNGE');
            }
            $this->command('LOGOUT', false);
        } finally {
            $this->disconnect();
        }
    }

    /** @param list<string> $untagged @return list<string> */
    private function searchResult(array $untagged): array
    {
        $out = [];
        foreach ($untagged as $line) {
            if (preg_match('/^\* SEARCH\b(.*)$/i', $line, $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $u) {
                    if (ctype_digit($u)) {
                        $out[] = $u;
                    }
                }
            }
        }
        return $out;
    }

    private function uid(string $id): void
    {
        if (!ctype_digit($id)) {
            throw new MailboxException('Invalid message id.');
        }
    }

    /** Quoted string for ASCII values; literal syntax otherwise. */
    private function astring(string $v): string
    {
        if (preg_match('/[\r\n\x00]/', $v)) {
            throw new MailboxException('Line breaks are not allowed in IMAP credentials or folder names.');
        }
        if (preg_match('/^[\x20-\x7e]*$/', $v)) {
            return '"' . addcslashes($v, '"\\') . '"';
        }
        return "\0L" . base64_encode($v) . "\0"; // sent as a {n} literal by command()
    }

    /**
     * Send a command; returns [untagged lines, tagged status text, literals].
     * With $strict a NO/BAD answer throws. A literal larger than
     * $maxLiteral ends the session (it cannot be skipped safely).
     * @return array{0:list<string>,1:string,2:list<string>}
     */
    private function command(string $cmd, bool $strict = true, int $maxLiteral = self::MAX_LITERAL): array
    {
        $tag = 'g' . (++$this->tag);
        // Literal arguments: send "{n}", wait for "+", then the bytes.
        $parts = preg_split('/\0L([A-Za-z0-9+\/=]*)\0/', $cmd, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$cmd];
        $line = $tag . ' ' . array_shift($parts);
        while ($parts !== []) {
            $value = (string) base64_decode((string) array_shift($parts), true);
            $rest = (string) array_shift($parts);
            $this->write($line . '{' . strlen($value) . '}');
            $cont = $this->readLine();
            if (!str_starts_with($cont, '+')) {
                throw new MailboxException('The IMAP server refused a literal.');
            }
            $line = $value . $rest;
        }
        $this->write($line);
        $untagged = [];
        $literals = [];
        $bytes = 0;
        $literalBytes = 0;
        while (true) {
            $resp = $this->readLine();
            $bytes += strlen($resp);
            if ($bytes > self::MAX_RESPONSE_BYTES) {
                $this->disconnect();
                throw new MailboxException('The IMAP server sent a response that is too long.');
            }
            // A line ending in {n} is followed by n bytes of data, then the rest of the response.
            while (preg_match('/\{(\d+)\}$/', $resp, $m)) {
                $n = (int) $m[1];
                $literalBytes += $n;
                if ($n > $maxLiteral || $literalBytes > $maxLiteral + self::MAX_LITERAL) {
                    $this->disconnect();
                    throw new MailboxException('The IMAP server sent more data than allowed (' . $n . ' bytes).');
                }
                $literals[] = $this->readBytes($n);
                $resp .= ' ' . $this->readLine();
            }
            if (str_starts_with($resp, $tag . ' ')) {
                $status = substr($resp, strlen($tag) + 1);
                if ($strict && !str_starts_with($status, 'OK')) {
                    throw new MailboxException('IMAP command failed: ' . mb_substr(preg_replace('/^(LOGIN)\b.*/i', '$1', $status) ?? '', 0, 160));
                }
                return [$untagged, $status, $literals];
            }
            if (str_starts_with($resp, '* BYE') && !str_starts_with($cmd, 'LOGOUT')) {
                throw new MailboxException('The IMAP server closed the session: ' . mb_substr($resp, 0, 120));
            }
            $untagged[] = $resp;
            if (count($untagged) > 100000) {
                throw new MailboxException('Too many lines in an IMAP response.');
            }
        }
    }
}
