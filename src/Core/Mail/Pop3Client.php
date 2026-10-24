<?php
declare(strict_types=1);

namespace Core\Mail;

/**
 * Minimal POP3 client (RFC 1939, STLS from RFC 2595) for the bounce
 * mailbox: USER/PASS, LIST, RETR (dot-unstuffing), DELE, QUIT. POP3 has no
 * folders, so unprocessed messages are kept locally by the caller.
 */
final class Pop3Client extends MailboxSocket
{
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
        $ids = [];
        foreach ($this->multiline() as $line) {
            if (preg_match('/^(\d+)\s+\d+/', $line, $m)) {
                $ids[] = $m[1];
            }
        }
        return array_slice($ids, 0, max(0, $limit));
    }

    public function fetch(string $id): string
    {
        $this->id($id);
        $this->write('RETR ' . $id);
        $this->expectOk($this->readLine(), 'RETR');
        return implode("\r\n", $this->multiline()) . "\r\n";
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

    /** Lines of a multi-line response up to the terminating ".", dot-unstuffed. @return list<string> */
    private function multiline(): array
    {
        $out = [];
        $bytes = 0;
        while (($line = $this->readLine()) !== '.') {
            if (str_starts_with($line, '..')) {
                $line = substr($line, 1);
            }
            $bytes += strlen($line) + 2;
            if ($bytes > 50 * 1024 * 1024) {
                throw new MailboxException('A message is too large to process.');
            }
            $out[] = $line;
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
