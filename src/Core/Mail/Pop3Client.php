<?php
declare(strict_types=1);

namespace Core\Mail;

/**
 * Minimal POP3 client (RFC 1939, STLS from RFC 2595) for the bounce
 * mailbox: USER/PASS, LIST (with sizes), RETR (dot-unstuffing, capped in
 * bytes and lines), DELE, QUIT. POP3 has no folders, so unprocessed
 * messages are kept locally by the caller; oversized ones are deleted
 * without being downloaded.
 */
final class Pop3Client extends MailboxSocket
{
    private const MAX_LINES = 100_000;
    private const MAX_LIST_BYTES = 1_048_576;
    /** A message bigger than announced is read to its end and discarded, up to this many bytes. */
    private const DRAIN_BYTES = 16 * 1024 * 1024;

    public function open(): void
    {
        $this->connect();
        $this->expectOk($this->readLine(), 'greeting');
        if ($this->cfg['encryption'] === 'starttls') {
            $this->write('STLS');
            $this->expectOk($this->readLine(), 'STLS');
            $this->startTls();
        }
        $this->write('USER ' . $this->arg($this->cfg['username']));
        $this->expectOk($this->readLine(), 'USER', true);
        $this->write('PASS ' . $this->arg($this->cfg['password']));
        $this->expectOk($this->readLine(), 'PASS', true);
    }

    public function list(int $limit): array
    {
        $this->write('LIST');
        $this->expectOk($this->readLine(), 'LIST');
        $out = [];
        foreach ($this->multiline(self::MAX_LIST_BYTES) as $line) {
            if (preg_match('/^(\d+)\s+(\d+)/', $line, $m) && count($out) < max(0, $limit)) {
                $out[$m[1]] = (int) $m[2];
            }
        }
        return $out;
    }

    public function fetch(string $id, int $maxBytes): string
    {
        $this->id($id);
        $this->write('RETR ' . $id);
        $this->expectOk($this->readLine(), 'RETR');
        return implode("\r\n", $this->multiline(max(1, $maxBytes))) . "\r\n";
    }

    public function delete(string $id): void
    {
        $this->id($id);
        $this->write('DELE ' . $id);
        $this->expectOk($this->readLine(), 'DELE');
    }

    public function moveAside(string $id): bool
    {
        return false;
    }

    public function pruneAside(int $maxCount, int $maxAgeDays): int
    {
        return 0;
    }

    public function close(): void
    {
        if ($this->stream === null) {
            return;
        }
        try {
            $this->write('QUIT'); // deletions are applied when the session ends with QUIT
            $this->expectOk($this->readLine(), 'QUIT');
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Lines of a multi-line response up to the terminating ".", dot-unstuffed,
     * at most $maxBytes and MAX_LINES lines. When a message is bigger, the
     * rest is read and thrown away (up to DRAIN_BYTES, so the session stays
     * in step) and MessageTooLargeException is thrown.
     * @return list<string>
     */
    private function multiline(int $maxBytes): array
    {
        $out = [];
        $bytes = 0;
        $tooLarge = false;
        while (($line = $this->readLine()) !== '.') {
            if (str_starts_with($line, '..')) {
                $line = substr($line, 1);
            }
            $bytes += strlen($line) + 2;
            if ($tooLarge || $bytes > $maxBytes || count($out) >= self::MAX_LINES) {
                $tooLarge = true;
                $out = [];
                if ($bytes > self::DRAIN_BYTES) {
                    $this->disconnect();
                    throw new MailboxException('The POP3 server sent more data than allowed.');
                }
                continue;
            }
            $out[] = $line;
        }
        if ($tooLarge) {
            throw new MessageTooLargeException('A message is larger than ' . $maxBytes . ' bytes.');
        }
        return $out;
    }

    private function expectOk(string $line, string $what, bool $login = false): void
    {
        if (!str_starts_with($line, '+OK')) {
            throw new MailboxException($login ? 'The POP3 login was refused. Check the user name and password.' : 'POP3 ' . $what . ' failed: ' . mb_substr($line, 0, 120));
        }
    }

    private function id(string $id): void
    {
        if (!ctype_digit($id)) {
            throw new MailboxException('Invalid message id.');
        }
    }

    private function arg(string $v): string
    {
        if (preg_match('/[\r\n\x00]/', $v)) {
            throw new MailboxException('Line breaks are not allowed in POP3 credentials.');
        }
        return $v;
    }
}
