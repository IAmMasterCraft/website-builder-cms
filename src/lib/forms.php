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

/** HMAC with the signing key derived from app_key (throws when app_key is missing or weak). */
function app_sign(string $data): string
{
    return hash_hmac('sha256', $data, app_subkey('sign'));
}

/**
 * HMAC as signed before the keys were split (raw app_key). Only used to keep
 * accepting unsubscribe links in emails sent before that change.
 */
function app_sign_legacy(string $data): string
{
    if (app_key_problem() !== null) {
        throw new RuntimeException((string) app_key_problem());
    }
    return hash_hmac('sha256', $data, (string) config('app_key'));
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

/** Emails a visitor can cause to one address per day, by kind (contact copy, guest event confirmation, sign-up notice). */
const UNSOLICITED_PER_ADDRESS_DAY = ['contact_copy' => 2, 'event_guest' => 3, 'signup_existing' => 2];
/** All emails visitors can aim at addresses they type, per hour, across kinds. */
const UNSOLICITED_PER_HOUR = 60;

/**
 * May a public form send an email of $kind to an address the visitor typed?
 * Limits per address per day and a global hourly cap, so the site cannot be
 * used to flood someone's inbox. Records the send when allowed.
 */
function unsolicited_mail_allowed(string $kind, string $email): bool
{
    $perAddress = UNSOLICITED_PER_ADDRESS_DAY[$kind] ?? 1;
    $addressBucket = 'mailto:' . $kind . ':' . hash('sha256', strtolower(trim($email)));
    if (rate_limited($addressBucket, $perAddress, 86400) || rate_limited('mailto-all', UNSOLICITED_PER_HOUR, 3600)) {
        error_log('Unsolicited email (' . $kind . ') not sent: limit reached');
        return false;
    }
    rate_hit($addressBucket);
    rate_hit('mailto-all');
    return true;
}
