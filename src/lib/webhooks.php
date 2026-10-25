<?php
declare(strict_types=1);

/*
 * Provider webhooks for bounces and complaints (Addendum A5): Amazon SES via
 * SNS, Mailgun, Postmark, SendGrid, Brevo. Each is off until configured in
 * Email settings, and every request is authenticated before anything is
 * recorded:
 *   SES/SNS  - the topic ARN must be on the configured allow-list (checked
 *              first); SNS message signature (SignatureVersion 1 = SHA1,
 *              2 = SHA256) checked against the signing certificate, which is
 *              only fetched over HTTPS from
 *              sns.<region>.amazonaws.com/SimpleNotificationService-<hex>.pem
 *              (no query), cached 7 days (20 files at most), at most 10
 *              fetches an hour.
 *   Mailgun  - HMAC-SHA256 of timestamp+token with the webhook signing key,
 *              timestamp within 15 minutes, each token used once.
 *   Postmark - HTTP basic auth (credentials put in the webhook URL) or a
 *              token in the X-Gather-Webhook-Token custom header.
 *   SendGrid - Signed Event Webhook: ECDSA P-256 / SHA-256 signature of
 *              timestamp+body with the verification key, timestamp within
 *              15 minutes.
 *   Brevo    - bearer token (or basic auth with the token as password).
 * These endpoints are called by the providers' servers, without a session,
 * so they are exempt from CSRF (documented in tests/smoke_admin.php); the
 * signature or token is the credential. Replays are harmless: every event
 * carries a provider id stored as email_events.ext_id. Fields are type
 * checked (webhook_field()): an array where text is expected answers 400,
 * which the controller counts towards the failure limit like any non-2xx.
 */

const WEBHOOK_PROVIDERS = ['ses', 'mailgun', 'postmark', 'sendgrid', 'brevo'];
const WEBHOOK_MAX_AGE = 900;
const WEBHOOK_MAX_BODY = 1_048_576;

/**
 * Authenticate and process one webhook request.
 * @param array $server $_SERVER-like array (headers as HTTP_*)
 * @param ?callable $fetch fn(string $httpsUrl): ?string for SNS certificates and subscription confirmation (tests inject one)
 * @return array{status:int, message:string, recorded:int}
 */
function webhook_handle(string $provider, string $body, array $server, ?callable $fetch = null, ?int $now = null): array
{
    $now ??= time();
    $cfg = bounce_settings()['webhooks'];
    if (!in_array($provider, WEBHOOK_PROVIDERS, true) || !webhook_enabled($provider, $cfg)) {
        return ['status' => 404, 'message' => 'Not found', 'recorded' => 0];
    }
    if (strlen($body) > WEBHOOK_MAX_BODY) {
        return ['status' => 413, 'message' => 'Too large', 'recorded' => 0];
    }
    try {
        $events = match ($provider) {
            'ses' => webhook_ses($body, $cfg, $fetch ?? 'webhook_https_get', $now),
            'mailgun' => webhook_mailgun($body, bounce_secret('webhooks', 'mailgun_key'), $now),
            'postmark' => webhook_postmark($body, $server, $cfg),
            'sendgrid' => webhook_sendgrid($body, $server, (string) $cfg['sendgrid_key'], $now),
            'brevo' => webhook_brevo($body, $server),
        };
    } catch (WebhookFieldException) {
        $events = 400; // a field of the wrong type (an array where text was expected)
    }
    if (is_int($events)) {
        return ['status' => $events, 'message' => $events === 401 || $events === 403 ? 'Signature or credentials not valid' : 'Bad request', 'recorded' => 0];
    }
    if (is_string($events)) {
        return ['status' => 200, 'message' => $events, 'recorded' => 0];
    }
    $n = 0;
    foreach ($events as $ev) {
        $n += bounce_record($ev + ['source' => $provider, 'now' => $now]) === 'recorded' ? 1 : 0;
    }
    return ['status' => 200, 'message' => 'OK', 'recorded' => $n];
}

/** A JSON field that must be text (or a number): arrays and objects are refused. */
final class WebhookFieldException extends InvalidArgumentException
{
}

function webhook_field(mixed $v): string
{
    if ($v === null) {
        return '';
    }
    if (is_string($v) || is_int($v) || is_float($v)) {
        return (string) $v;
    }
    if (is_bool($v)) {
        return $v ? '1' : '';
    }
    throw new WebhookFieldException('webhook field is not text');
}

function webhook_enabled(string $provider, array $cfg): bool
{
    return match ($provider) {
        'ses' => $cfg['ses'] === true && trim((string) $cfg['ses_topics']) !== '',
        'mailgun' => $cfg['mailgun_key'] !== '',
        'postmark' => ($cfg['postmark_user'] !== '' && $cfg['postmark_password'] !== '') || $cfg['postmark_token'] !== '',
        'sendgrid' => $cfg['sendgrid_key'] !== '',
        'brevo' => $cfg['brevo_token'] !== '',
        default => false,
    };
}

/** HTTPS GET with certificate verification (used for SNS only, to allow-listed hosts). */
function webhook_https_get(string $url): ?string
{
    if (!webhook_sns_url_ok($url)) {
        return null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'follow_location' => 0, 'ignore_errors' => false], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    set_error_handler(static fn (): bool => true);
    try {
        $out = file_get_contents($url, false, $ctx, 0, 65536);
    } finally {
        restore_error_handler();
    }
    return $out === false ? null : $out;
}

/** Only https://sns.<region>.amazonaws.com(.cn)/… with no port, user or fragment. */
function webhook_sns_url_ok(string $url): bool
{
    $p = parse_url($url);
    if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || isset($p['port']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])) {
        return false;
    }
    return (bool) preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/', strtolower((string) ($p['host'] ?? '')));
}

/** Basic-auth credentials from the request (PHP_AUTH_* or the Authorization header). @return array{0:string,1:string}|null */
function webhook_basic_auth(array $server): ?array
{
    if (isset($server['PHP_AUTH_USER'])) {
        return [(string) $server['PHP_AUTH_USER'], (string) ($server['PHP_AUTH_PW'] ?? '')];
    }
    $h = (string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)$/i', $h, $m)) {
        $d = base64_decode($m[1], true);
        if ($d !== false && str_contains($d, ':')) {
            [$u, $pw] = explode(':', $d, 2);
            return [$u, $pw];
        }
    }
    return null;
}

/** Constant-time comparison that is false for an empty expected value. */
function webhook_secret_equals(string $expected, string $given): bool
{
    return $expected !== '' && hash_equals($expected, $given);
}

// ---- Amazon SES via SNS --------------------------------------------------------------

/** The string SNS signs (field order fixed by AWS). */
function webhook_sns_string_to_sign(array $m): ?string
{
    $type = webhook_field($m['Type'] ?? '');
    $keys = $type === 'Notification'
        ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
        : (in_array($type, ['SubscriptionConfirmation', 'UnsubscribeConfirmation'], true) ? ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'] : null);
    if ($keys === null) {
        return null;
    }
    $out = '';
    foreach ($keys as $k) {
        if ($k === 'Subject' && !isset($m['Subject'])) {
            continue;
        }
        if (!isset($m[$k]) || !is_string($m[$k])) {
            return null;
        }
        $out .= $k . "\n" . $m[$k] . "\n";
    }
    return $out;
}

/** SNS signing certificates fetched per hour at most (cache misses), across all requests. */
const WEBHOOK_SNS_FETCH_PER_HOUR = 10;
const WEBHOOK_SNS_CACHE_MAX = 20;
const WEBHOOK_SNS_CACHE_TTL = 7 * 86400;

/**
 * The normalised signing-certificate URL, or null: https, an
 * sns.<region>.amazonaws.com(.cn) host, no port/user/query/fragment, and the
 * path AWS uses (/SimpleNotificationService-<hex>.pem).
 */
function webhook_sns_cert_url(string $url): ?string
{
    if (!webhook_sns_url_ok($url) || str_contains($url, '?') || str_contains($url, '#')) {
        return null;
    }
    $path = (string) parse_url($url, PHP_URL_PATH);
    if (!preg_match('#^/SimpleNotificationService-[a-f0-9]{16,64}\.pem$#', $path)) {
        return null;
    }
    return 'https://' . strtolower((string) parse_url($url, PHP_URL_HOST)) . $path;
}

/** Folder for cached SNS certificates. */
function webhook_sns_cache_dir(): string
{
    return BASE . '/storage/cache';
}

/**
 * The PEM for a normalised certificate URL: from the cache (7 days), or
 * fetched (rate limited across all requests); the cache keeps at most
 * WEBHOOK_SNS_CACHE_MAX files.
 */
function webhook_sns_cert(string $url, callable $fetch, int $now): ?string
{
    $dir = webhook_sns_cache_dir();
    $file = $dir . '/sns-' . hash('sha256', $url) . '.pem';
    if (is_file($file) && (int) filemtime($file) > $now - WEBHOOK_SNS_CACHE_TTL) {
        $pem = (string) file_get_contents($file);
        if (str_contains($pem, 'BEGIN CERTIFICATE')) {
            return $pem;
        }
    }
    if (rate_limited('sns-cert-fetch', WEBHOOK_SNS_FETCH_PER_HOUR, 3600)) {
        error_log('SNS certificate not fetched: hourly limit reached');
        return null;
    }
    rate_hit('sns-cert-fetch');
    $pem = (string) ($fetch($url) ?? '');
    if (!str_contains($pem, 'BEGIN CERTIFICATE') || openssl_pkey_get_public($pem) === false) {
        return null;
    }
    if (is_dir($dir) || mkdir($dir, 0770, true)) {
        $cached = glob($dir . '/sns-*.pem') ?: [];
        usort($cached, static fn (string $a, string $b): int => filemtime($a) <=> filemtime($b));
        while (count($cached) >= WEBHOOK_SNS_CACHE_MAX) {
            unlink((string) array_shift($cached));
        }
        file_put_contents($file, $pem);
    }
    return $pem;
}

/**
 * Verify an SNS message signature. Everything that can be checked without
 * the certificate is checked first (URL shape, fields, signature encoding);
 * $fetch returns the PEM certificate for an allow-listed URL.
 */
function webhook_sns_verify(array $m, callable $fetch, ?int $now = null): bool
{
    $url = webhook_sns_cert_url(webhook_field($m['SigningCertURL'] ?? $m['SigningCertUrl'] ?? ''));
    if ($url === null) {
        return false;
    }
    $string = webhook_sns_string_to_sign($m);
    $sig = base64_decode(webhook_field($m['Signature'] ?? ''), true);
    $algo = match (webhook_field($m['SignatureVersion'] ?? '')) {
        '1' => OPENSSL_ALGO_SHA1,
        '2' => OPENSSL_ALGO_SHA256,
        default => null,
    };
    if ($string === null || $sig === false || $sig === '' || $algo === null) {
        return false;
    }
    $pem = webhook_sns_cert($url, $fetch, $now ?? time());
    $key = $pem !== null ? openssl_pkey_get_public($pem) : false;
    return $key !== false && openssl_verify($string, $sig, $key, $algo) === 1;
}

/** @return list<array>|int|string */
function webhook_ses(string $body, array $cfg, callable $fetch, ?int $now = null): array|int|string
{
    $m = json_decode($body, true);
    if (!is_array($m)) {
        return 400;
    }
    // The topic allow-list first: a message for another topic never causes a certificate fetch.
    $topics = array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $cfg['ses_topics']) ?: []));
    if (!in_array(webhook_field($m['TopicArn'] ?? ''), $topics, true)) {
        return 403;
    }
    if (!webhook_sns_verify($m, $fetch, $now)) {
        return 403;
    }
    if ($m['Type'] === 'SubscriptionConfirmation') {
        $url = webhook_field($m['SubscribeURL'] ?? '');
        if (!webhook_sns_url_ok($url) || $fetch($url) === null) {
            return 400;
        }
        return 'Subscription confirmed';
    }
    if ($m['Type'] !== 'Notification') {
        return 'Ignored';
    }
    $n = json_decode(webhook_field($m['Message']), true);
    if (!is_array($n)) {
        return 400;
    }
    $kind = webhook_field($n['notificationType'] ?? $n['eventType'] ?? '');
    $mail = is_array($n['mail'] ?? null) ? $n['mail'] : [];
    $tagText = implode(' ', array_merge(
        array_map('webhook_field', is_array($mail['tags']['gather_id'] ?? null) ? array_values($mail['tags']['gather_id']) : [$mail['tags']['gather_id'] ?? null]),
        array_map(static fn ($h): string => is_array($h) && in_array(strtolower(webhook_field($h['name'] ?? '')), ['x-gather-message-id', 'message-id'], true) ? webhook_field($h['value'] ?? '') : '', (array) ($mail['headers'] ?? []))
    ));
    $events = [];
    if ($kind === 'Bounce' && is_array($n['bounce'] ?? null)) {
        $b = $n['bounce'];
        $sub = webhook_field($b['bounceSubType'] ?? '');
        $type = match (webhook_field($b['bounceType'] ?? '')) {
            'Permanent' => 'hard',
            default => $sub === 'ContentRejected' ? 'block' : 'soft',
        };
        foreach ((array) ($b['bouncedRecipients'] ?? []) as $i => $r) {
            if (!is_array($r)) {
                continue;
            }
            $diag = webhook_field($r['diagnosticCode'] ?? '');
            $events[] = ['email' => webhook_field($r['emailAddress'] ?? ''), 'type' => $type, 'status' => webhook_field($r['status'] ?? ''),
                'detail' => trim($sub . ' ' . $diag), 'tag' => count((array) $b['bouncedRecipients']) === 1 ? $tagText : '',
                'ext_id' => 'ses:' . webhook_field($b['feedbackId'] ?? $m['MessageId']) . ':' . $i];
        }
    } elseif ($kind === 'Complaint' && is_array($n['complaint'] ?? null)) {
        $c = $n['complaint'];
        if (($c['complaintFeedbackType'] ?? '') === 'not-spam') {
            return 'Ignored';
        }
        foreach ((array) ($c['complainedRecipients'] ?? []) as $i => $r) {
            $events[] = ['email' => webhook_field($r['emailAddress'] ?? ''), 'type' => 'complaint', 'detail' => 'Complaint: ' . webhook_field($c['complaintFeedbackType'] ?? 'abuse'),
                'tag' => $tagText, 'ext_id' => 'ses:' . webhook_field($c['feedbackId'] ?? $m['MessageId']) . ':' . $i];
        }
    } else {
        return 'Ignored';
    }
    return $events;
}

// ---- Mailgun ---------------------------------------------------------------------------

/** @return list<array>|int|string */
function webhook_mailgun(string $body, string $key, int $now): array|int|string
{
    $p = json_decode($body, true);
    if (!is_array($p) || !is_array($p['signature'] ?? null) || !is_array($p['event-data'] ?? null)) {
        return 400;
    }
    $s = $p['signature'];
    $ts = webhook_field($s['timestamp'] ?? '');
    $expected = hash_hmac('sha256', $ts . webhook_field($s['token'] ?? ''), $key);
    if ($key === '' || !ctype_digit($ts) || abs($now - (int) $ts) > WEBHOOK_MAX_AGE || !hash_equals($expected, strtolower(webhook_field($s['signature'] ?? '')))) {
        return 401;
    }
    // Each signed token is accepted once within the replay window (rate_hits is pruned daily by cron).
    $tokenBucket = 'mailgun-token:' . hash('sha256', webhook_field($s['token'] ?? ''));
    if (rate_limited($tokenBucket, 1, 2 * WEBHOOK_MAX_AGE)) {
        return 401;
    }
    rate_hit($tokenBucket);
    $e = $p['event-data'];
    $event = webhook_field($e['event'] ?? '');
    $tag = trim(webhook_field($e['user-variables']['gather_id'] ?? '') . ' ' . webhook_field($e['message']['headers']['message-id'] ?? ''));
    $ext = 'mailgun:' . (isset($e['id']) ? webhook_field($e['id']) : hash('sha256', $body));
    if ($event === 'complained') {
        return [['email' => webhook_field($e['recipient'] ?? ''), 'type' => 'complaint', 'detail' => 'Complaint (Mailgun)', 'tag' => $tag, 'ext_id' => $ext]];
    }
    if ($event !== 'failed' || ($e['severity'] ?? '') !== 'permanent') {
        return 'Ignored'; // temporary failures: Mailgun keeps retrying and reports the final outcome
    }
    $ds = is_array($e['delivery-status'] ?? null) ? $e['delivery-status'] : [];
    $text = trim(webhook_field($ds['message'] ?? '') . ' ' . webhook_field($ds['description'] ?? '') . ' ' . webhook_field($e['reason'] ?? ''));
    $status = webhook_field($ds['enhanced-code'] ?? '');
    $code = (int) webhook_field($ds['code'] ?? 0);
    $type = bounce_classify($status, $code, $text);
    return [['email' => webhook_field($e['recipient'] ?? ''), 'type' => in_array($type, ['hard', 'soft', 'block'], true) ? $type : 'hard', 'status' => $status,
        'smtp_code' => $code ?: null, 'detail' => $text, 'tag' => $tag, 'ext_id' => $ext]];
}

// ---- Postmark --------------------------------------------------------------------------

/** @return list<array>|int|string */
function webhook_postmark(string $body, array $server, array $cfg): array|int|string
{
    $auth = webhook_basic_auth($server);
    $userOk = $auth !== null && webhook_secret_equals((string) $cfg['postmark_user'], $auth[0]) && webhook_secret_equals(secret_open((string) $cfg['postmark_password']), $auth[1]);
    $tokenOk = webhook_secret_equals(secret_open((string) $cfg['postmark_token']), webhook_field($server['HTTP_X_GATHER_WEBHOOK_TOKEN'] ?? ''));
    if (!$userOk && !$tokenOk) {
        return 401;
    }
    $p = json_decode($body, true);
    if (!is_array($p)) {
        return 400;
    }
    $record = webhook_field($p['RecordType'] ?? '');
    $ptype = webhook_field($p['Type'] ?? '');
    $type = match (true) {
        $record === 'SpamComplaint', in_array($ptype, ['SpamNotification', 'SpamComplaint'], true) => 'complaint',
        in_array($ptype, ['HardBounce', 'BadEmailAddress', 'ManuallyDeactivated'], true) => 'hard',
        in_array($ptype, ['SoftBounce', 'Transient', 'DnsError'], true) => 'soft',
        $ptype === 'Blocked' => 'block',
        default => null,
    };
    if ($type === null || !in_array($record, ['Bounce', 'SpamComplaint'], true)) {
        return 'Ignored';
    }
    $tag = trim(webhook_field($p['Metadata']['gather-id'] ?? '') . ' ' . webhook_field($p['MessageID'] ?? ''));
    return [['email' => webhook_field($p['Email'] ?? ''), 'type' => $type, 'detail' => trim($ptype . ': ' . webhook_field($p['Description'] ?? '') . ' ' . webhook_field($p['Details'] ?? '')),
        'tag' => $tag, 'ext_id' => 'postmark:' . (isset($p['ID']) ? webhook_field($p['ID']) : hash('sha256', $body))]];
}

// ---- SendGrid --------------------------------------------------------------------------

/** PEM from SendGrid's base64 DER verification key (or a PEM pasted as is). */
function webhook_sendgrid_pem(string $key): string
{
    $key = trim($key);
    if (str_contains($key, 'BEGIN PUBLIC KEY')) {
        return $key;
    }
    $der = preg_replace('/\s+/', '', $key) ?? '';
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split($der, 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** @return list<array>|int|string */
function webhook_sendgrid(string $body, array $server, string $key, int $now): array|int|string
{
    $sig = base64_decode(webhook_field($server['HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_SIGNATURE'] ?? ''), true);
    $ts = webhook_field($server['HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_TIMESTAMP'] ?? '');
    $pub = $key !== '' ? openssl_pkey_get_public(webhook_sendgrid_pem($key)) : false;
    if ($sig === false || $sig === '' || !ctype_digit($ts) || abs($now - (int) $ts) > WEBHOOK_MAX_AGE || $pub === false
        || openssl_verify($ts . $body, $sig, $pub, OPENSSL_ALGO_SHA256) !== 1) {
        return 401;
    }
    $list = json_decode($body, true);
    if (!is_array($list) || !array_is_list($list)) {
        return 400;
    }
    $events = [];
    foreach (array_slice($list, 0, 1000) as $e) {
        if (!is_array($e)) {
            continue;
        }
        $event = webhook_field($e['event'] ?? '');
        $reason = webhook_field($e['reason'] ?? '');
        $type = match ($event) {
            'spamreport' => 'complaint',
            'bounce' => ($e['type'] ?? '') === 'blocked' ? (bounce_classify(webhook_field($e['status'] ?? ''), 0, $reason) === 'block' ? 'block' : 'soft') : 'hard',
            'dropped' => str_contains($reason, 'Bounced') ? 'hard' : (str_contains($reason, 'Spam Report') ? 'complaint' : null),
            default => null,
        };
        if ($type === null) {
            continue;
        }
        if ($event === 'bounce' && $type === 'hard') {
            $c = bounce_classify(webhook_field($e['status'] ?? ''), 0, $reason);
            $type = in_array($c, ['hard', 'soft', 'block'], true) ? $c : 'hard';
        }
        $events[] = ['email' => webhook_field($e['email'] ?? ''), 'type' => $type, 'status' => webhook_field($e['status'] ?? ''), 'detail' => trim($event . ': ' . $reason),
            'tag' => webhook_field($e['gather_id'] ?? ''), 'ext_id' => 'sendgrid:' . (isset($e['sg_event_id']) ? webhook_field($e['sg_event_id']) : hash('sha256', json_encode($e) ?: ''))];
    }
    return $events === [] ? 'Ignored' : $events;
}

// ---- Brevo -----------------------------------------------------------------------------

/** @return list<array>|int|string */
function webhook_brevo(string $body, array $server): array|int|string
{
    $token = bounce_secret('webhooks', 'brevo_token');
    $h = webhook_field($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    $bearer = preg_match('/^Bearer\s+(\S+)$/i', $h, $m) ? $m[1] : '';
    $basic = webhook_basic_auth($server);
    if (!webhook_secret_equals($token, $bearer) && !($basic !== null && webhook_secret_equals($token, $basic[1]))) {
        return 401;
    }
    $p = json_decode($body, true);
    if (!is_array($p)) {
        return 400;
    }
    $event = webhook_field($p['event'] ?? '');
    $type = match ($event) {
        'hard_bounce', 'invalid_email' => 'hard',
        'soft_bounce' => 'soft',
        'spam', 'complaint' => 'complaint',
        default => null,
    };
    if ($type === null) {
        return 'Ignored';
    }
    $tag = trim(webhook_field($p['X-Mailin-custom'] ?? '') . ' ' . webhook_field($p['message-id'] ?? ''));
    return [['email' => webhook_field($p['email'] ?? ''), 'type' => $type, 'detail' => trim($event . ': ' . webhook_field($p['reason'] ?? '')), 'tag' => $tag,
        'ext_id' => 'brevo:' . webhook_field($p['id'] ?? '') . ':' . $event . ':' . (isset($p['ts_event']) || isset($p['ts']) ? webhook_field($p['ts_event'] ?? $p['ts']) : hash('sha256', $body))]];
}
