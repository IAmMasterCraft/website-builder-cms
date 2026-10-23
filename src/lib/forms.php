<?php
declare(strict_types=1);

/*
 * Anti-abuse for public forms (§4.1): a honeypot field, a signed
 * "form shown at" time (too fast = bot, too old = stale), and a per-IP
 * rate limit. Used by /join, /join/group and the contact-form section.
 */

const FORM_MIN_SECONDS = 3;
const FORM_MAX_SECONDS = 7200;
const FORM_HONEYPOT = 'website_url';

/** HMAC with the install's secret key. */
function app_sign(string $data): string
{
    $key = (string) config('app_key', '');
    if ($key === '' || $key === 'change-me') {
        $key = 'gather-unset-key:' . (string) config('db.name', '');
    }
    return hash_hmac('sha256', $data, $key);
}

/** Hidden fields printed inside every guarded form. */
function form_guard_fields(string $form, ?int $now = null): string
{
    $ts = (string) ($now ?? time());
    return '<div class="hp" aria-hidden="true"><label for="hp-' . e($form) . '">Leave this empty</label>'
        . '<input type="text" id="hp-' . e($form) . '" name="' . FORM_HONEYPOT . '" value="" tabindex="-1" autocomplete="off"></div>'
        . '<input type="hidden" name="form_ts" value="' . e($ts . '.' . substr(app_sign($form . '|' . $ts), 0, 32)) . '">';
}

/**
 * Check the honeypot and timing. Returns null when the submission looks human,
 * otherwise a short reason (logged, never shown in detail).
 */
function form_guard_problem(string $form, array $input, ?int $now = null): ?string
{
    $now ??= time();
    if (trim((string) ($input[FORM_HONEYPOT] ?? '')) !== '') {
        return 'honeypot';
    }
    $token = (string) ($input['form_ts'] ?? '');
    if (!preg_match('/^(\d{9,11})\.([a-f0-9]{32})$/', $token, $m)) {
        return 'missing-time';
    }
    if (!hash_equals(substr(app_sign($form . '|' . $m[1]), 0, 32), $m[2])) {
        return 'bad-time';
    }
    $age = $now - (int) $m[1];
    if ($age < FORM_MIN_SECONDS) {
        return 'too-fast';
    }
    if ($age > FORM_MAX_SECONDS) {
        return 'stale';
    }
    return null;
}

/** Per-IP rate limit for a public form; records the attempt. True when over the limit. */
function form_rate_limited(string $form, int $max, int $windowSeconds): bool
{
    $bucket = 'form:' . $form . ':' . client_ip();
    if (rate_limited($bucket, $max, $windowSeconds)) {
        return true;
    }
    rate_hit($bucket);
    return false;
}
