<?php
declare(strict_types=1);

namespace Core\Mail;

/**
 * Minimal MIME reader for bounce processing: headers (unfolded, decoded
 * names in lower case), Content-Type parameters, transfer decoding
 * (base64, quoted-printable) and nested multipart parts. Never executes or
 * renders anything; bodies are treated as data.
 */
final class MimeMessage
{
    /** Hard limits so a hostile message cannot exhaust memory or recursion. */
    private const MAX_DEPTH = 6;
    private const MAX_PARTS = 60;

    /** @param array<string, list<string>> $headers lower-case name => values */
    private function __construct(
        public readonly array $headers,
        public readonly string $body,
        /** @var list<MimeMessage> */
        public readonly array $parts,
    ) {
    }

    public static function parse(string $raw, int $depth = 0): self
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $split = strpos($raw, "\n\n");
        // A part may start with a blank line (no headers at all).
        if (str_starts_with($raw, "\n")) {
            $head = '';
            $body = substr($raw, 1);
        } elseif ($split === false) {
            $head = $raw;
            $body = '';
        } else {
            $head = substr($raw, 0, $split);
            $body = substr($raw, $split + 2);
        }
        $headers = self::parseHeaders($head);
        $msg = new self($headers, $body, []);
        $type = $msg->contentType();
        $parts = [];
        if (str_starts_with($type, 'multipart/') && $depth < self::MAX_DEPTH) {
            $boundary = $msg->param('content-type', 'boundary');
            if ($boundary !== null && $boundary !== '') {
                $parts = self::splitMultipart($body, $boundary, $depth);
            }
        } elseif (($type === 'message/rfc822' || $type === 'message/global') && $depth < self::MAX_DEPTH) {
            $parts = [self::parse($msg->decodedBody(), $depth + 1)];
        }
        return new self($headers, $body, $parts);
    }

    /** @return array<string, list<string>> */
    public static function parseHeaders(string $head): array
    {
        $head = str_replace("\r\n", "\n", $head);
        $unfolded = preg_replace("/\n[ \t]+/", ' ', $head) ?? $head;
        $out = [];
        foreach (explode("\n", $unfolded) as $line) {
            if (!preg_match('/^([!-9;-~]+):[ \t]*(.*)$/', $line, $m)) {
                continue;
            }
            $out[strtolower($m[1])][] = trim($m[2]);
        }
        return $out;
    }

    /** @return list<self> */
    private static function splitMultipart(string $body, string $boundary, int $depth): array
    {
        $parts = [];
        $chunks = explode("\n--" . $boundary, "\n" . $body);
        array_shift($chunks); // preamble
        foreach ($chunks as $chunk) {
            if (str_starts_with($chunk, '--')) {
                break; // closing boundary
            }
            $nl = strpos($chunk, "\n");
            $chunk = $nl === false ? '' : substr($chunk, $nl + 1);
            $parts[] = self::parse($chunk, $depth + 1);
            if (count($parts) >= self::MAX_PARTS) {
                break;
            }
        }
        return $parts;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /** @return list<string> */
    public function headerAll(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    /** Lower-case media type without parameters ("text/plain" when missing). */
    public function contentType(): string
    {
        $v = $this->header('content-type');
        if ($v === null) {
            return 'text/plain';
        }
        return strtolower(trim(explode(';', $v)[0]));
    }

    /** A parameter of a structured header, e.g. param('content-type', 'boundary'). */
    public function param(string $header, string $name): ?string
    {
        $v = $this->header($header);
        if ($v === null) {
            return null;
        }
        if (preg_match('/;\s*' . preg_quote($name, '/') . '\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^;\s]+))/i', $v, $m)) {
            return isset($m[2]) && $m[2] !== '' ? $m[2] : stripcslashes($m[1]);
        }
        return null;
    }

    /** Body with the transfer encoding removed. */
    public function decodedBody(): string
    {
        $enc = strtolower(trim((string) $this->header('content-transfer-encoding')));
        return match ($enc) {
            'base64' => (string) base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $this->body) ?? '', false),
            'quoted-printable' => quoted_printable_decode($this->body),
            default => $this->body,
        };
    }

    /**
     * Depth-first list of this part and all nested parts.
     * @return list<self>
     */
    public function flatten(): array
    {
        $out = [$this];
        foreach ($this->parts as $p) {
            array_push($out, ...$p->flatten());
        }
        return $out;
    }

    /** First nested part (or this one) with the given media type. */
    public function find(string $type): ?self
    {
        foreach ($this->flatten() as $p) {
            if ($p->contentType() === $type) {
                return $p;
            }
        }
        return null;
    }

    /**
     * Decoded "Name <addr>" lists in a header: the bare addresses, lower case.
     * @return list<string>
     */
    public static function addresses(string $value): array
    {
        preg_match_all('/[A-Za-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+/', $value, $m);
        return array_values(array_unique(array_map('strtolower', $m[0])));
    }
}
