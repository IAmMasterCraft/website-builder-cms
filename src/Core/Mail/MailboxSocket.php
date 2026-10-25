<?php
declare(strict_types=1);

namespace Core\Mail;

/**
 * Line-based socket for the bounce mailbox clients. Pure PHP streams (no
 * ext-imap): implicit TLS ("ssl", ports 993/995), STARTTLS/STLS upgrade
 * ("starttls", ports 143/110) or plain ("none", only for local testing).
 * Certificates are always verified (peer name = host); a custom CA file can
 * be given for private CAs. Every read has a timeout.
 */
abstract class MailboxSocket implements BounceMailbox
{
    /** @var resource|null */
    protected $stream = null;
    protected const MAX_LINE = 1_000_000;

    /**
     * @param array{host:string, port:int, encryption:string, username:string, password:string, timeout?:int, cafile?:string, connect_ip?:?string} $cfg
     */
    public function __construct(protected readonly array $cfg)
    {
    }

    protected function connect(): void
    {
        $host = $this->cfg['host'];
        if (!preg_match('/^[A-Za-z0-9.-]{1,190}$/', $host)) {
            throw new MailboxException('Invalid mailbox host name.');
        }
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true, 'allow_self_signed' => false];
        if (($this->cfg['cafile'] ?? '') !== '') {
            $ssl['cafile'] = $this->cfg['cafile'];
        }
        $ctx = stream_context_create(['ssl' => $ssl]);
        $scheme = $this->cfg['encryption'] === 'ssl' ? 'ssl' : 'tcp';
        $timeout = (int) ($this->cfg['timeout'] ?? 20);
        $errno = 0;
        $errstr = '';
        set_error_handler(static fn (): bool => true);
        try {
            // connect_ip: the address the caller checked (TLS still verifies the certificate against $host).
            $target = (string) ($this->cfg['connect_ip'] ?? '') !== '' ? (str_contains((string) $this->cfg['connect_ip'], ':') ? '[' . $this->cfg['connect_ip'] . ']' : (string) $this->cfg['connect_ip']) : $host;
            $s = stream_socket_client($scheme . '://' . $target . ':' . (int) $this->cfg['port'], $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        } finally {
            restore_error_handler();
        }
        if ($s === false) {
            throw new MailboxException('Could not connect to ' . $host . ':' . (int) $this->cfg['port'] . ($errstr !== '' ? ' (' . $errstr . ')' : '') . '. Check the server, port and encryption.');
        }
        stream_set_timeout($s, $timeout);
        $this->stream = $s;
    }

    /** Upgrade a plain connection to TLS (after STARTTLS / STLS was accepted). */
    protected function startTls(): void
    {
        set_error_handler(static fn (): bool => true);
        try {
            $ok = stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
        } finally {
            restore_error_handler();
        }
        if ($ok !== true) {
            throw new MailboxException('The TLS handshake failed (certificate not valid for ' . $this->cfg['host'] . '?).');
        }
    }

    protected function write(string $line): void
    {
        if ($this->stream === null) {
            throw new MailboxException('Not connected.');
        }
        $data = $line . "\r\n";
        $left = strlen($data);
        while ($left > 0) {
            $n = fwrite($this->stream, substr($data, -$left));
            if ($n === false || $n === 0) {
                throw new MailboxException('The connection was closed while writing.');
            }
            $left -= $n;
        }
    }

    /** One line without the line ending. */
    protected function readLine(): string
    {
        if ($this->stream === null) {
            throw new MailboxException('Not connected.');
        }
        $line = fgets($this->stream, self::MAX_LINE);
        if ($line === false) {
            $meta = stream_get_meta_data($this->stream);
            throw new MailboxException(!empty($meta['timed_out']) ? 'The mail server did not answer in time.' : 'The mail server closed the connection.');
        }
        return rtrim($line, "\r\n");
    }

    /** Exactly $n bytes (IMAP literals). */
    protected function readBytes(int $n): string
    {
        $out = '';
        while (strlen($out) < $n) {
            $chunk = fread($this->stream, min(65536, $n - strlen($out)));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->stream);
                throw new MailboxException(!empty($meta['timed_out']) ? 'The mail server did not answer in time.' : 'The connection was closed during a message.');
            }
            $out .= $chunk;
        }
        return $out;
    }

    protected function disconnect(): void
    {
        if ($this->stream !== null) {
            fclose($this->stream);
            $this->stream = null;
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
