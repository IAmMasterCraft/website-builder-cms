<?php
declare(strict_types=1);

function slugify(string $text, int $max = 120): string
{
    $text = trim($text);
    if (function_exists('transliterator_transliterate')) {
        $t = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
        if (is_string($t)) {
            $text = $t;
        }
    }
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    $text = trim(substr(trim($text, '-'), 0, $max), '-');
    return $text;
}

/** Make $slug unique in $table.slug, adding -2, -3... */
function unique_slug(string $table, string $slug, ?int $ignoreId = null, string $column = 'slug'): string
{
    $base = $slug !== '' ? $slug : 'item';
    $candidate = $base;
    for ($i = 2; ; $i++) {
        $sql = 'SELECT id FROM ' . db_ident($table) . ' WHERE ' . db_ident($column) . ' = ?';
        $params = [$candidate];
        if ($ignoreId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $ignoreId;
        }
        if (q_val($sql, $params) === null) {
            return $candidate;
        }
        $candidate = $base . '-' . $i;
    }
}

/** Plain-text excerpt from (possibly HTML) text. */
function excerpt(?string $text, int $length = 180): string
{
    $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    if (mb_strlen($plain) <= $length) {
        return $plain;
    }
    $cut = mb_substr($plain, 0, $length);
    $space = mb_strrpos($cut, ' ');
    return rtrim(mb_substr($cut, 0, $space !== false && $space > $length * 0.6 ? $space : $length), " ,.;:") . '…';
}

/** Accept only http(s), mailto, tel or same-site relative URLs. */
function safe_url(?string $url): string
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    // Same-site paths: browsers treat "/\host", "//host" and "/<tab>/host" as other sites,
    // so backslashes, whitespace/control characters and a second slash are refused.
    if (str_starts_with($url, '/')) {
        return !preg_match('/[\x00-\x20\\\\"<>]/', $url) && !in_array($url[1] ?? '', ['/', '\\'], true) ? $url : '';
    }
    if (str_starts_with($url, '#')) {
        return !preg_match('/[\x00-\x20"<>]/', $url) ? $url : '';
    }
    if (preg_match('#^(https?://|mailto:|tel:)#i', $url) && !preg_match('/[\x00-\x20"<>]/', $url)) {
        return $url;
    }
    return '';
}
