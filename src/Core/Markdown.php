<?php
declare(strict_types=1);

namespace Core;

/**
 * A small, safe Markdown renderer for the documentation in docs/ (B3).
 *
 * Supported: ATX headings (with GitHub-style ids), paragraphs, bullet and
 * numbered lists (nested, tight or loose), fenced code blocks, tables with
 * alignment, blockquotes (GitHub "[!NOTE]" style notes), horizontal rules,
 * and inline code, links, autolinks, images, strong and emphasis.
 *
 * Safety: there is no raw HTML passthrough. Every piece of text is escaped;
 * link targets are limited to http(s), mailto, relative paths and #anchors
 * (anything else renders as plain text); images are only shown when the
 * image resolver returns a URL for them. The resolvers let the caller rewrite
 * links between documents (help routes or static .html files).
 */
final class Markdown
{
    /** @var (callable(string): ?string)|null link target (already checked by safeUrl) -> final href, or null for text only */
    private $linkResolver;
    /** @var (callable(string): ?string)|null image source -> URL, or null to drop the image */
    private $imageResolver;
    /** @var array<string,int> heading ids used so far */
    private array $ids = [];
    /** @var list<array{level:int,id:string,text:string}> */
    public array $headings = [];
    /** @var list<string> inline placeholders */
    private array $tokens = [];

    /** @param list<string> $reservedIds ids the surrounding page already uses (headings get a suffix instead) */
    public function __construct(?callable $linkResolver = null, ?callable $imageResolver = null, private array $reservedIds = [])
    {
        $this->linkResolver = $linkResolver;
        $this->imageResolver = $imageResolver;
    }

    public function render(string $markdown): string
    {
        $this->ids = array_fill_keys($this->reservedIds, 1);
        $this->headings = [];
        $markdown = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $markdown);
        // Strip a UTF-8 byte order mark and control characters other than newlines.
        $markdown = (string) preg_replace('/^\xEF\xBB\xBF|[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $markdown);
        return $this->blocks(explode("\n", $markdown));
    }

    /** Only http(s), mailto, relative paths and #anchors; null for anything else. */
    public static function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x20\x7F\\\\<>"]/', $url)) {
            return null;
        }
        if (preg_match('#^https?://[^/?\#@]+#i', $url) || preg_match('#^mailto:[^/?\#]+@[^/?\#]+$#i', $url)) {
            return $url;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) || str_starts_with($url, '//')) {
            return null;
        }
        return $url;
    }

    /** GitHub-compatible heading slug ("How do I…?" -> "how-do-i"). */
    public static function slug(string $text): string
    {
        $s = mb_strtolower(trim($text));
        $s = (string) preg_replace('/[^\p{L}\p{N}\s_-]+/u', '', $s);
        $s = (string) preg_replace('/\s/u', '-', $s);
        return $s !== '' ? $s : 'section';
    }

    // ---------------------------------------------------------------- blocks

    /** @param list<string> $lines */
    private function blocks(array $lines, bool $tight = false): string
    {
        $out = [];
        $n = count($lines);
        $i = 0;
        while ($i < $n) {
            $line = $lines[$i];
            if (trim($line) === '') {
                $i++;
                continue;
            }
            // Fenced code
            if (preg_match('/^( {0,3})(`{3,}|~{3,})\s*([A-Za-z0-9_+-]*)[^`]*$/', $line, $m)) {
                $indent = strlen($m[1]);
                $fence = $m[2];
                $lang = strtolower($m[3]);
                $code = [];
                $i++;
                while ($i < $n && !preg_match('/^ {0,3}' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}\s*$/', $lines[$i])) {
                    $code[] = (string) preg_replace('/^ {0,' . $indent . '}/', '', $lines[$i]);
                    $i++;
                }
                $i++; // closing fence (or end of input)
                $class = $lang !== '' ? ' class="language-' . self::e($lang) . '"' : '';
                $out[] = '<pre tabindex="0"><code' . $class . '>' . self::e(implode("\n", $code)) . '</code></pre>';
                continue;
            }
            // Heading
            if (preg_match('/^ {0,3}(#{1,6})\s+(.*?)(?:\s+#+)?\s*$/', $line, $m) || preg_match('/^ {0,3}(#{1,6})\s*$/', $line, $m)) {
                $level = strlen($m[1]);
                $raw = $m[2] ?? '';
                $html = $this->inline($raw);
                $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $id = $this->uniqueId(self::slug($text));
                $this->headings[] = ['level' => $level, 'id' => $id, 'text' => $text];
                $out[] = "<h$level id=\"" . self::e($id) . "\">$html</h$level>";
                $i++;
                continue;
            }
            // Horizontal rule
            if (preg_match('/^ {0,3}([-*_])(?:\s*\1){2,}\s*$/', $line)) {
                $out[] = '<hr>';
                $i++;
                continue;
            }
            // Table: a header row followed by a delimiter row
            if (str_contains($line, '|') && isset($lines[$i + 1]) && self::isTableDelimiter($lines[$i + 1])) {
                [$html, $i] = $this->table($lines, $i);
                $out[] = $html;
                continue;
            }
            // Blockquote
            if (preg_match('/^ {0,3}>/', $line)) {
                $quote = [];
                while ($i < $n && trim($lines[$i]) !== '') {
                    if (preg_match('/^ {0,3}> ?(.*)$/', $lines[$i], $m)) {
                        $quote[] = $m[1];
                    } elseif ($quote !== [] && !$this->startsBlock($lines[$i])) {
                        $quote[] = $lines[$i]; // lazy continuation
                    } else {
                        break;
                    }
                    $i++;
                }
                $out[] = $this->blockquote($quote);
                continue;
            }
            // List
            if (self::listMarker($line) !== null) {
                [$html, $i] = $this->listBlock($lines, $i);
                $out[] = $html;
                continue;
            }
            // Paragraph
            $para = [];
            while ($i < $n && trim($lines[$i]) !== '') {
                if ($para !== [] && ($this->startsBlock($lines[$i], true) || (str_contains($lines[$i], '|') && isset($lines[$i + 1]) && self::isTableDelimiter($lines[$i + 1])))) {
                    break;
                }
                $para[] = $lines[$i];
                $i++;
            }
            $html = $this->paragraphInline($para);
            $out[] = $tight ? $html : '<p>' . $html . '</p>';
        }
        return implode("\n", $out);
    }

    /** Does this line start a block that interrupts a paragraph? */
    private function startsBlock(string $line, bool $inParagraph = false): bool
    {
        if (preg_match('/^ {0,3}(#{1,6}(\s|$)|`{3,}|~{3,}|>)/', $line) || preg_match('/^ {0,3}([-*_])(?:\s*\1){2,}\s*$/', $line)) {
            return true;
        }
        $marker = self::listMarker($line);
        if ($marker === null) {
            return false;
        }
        // Like CommonMark: only a numbered list starting at 1 interrupts a paragraph.
        return !$inParagraph || !$marker['ordered'] || $marker['start'] === 1;
    }

    /** @return array{indent:int,ordered:bool,start:int,bullet:string,content:int,text:string}|null */
    private static function listMarker(string $line): ?array
    {
        if (!preg_match('/^( {0,3})([-*+]|(\d{1,9})([.)]))( +|$)(.*)$/', $line, $m)) {
            return null;
        }
        $spaces = strlen($m[5]);
        if ($spaces > 4) {
            $spaces = 1; // indented code inside a list item: not supported, keep the text
        }
        $ordered = ($m[3] ?? '') !== '';
        return [
            'indent' => strlen($m[1]),
            'ordered' => $ordered,
            'start' => $ordered ? (int) $m[3] : 0,
            'bullet' => $ordered ? $m[4] : $m[2],
            'content' => strlen($m[1]) + strlen($m[2]) + max(1, $spaces),
            'text' => ($spaces > 4 ? str_repeat(' ', strlen($m[5]) - 1) : '') . $m[6],
        ];
    }

    /**
     * @param list<string> $lines
     * @return array{0:string,1:int}
     */
    private function listBlock(array $lines, int $i): array
    {
        $first = self::listMarker($lines[$i]);
        assert($first !== null);
        $n = count($lines);
        $items = [];
        $loose = false;
        while ($i < $n) {
            $marker = self::listMarker($lines[$i]);
            if ($marker === null || $marker['ordered'] !== $first['ordered'] || $marker['bullet'] !== $first['bullet'] || $marker['indent'] > $first['content'] - 1) {
                break;
            }
            $content = $marker['content'];
            $item = [$marker['text']];
            $i++;
            $sawBlank = false;
            while ($i < $n) {
                $line = $lines[$i];
                if (trim($line) === '') {
                    $sawBlank = true;
                    $item[] = '';
                    $i++;
                    continue;
                }
                $indent = strlen($line) - strlen(ltrim($line, ' '));
                if ($indent >= $content) {
                    if ($sawBlank && self::nonBlank($item) > 0) {
                        $loose = $loose || !self::onlyNestedList($line, $content);
                    }
                    $item[] = substr($line, $content);
                    $sawBlank = false;
                    $i++;
                    continue;
                }
                if (!$sawBlank && !$this->startsBlock($line) && self::listMarker($line) === null) {
                    $item[] = ltrim($line); // lazy paragraph continuation
                    $i++;
                    continue;
                }
                break;
            }
            // Trailing blank lines belong between items, not inside this one.
            $trailing = 0;
            while ($item !== [] && trim((string) end($item)) === '') {
                array_pop($item);
                $trailing++;
            }
            if ($trailing > 0 && $i < $n && ($next = self::listMarker($lines[$i])) !== null && $next['ordered'] === $first['ordered'] && $next['bullet'] === $first['bullet'] && $next['indent'] <= $first['content'] - 1) {
                $loose = true;
            }
            $items[] = $item;
        }
        $html = [];
        foreach ($items as $item) {
            $html[] = '<li>' . $this->blocks($item, !$loose) . '</li>';
        }
        $tag = $first['ordered'] ? 'ol' : 'ul';
        $start = $first['ordered'] && $first['start'] !== 1 ? ' start="' . $first['start'] . '"' : '';
        return ["<$tag$start>\n" . implode("\n", $html) . "\n</$tag>", $i];
    }

    /** @param list<string> $lines */
    private static function nonBlank(array $lines): int
    {
        return count(array_filter($lines, static fn (string $l): bool => trim($l) !== ''));
    }

    private static function onlyNestedList(string $line, int $content): bool
    {
        return self::listMarker(substr($line, $content)) !== null;
    }

    private static function isTableDelimiter(string $line): bool
    {
        $line = trim($line);
        return str_contains($line, '-') && (bool) preg_match('/^\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?$/', $line) && (str_contains($line, '|') || preg_match('/^:?-+:?$/', $line) === 0);
    }

    /** @return list<string> */
    private static function tableCells(string $line): array
    {
        $line = trim($line);
        if (str_starts_with($line, '|')) {
            $line = substr($line, 1);
        }
        if (str_ends_with($line, '|') && !str_ends_with($line, '\\|')) {
            $line = substr($line, 0, -1);
        }
        // Split on pipes that are not escaped and not inside code spans.
        $cells = [];
        $cur = '';
        $inCode = false;
        $len = strlen($line);
        for ($k = 0; $k < $len; $k++) {
            $ch = $line[$k];
            if ($ch === '\\' && ($line[$k + 1] ?? '') === '|') {
                $cur .= '|';
                $k++;
                continue;
            }
            if ($ch === '`') {
                $inCode = !$inCode;
            }
            if ($ch === '|' && !$inCode) {
                $cells[] = trim($cur);
                $cur = '';
                continue;
            }
            $cur .= $ch;
        }
        $cells[] = trim($cur);
        return $cells;
    }

    /**
     * @param list<string> $lines
     * @return array{0:string,1:int}
     */
    private function table(array $lines, int $i): array
    {
        $head = self::tableCells($lines[$i]);
        $aligns = array_map(static function (string $d): string {
            $d = trim($d);
            return match (true) {
                str_starts_with($d, ':') && str_ends_with($d, ':') => 'center',
                str_ends_with($d, ':') => 'right',
                default => '',
            };
        }, self::tableCells($lines[$i + 1]));
        $i += 2;
        $rows = [];
        $n = count($lines);
        while ($i < $n && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) {
            $rows[] = self::tableCells($lines[$i]);
            $i++;
        }
        $cols = count($head);
        $cell = function (string $tag, string $text, int $col) use ($aligns): string {
            $align = $aligns[$col] ?? '';
            return "<$tag" . ($align !== '' ? ' class="align-' . $align . '"' : '') . ($tag === 'th' ? ' scope="col"' : '') . '>' . $this->inline($text) . "</$tag>";
        };
        $html = '<div class="md-table"><table><thead><tr>';
        foreach ($head as $c => $text) {
            $html .= $cell('th', $text, $c);
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            for ($c = 0; $c < $cols; $c++) {
                $html .= $cell('td', $row[$c] ?? '', $c);
            }
            $html .= '</tr>';
        }
        return [$html . '</tbody></table></div>', $i];
    }

    /** @param list<string> $lines */
    private function blockquote(array $lines): string
    {
        $kinds = ['NOTE' => 'Note', 'TIP' => 'Tip', 'IMPORTANT' => 'Important', 'WARNING' => 'Warning', 'CAUTION' => 'Caution'];
        if ($lines !== [] && preg_match('/^\s*\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]\s*$/i', $lines[0], $m)) {
            $kind = strtoupper($m[1]);
            array_shift($lines);
            return '<aside class="md-note md-note-' . strtolower($kind) . '"><p class="md-note-title">' . $kinds[$kind] . '</p>' . $this->blocks($lines) . '</aside>';
        }
        return '<blockquote>' . $this->blocks($lines) . '</blockquote>';
    }

    private function uniqueId(string $id): string
    {
        $candidate = $id;
        $k = 0;
        while (isset($this->ids[$candidate])) {
            $candidate = $id . '-' . (++$k);
        }
        $this->ids[$candidate] = 1;
        return $candidate;
    }

    // ---------------------------------------------------------------- inline

    /**
     * A paragraph's lines rendered together (so emphasis and links may span
     * lines); two trailing spaces or a backslash make a hard line break.
     * @param list<string> $lines
     */
    private function paragraphInline(array $lines): string
    {
        $parts = [];
        $last = count($lines) - 1;
        foreach ($lines as $k => $line) {
            $line = ltrim($line);
            $break = $k < $last && (str_ends_with($line, '  ') || (str_ends_with(rtrim($line, ' '), '\\') && !str_ends_with(rtrim($line, ' '), '\\\\')));
            $line = rtrim($line, ' ');
            if ($break && str_ends_with($line, '\\')) {
                $line = substr($line, 0, -1);
            }
            // \x1B never survives render()'s control-character filter, so it is a safe marker.
            $parts[] = $line . ($break ? "\x1B" : '');
        }
        return str_replace("\x1B", '<br>', $this->inline(implode("\n", $parts)));
    }

    /** Render inline Markdown; every text fragment is escaped. */
    public function inline(string $text): string
    {
        $saved = $this->tokens;
        $this->tokens = [];
        $buf = '';
        $len = strlen($text);
        $i = 0;
        while ($i < $len) {
            $ch = $text[$i];
            // Backslash escape of ASCII punctuation
            if ($ch === '\\' && $i + 1 < $len && preg_match('/[!-\/:-@\[-`{-~]/', $text[$i + 1])) {
                $buf .= $this->token(self::e($text[$i + 1]));
                $i += 2;
                continue;
            }
            // Code span
            if ($ch === '`') {
                $run = strspn($text, '`', $i);
                $close = $this->findBackticks($text, $i + $run, $run);
                if ($close !== null) {
                    $code = substr($text, $i + $run, $close - $i - $run);
                    $code = str_replace("\n", ' ', $code);
                    if (strlen($code) > 2 && $code[0] === ' ' && $code[strlen($code) - 1] === ' ' && trim($code) !== '') {
                        $code = substr($code, 1, -1);
                    }
                    $buf .= $this->token('<code>' . self::e($code) . '</code>');
                    $i = $close + $run;
                    continue;
                }
                $buf .= str_repeat('`', $run);
                $i += $run;
                continue;
            }
            // Autolink <https://…> or <mailto:…>
            if ($ch === '<' && preg_match('/\G<((?:https?:\/\/|mailto:)[^\s<>]+)>/i', $text, $m, 0, $i)) {
                $buf .= $this->token($this->link($m[1], self::e($m[1])));
                $i += strlen($m[0]);
                continue;
            }
            // Image or link
            if ($ch === '!' && ($text[$i + 1] ?? '') === '[' || $ch === '[') {
                $isImage = $ch === '!';
                $open = $i + ($isImage ? 1 : 0);
                $close = self::matchBracket($text, $open);
                if ($close !== null && ($text[$close + 1] ?? '') === '(') {
                    $dest = self::parseDestination($text, $close + 1);
                    if ($dest !== null) {
                        [$url, $end] = $dest;
                        $label = substr($text, $open + 1, $close - $open - 1);
                        $buf .= $this->token($isImage ? $this->image($url, $label) : $this->link($url, $this->inline($label)));
                        $i = $end;
                        continue;
                    }
                }
            }
            $buf .= $ch;
            $i++;
        }
        $html = $this->emphasis(self::e($buf));
        // Put the protected fragments back (they may nest: link text holds code).
        for ($pass = 0; $pass < 3 && str_contains($html, "\x1A"); $pass++) {
            $html = (string) preg_replace_callback('/\x1A(\d+)\x1A/', fn (array $m): string => $this->tokens[(int) $m[1]] ?? '', $html);
        }
        $this->tokens = $saved;
        return $html;
    }

    private function token(string $html): string
    {
        $this->tokens[] = $html;
        return "\x1A" . (count($this->tokens) - 1) . "\x1A";
    }

    private function findBackticks(string $text, int $from, int $run): ?int
    {
        $len = strlen($text);
        $p = $from;
        while ($p < $len && ($p = strpos($text, '`', $p)) !== false) {
            $r = strspn($text, '`', $p);
            if ($r === $run) {
                return $p;
            }
            $p += $r;
        }
        return null;
    }

    private static function matchBracket(string $text, int $open): ?int
    {
        $depth = 0;
        $len = strlen($text);
        for ($k = $open; $k < $len; $k++) {
            $c = $text[$k];
            if ($c === '\\') {
                $k++;
                continue;
            }
            if ($c === '`') {
                $run = strspn($text, '`', $k);
                $end = strpos($text, str_repeat('`', $run), $k + $run);
                if ($end !== false) {
                    $k = $end + $run - 1;
                    continue;
                }
            }
            if ($c === '[') {
                $depth++;
            } elseif ($c === ']') {
                $depth--;
                if ($depth === 0) {
                    return $k;
                }
            }
        }
        return null;
    }

    /** @return array{0:string,1:int}|null URL and the offset after ")" */
    private static function parseDestination(string $text, int $paren): ?array
    {
        if (!preg_match('/\G\(\s*(<[^<>\n]*>|[^\s()]*(?:\([^\s()]*\)[^\s()]*)*)(?:\s+("[^"]*"|\'[^\']*\'))?\s*\)/', $text, $m, 0, $paren)) {
            return null;
        }
        $url = $m[1];
        if (str_starts_with($url, '<')) {
            $url = substr($url, 1, -1);
        }
        return [$url, $paren + strlen($m[0])];
    }

    private function emphasis(string $html): string
    {
        $html = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $html);
        $html = (string) preg_replace('/(?<![\p{L}\p{N}_])__(?=\S)(.+?)(?<=\S)__(?![\p{L}\p{N}_])/su', '<strong>$1</strong>', $html);
        $html = (string) preg_replace('/(?<![\*\p{L}\p{N}])\*(?=[^\s*])(.+?)(?<=[^\s*])\*(?![\*\p{L}\p{N}])/su', '<em>$1</em>', $html);
        return (string) preg_replace('/(?<![\p{L}\p{N}_])_(?=[^\s_])(.+?)(?<=[^\s_])_(?![\p{L}\p{N}_])/su', '<em>$1</em>', $html);
    }

    private function link(string $url, string $labelHtml): string
    {
        $safe = self::safeUrl($url);
        $href = $safe === null ? null : ($this->linkResolver !== null ? ($this->linkResolver)($safe) : $safe);
        if ($href === null || self::safeUrl($href) === null) {
            return '<span class="md-link-text">' . $labelHtml . '</span>';
        }
        $external = (bool) preg_match('#^https?://#i', $href);
        return '<a href="' . self::e($href) . '"' . ($external ? ' rel="noopener noreferrer"' : '') . '>' . $labelHtml . '</a>';
    }

    private function image(string $src, string $alt): string
    {
        $altText = trim(html_entity_decode(strip_tags($this->inline($alt)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $safe = self::safeUrl($src);
        $url = $safe !== null && $this->imageResolver !== null ? ($this->imageResolver)($safe) : null;
        if ($url === null || self::safeUrl($url) === null) {
            return $altText !== '' ? '<span class="md-image-missing">[' . self::e($altText) . ']</span>' : '';
        }
        return '<img src="' . self::e($url) . '" alt="' . self::e($altText) . '" loading="lazy" decoding="async">';
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
