<?php
declare(strict_types=1);

/*
 * List hygiene (Addendum A5): does an address's domain accept mail? Checked
 * at sign-up, CSV import and campaign queueing with an MX lookup (A/AAAA as
 * the implicit-MX fallback, RFC 5321 §5.1; a "null MX" (RFC 7505) means no
 * mail). Results are cached in email_domains. Lookups use the system
 * resolver (dns_get_record), which has its own timeouts; to keep a request
 * from hanging on a slow resolver each request gets a time budget, after
 * which further domains count as "unknown" (accepted). Only a definite
 * "no mail server" rejects an address: a lookup error never does.
 *
 * Sign-up is a public form, so it uses the quick mode: MX only (no A/AAAA
 * fallback; an empty MX answer is "unknown"), a 2-second request budget,
 * and every mode skips the A/AAAA step when the MX lookup alone took more
 * than a second. Uncached lookups are also limited across all requests
 * (DNS_LOOKUPS_PER_10_MIN), so many parallel sign-ups cannot tie up the PHP
 * workers waiting on DNS; the cache table is pruned by the daily cron
 * (expired rows, at most DNS_CACHE_MAX_ROWS).
 *
 * Tests inject a resolver with dns_resolver(); names reserved for testing
 * and documentation (RFC 2606/6761) are answered without any lookup.
 */

const DNS_OK_TTL_DAYS = 7;
const DNS_NONE_TTL_HOURS = 24;
const DNS_REQUEST_BUDGET_SECONDS = 4.0;
/** Budget per request in quick mode (sign-up). */
const DNS_QUICK_BUDGET_SECONDS = 2.0;
/** An MX lookup slower than this is not followed by A/AAAA lookups. */
const DNS_SLOW_LOOKUP_SECONDS = 1.0;
/** Uncached lookups across all requests per 10 minutes; beyond that, "unknown" (accepted). */
const DNS_LOOKUPS_PER_10_MIN = 60;
const DNS_CACHE_MAX_ROWS = 20000;

/** Big mailbox providers: never looked up (saves time on every sign-up). */
const DNS_KNOWN_DOMAINS = [
    'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk', 'ymail.com', 'hotmail.com', 'hotmail.co.uk', 'outlook.com', 'live.com', 'live.co.uk', 'msn.com',
    'icloud.com', 'me.com', 'mac.com', 'aol.com', 'btinternet.com', 'sky.com', 'virginmedia.com', 'talktalk.net', 'protonmail.com', 'proton.me', 'gmx.com',
    'gmx.co.uk', 'mail.com', 'zoho.com', 'fastmail.com', 'yandex.com',
];

/**
 * The resolver: fn(string $domain, bool $mxOnly = false): 'ok'|'none'|'unknown'.
 * Pass one to replace it (tests), or $reset to go back to the real one.
 */
function dns_resolver(?callable $set = null, bool $reset = false): callable
{
    static $resolver = null;
    if ($reset) {
        $resolver = null;
    }
    if ($set !== null) {
        $resolver = $set;
    }
    // GATHER_DNS=off (environment) switches lookups off: every domain is "unknown" (accepted). Used by the smoke test.
    return $resolver ??= getenv('GATHER_DNS') === 'off' ? static fn (string $domain): string => 'unknown' : 'dns_resolve_system';
}

/** Real lookup with dns_get_record(); warnings from the resolver are caught, not printed. */
function dns_resolve_system(string $domain, bool $mxOnly = false): string
{
    $start = microtime(true);
    $lookup = static function (string $d, int $type): array|false {
        set_error_handler(static fn (): bool => true);
        try {
            return dns_get_record($d, $type);
        } catch (Throwable) {
            return false;
        } finally {
            restore_error_handler();
        }
    };
    $mx = $lookup($domain . '.', DNS_MX);
    if ($mx === false) {
        return 'unknown';
    }
    if ($mx !== []) {
        // RFC 7505 null MX: "0 ." = this domain does not accept mail.
        $hosts = array_map(static fn (array $r): string => rtrim((string) ($r['target'] ?? ''), '.'), $mx);
        return array_filter($hosts, static fn (string $h): bool => $h !== '') === [] ? 'none' : 'ok';
    }
    // No MX: the A/AAAA fallback costs two more lookups. Skip it at sign-up or when DNS is slow.
    if ($mxOnly || microtime(true) - $start > DNS_SLOW_LOOKUP_SECONDS) {
        return 'unknown';
    }
    $a = $lookup($domain . '.', DNS_A);
    if ($a === false) {
        return 'unknown';
    }
    if ($a !== []) {
        return 'ok';
    }
    $aaaa = $lookup($domain . '.', DNS_AAAA);
    if ($aaaa === false) {
        return 'unknown';
    }
    return $aaaa !== [] ? 'ok' : 'none';
}

/** Seconds of DNS lookups spent in this request (add $add). */
function dns_budget_spent(float $add = 0.0, bool $reset = false): float
{
    static $spent = 0.0;
    if ($reset) {
        $spent = 0.0;
    }
    return $spent += $add;
}

/**
 * Can $domain receive mail? 'ok', 'none' (definitely not) or 'unknown'
 * (lookup failed or the time budget is used up: treated as fine).
 */
function email_domain_status(string $domain, ?int $now = null, bool $quick = false): string
{
    $domain = strtolower(rtrim(trim($domain), '.'));
    if ($domain === '' || !preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $domain)) {
        return 'none';
    }
    // RFC 6761: .invalid never exists; testing and documentation names are not looked up.
    if (str_ends_with($domain, '.invalid')) {
        return 'none';
    }
    if (str_ends_with($domain, '.test') || str_ends_with($domain, '.example') || str_ends_with($domain, '.localhost')
        || in_array($domain, ['example.com', 'example.net', 'example.org'], true) || in_array($domain, DNS_KNOWN_DOMAINS, true)) {
        return 'ok';
    }
    $memo = &dns_memo();
    if (isset($memo[$domain])) {
        return $memo[$domain];
    }
    $now ??= time();
    $cached = q_one('SELECT status, checked_at FROM email_domains WHERE domain = ?', [$domain]);
    if ($cached !== null) {
        $age = $now - (int) strtotime($cached['checked_at'] . ' UTC');
        $ttl = $cached['status'] === 'ok' ? DNS_OK_TTL_DAYS * 86400 : DNS_NONE_TTL_HOURS * 3600;
        if ($age >= 0 && $age < $ttl) {
            return $memo[$domain] = (string) $cached['status'];
        }
    }
    if (dns_budget_spent() >= ($quick ? DNS_QUICK_BUDGET_SECONDS : DNS_REQUEST_BUDGET_SECONDS)) {
        return 'unknown';
    }
    if (rate_limited('dns-lookup', DNS_LOOKUPS_PER_10_MIN, 600)) {
        return 'unknown';
    }
    rate_hit('dns-lookup');
    $t = microtime(true);
    $status = (dns_resolver())($domain, $quick);
    dns_budget_spent(microtime(true) - $t);
    $status = in_array($status, ['ok', 'none'], true) ? $status : 'unknown';
    if ($status !== 'unknown') {
        q('INSERT INTO email_domains (domain, status, checked_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status), checked_at = VALUES(checked_at)',
            [$domain, $status, gmdate('Y-m-d H:i:s', $now)]);
    }
    return $memo[$domain] = $status;
}

/** Daily cron: drop expired cache rows and keep at most DNS_CACHE_MAX_ROWS. Returns rows removed. */
function email_domains_prune(?int $now = null): int
{
    $now ??= time();
    $n = q("DELETE FROM email_domains WHERE (status = 'ok' AND checked_at < ?) OR (status = 'none' AND checked_at < ?)",
        [gmdate('Y-m-d H:i:s', $now - DNS_OK_TTL_DAYS * 86400), gmdate('Y-m-d H:i:s', $now - DNS_NONE_TTL_HOURS * 3600)])->rowCount();
    $over = (int) q_val('SELECT COUNT(*) FROM email_domains') - DNS_CACHE_MAX_ROWS;
    if ($over > 0) {
        $n += q('DELETE FROM email_domains ORDER BY checked_at ASC LIMIT ' . $over)->rowCount();
    }
    return $n;
}

/** Per-request answers. */
function &dns_memo(): array
{
    static $memo = [];
    return $memo;
}

/** Forget the per-request memo and budget (tests). */
function dns_forget(): void
{
    dns_budget_spent(0.0, true);
    $memo = &dns_memo();
    $memo = [];
}

/** A message for an address whose domain cannot receive mail, or null. $quick: public forms (MX only, smaller budget). */
function email_domain_problem(string $email, bool $quick = false): ?string
{
    $domain = email_domain($email);
    return email_domain_status($domain, null, $quick) === 'none' ? 'We could not find a mail server for “' . $domain . '”. Please check the address.' : null;
}

/** Common domains for typo suggestions on the sign-up form. */
const EMAIL_SUGGEST_DOMAINS = [
    'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk', 'hotmail.com', 'hotmail.co.uk', 'outlook.com', 'live.com', 'live.co.uk', 'icloud.com',
    'aol.com', 'btinternet.com', 'sky.com', 'virginmedia.com', 'talktalk.net', 'protonmail.com', 'msn.com', 'me.com', 'ymail.com',
];

/** "amara@gmial.com" -> "amara@gmail.com" for obvious typos of common domains, else null. */
function email_typo_suggestion(string $email): ?string
{
    $at = strrpos($email, '@');
    if ($at === false) {
        return null;
    }
    $local = substr($email, 0, $at);
    $domain = strtolower(substr($email, $at + 1));
    if ($domain === '' || in_array($domain, EMAIL_SUGGEST_DOMAINS, true) || in_array($domain, DNS_KNOWN_DOMAINS, true)) {
        return null;
    }
    $best = null;
    $bestDist = PHP_INT_MAX;
    foreach (EMAIL_SUGGEST_DOMAINS as $candidate) {
        $d = levenshtein($domain, $candidate);
        if ($d < $bestDist) {
            [$best, $bestDist] = [$candidate, $d];
        }
    }
    // One or two edits on a domain of a reasonable length ("gmial.com", "hotmail.con", "yahooo.co.uk").
    return $best !== null && $bestDist <= 2 && strlen($domain) >= 6 ? $local . '@' . $best : null;
}
