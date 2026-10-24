<?php
declare(strict_types=1);

/*
 * Provider webhooks for bounces and complaints (Addendum A5): Amazon SES via
 * SNS, Mailgun, Postmark, SendGrid, Brevo. Each is off until configured in
 * Email settings, and every request is authenticated before anything is
 * recorded:
 *   SES/SNS  - SNS message signature (SignatureVersion 1 = SHA1, 2 = SHA256)
 *              checked against the signing certificate, which is only fetched
 *              over HTTPS from sns.<region>.amazonaws.com; the topic ARN must
 *              be on the configured allow-list.
 *   Mailgun  - HMAC-SHA256 of timestamp+token with the webhook signing key,
 *              timestamp within 15 minutes.
 *   Postmark - HTTP basic auth (credentials put in the webhook URL) or a
 *              token in the X-Gather-Webhook-Token custom header.
 *   SendGrid - Signed Event Webhook: ECDSA P-256 / SHA-256 signature of
 *              timestamp+body with the verification key, timestamp within
 *              15 minutes.
 *   Brevo    - bearer token (or basic auth with the token as password).
 * These endpoints are called by the providers' servers, without a session,
 * so they are exempt from CSRF (documented in tests/smoke_admin.php); the
 * signature or token is the credential. Replays are harmless: every event
 * carries a provider id stored as email_events.ext_id.
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
    $events = match ($provider) {
        'ses' => webhook_ses($body, $cfg, $fetch ?? 'webhook_https_get'),
        'mailgun' => webhook_mailgun($body, bounce_secret('webhooks', 'mailgun_key'), $now),
        'postmark' => webhook_postmark($body, $server, $cfg),
        'sendgrid' => webhook_sendgrid($body, $server, (string) $cfg['sendgrid_key'], $now),
        'brevo' => webhook_brevo($body, $server),
    };
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
    $type = (string) ($m['Type'] ?? '');
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

/** Verify an SNS message signature. $fetch returns the PEM certificate for an allow-listed URL. */
function webhook_sns_verify(array $m, callable $fetch): bool
{
    $url = (string) ($m['SigningCertURL'] ?? $m['SigningCertUrl'] ?? '');
    if (!webhook_sns_url_ok($url) || !str_ends_with(strtolower((string) parse_url($url, PHP_URL_PATH)), '.pem')) {
        return false;
    }
    $string = webhook_sns_string_to_sign($m);
    $sig = base64_decode((string) ($m['Signature'] ?? ''), true);
    $algo = match ((string) ($m['SignatureVersion'] ?? '')) {
        '1' => OPENSSL_ALGO_SHA1,
        '2' => OPENSSL_ALGO_SHA256,
        default => null,
    };
    if ($string === null || $sig === false || $sig === '' || $algo === null) {
        return false;
    }
    $cacheFile = BASE . '/storage/cache/sns-' . hash('sha256', $url) . '.pem';
    $pem = is_file($cacheFile) ? (string) file_get_contents($cacheFile) : '';
    if ($pem === '') {
        $pem = (string) ($fetch($url) ?? '');
        if (!str_contains($pem, 'BEGIN CERTIFICATE')) {
            return false;
        }
        file_put_contents($cacheFile, $pem);
    }
    $key = openssl_pkey_get_public($pem);
    return $key !== false && openssl_verify($string, $sig, $key, $algo) === 1;
}

/** @return list<array>|int|string */
function webhook_ses(string $body, array $cfg, callable $fetch): array|int|string
{
    $m = json_decode($body, true);
    if (!is_array($m)) {
        return 400;
    }
    if (!webhook_sns_verify($m, $fetch)) {
        return 403;
    }
    $topics = array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $cfg['ses_topics']) ?: []));
    if (!in_array((string) ($m['TopicArn'] ?? ''), $topics, true)) {
        return 403;
    }
    if ($m['Type'] === 'SubscriptionConfirmation') {
        $url = (string) ($m['SubscribeURL'] ?? '');
        if (!webhook_sns_url_ok($url) || $fetch($url) === null) {
            return 400;
        }
        return 'Subscription confirmed';
    }
    if ($m['Type'] !== 'Notification') {
        return 'Ignored';
    }
    $n = json_decode((string) $m['Message'], true);
    if (!is_array($n)) {
        return 400;
    }
    $kind = (string) ($n['notificationType'] ?? $n['eventType'] ?? '');
    $mail = is_array($n['mail'] ?? null) ? $n['mail'] : [];
    $tagText = implode(' ', array_merge(
        array_map('strval', (array) ($mail['tags']['gather_id'] ?? [])),
        array_map(static fn ($h): string => is_array($h) && in_array(strtolower((string) ($h['name'] ?? '')), ['x-gather-message-id', 'message-id'], true) ? (string) ($h['value'] ?? '') : '', (array) ($mail['headers'] ?? []))
    ));
    $events = [];
    if ($kind === 'Bounce' && is_array($n['bounce'] ?? null)) {
        $b = $n['bounce'];
        $sub = (string) ($b['bounceSubType'] ?? '');
        $type = match ((string) ($b['bounceType'] ?? '')) {
            'Permanent' => 'hard',
            default => $sub === 'ContentRejected' ? 'block' : 'soft',
        };
        foreach ((array) ($b['bouncedRecipients'] ?? []) as $i => $r) {
            if (!is_array($r)) {
                continue;
            }
            $diag = (string) ($r['diagnosticCode'] ?? '');
            $events[] = ['email' => (string) ($r['emailAddress'] ?? ''), 'type' => $type, 'status' => (string) ($r['status'] ?? ''),
                'detail' => trim($sub . ' ' . $diag), 'tag' => count((array) $b['bouncedRecipients']) === 1 ? $tagText : '',
                'ext_id' => 'ses:' . (string) ($b['feedbackId'] ?? $m['MessageId']) . ':' . $i];
        }
    } elseif ($kind === 'Complaint' && is_array($n['complaint'] ?? null)) {
        $c = $n['complaint'];
        if (($c['complaintFeedbackType'] ?? '') === 'not-spam') {
            return 'Ignored';
        }
        foreach ((array) ($c['complainedRecipients'] ?? []) as $i => $r) {
            $events[] = ['email' => (string) ($r['emailAddress'] ?? ''), 'type' => 'complaint', 'detail' => 'Complaint: ' . (string) ($c['complaintFeedbackType'] ?? 'abuse'),
                'tag' => $tagText, 'ext_id' => 'ses:' . (string) ($c['feedbackId'] ?? $m['MessageId']) . ':' . $i];
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
    $ts = (string) ($s['timestamp'] ?? '');
    $expected = hash_hmac('sha256', $ts . (string) ($s['token'] ?? ''), $key);
    if ($key === '' || !ctype_digit($ts) || abs($now - (int) $ts) > WEBHOOK_MAX_AGE || !hash_equals($expected, strtolower((string) ($s['signature'] ?? '')))) {
        return 401;
    }
    $e = $p['event-data'];
    $event = (string) ($e['event'] ?? '');
    $tag = trim((string) ($e['user-variables']['gather_id'] ?? '') . ' ' . (string) ($e['message']['headers']['message-id'] ?? ''));
    $ext = 'mailgun:' . (string) ($e['id'] ?? hash('sha256', $body));
    if ($event === 'complained') {
        return [['email' => (string) ($e['recipient'] ?? ''), 'type' => 'complaint', 'detail' => 'Complaint (Mailgun)', 'tag' => $tag, 'ext_id' => $ext]];
    }
    if ($event !== 'failed' || ($e['severity'] ?? '') !== 'permanent') {
        return 'Ignored'; // temporary failures: Mailgun keeps retrying and reports the final outcome
    }
    $ds = is_array($e['delivery-status'] ?? null) ? $e['delivery-status'] : [];
    $text = trim((string) ($ds['message'] ?? '') . ' ' . (string) ($ds['description'] ?? '') . ' ' . (string) ($e['reason'] ?? ''));
    $status = (string) ($ds['enhanced-code'] ?? '');
    $type = bounce_classify($status, (int) ($ds['code'] ?? 0), $text);
    return [['email' => (string) ($e['recipient'] ?? ''), 'type' => in_array($type, ['hard', 'soft', 'block'], true) ? $type : 'hard', 'status' => $status,
        'smtp_code' => (int) ($ds['code'] ?? 0) ?: null, 'detail' => $text, 'tag' => $tag, 'ext_id' => $ext]];
}

// ---- Postmark --------------------------------------------------------------------------

/** @return list<array>|int|string */
function webhook_postmark(string $body, array $server, array $cfg): array|int|string
{
    $auth = webhook_basic_auth($server);
    $userOk = $auth !== null && webhook_secret_equals((string) $cfg['postmark_user'], $auth[0]) && webhook_secret_equals(secret_open((string) $cfg['postmark_password']), $auth[1]);
    $tokenOk = webhook_secret_equals(secret_open((string) $cfg['postmark_token']), (string) ($server['HTTP_X_GATHER_WEBHOOK_TOKEN'] ?? ''));
    if (!$userOk && !$tokenOk) {
        return 401;
    }
    $p = json_decode($body, true);
    if (!is_array($p)) {
        return 400;
    }
    $record = (string) ($p['RecordType'] ?? '');
    $ptype = (string) ($p['Type'] ?? '');
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
    $tag = trim((string) ($p['Metadata']['gather-id'] ?? '') . ' ' . (string) ($p['MessageID'] ?? ''));
    return [['email' => (string) ($p['Email'] ?? ''), 'type' => $type, 'detail' => trim($ptype . ': ' . (string) ($p['Description'] ?? '') . ' ' . (string) ($p['Details'] ?? '')),
        'tag' => $tag, 'ext_id' => 'postmark:' . (string) ($p['ID'] ?? hash('sha256', $body))]];
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
    $sig = base64_decode((string) ($server['HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_SIGNATURE'] ?? ''), true);
    $ts = (string) ($server['HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_TIMESTAMP'] ?? '');
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
        $event = (string) ($e['event'] ?? '');
        $reason = (string) ($e['reason'] ?? '');
        $type = match ($event) {
            'spamreport' => 'complaint',
            'bounce' => ($e['type'] ?? '') === 'blocked' ? (bounce_classify((string) ($e['status'] ?? ''), 0, $reason) === 'block' ? 'block' : 'soft') : 'hard',
            'dropped' => str_contains($reason, 'Bounced') ? 'hard' : (str_contains($reason, 'Spam Report') ? 'complaint' : null),
            default => null,
        };
        if ($type === null) {
            continue;
        }
        if ($event === 'bounce' && $type === 'hard') {
            $c = bounce_classify((string) ($e['status'] ?? ''), 0, $reason);
            $type = in_array($c, ['hard', 'soft', 'block'], true) ? $c : 'hard';
        }
        $events[] = ['email' => (string) ($e['email'] ?? ''), 'type' => $type, 'status' => (string) ($e['status'] ?? ''), 'detail' => trim($event . ': ' . $reason),
            'tag' => (string) ($e['gather_id'] ?? ''), 'ext_id' => 'sendgrid:' . (string) ($e['sg_event_id'] ?? hash('sha256', json_encode($e) ?: ''))];
    }
    return $events === [] ? 'Ignored' : $events;
}

// ---- Brevo -----------------------------------------------------------------------------

/** @return list<array>|int|string */
function webhook_brevo(string $body, array $server): array|int|string
{
    $token = bounce_secret('webhooks', 'brevo_token');
    $h = (string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    $bearer = preg_match('/^Bearer\s+(\S+)$/i', $h, $m) ? $m[1] : '';
    $basic = webhook_basic_auth($server);
    if (!webhook_secret_equals($token, $bearer) && !($basic !== null && webhook_secret_equals($token, $basic[1]))) {
        return 401;
    }
    $p = json_decode($body, true);
    if (!is_array($p)) {
        return 400;
    }
    $event = (string) ($p['event'] ?? '');
    $type = match ($event) {
        'hard_bounce', 'invalid_email' => 'hard',
        'soft_bounce' => 'soft',
        'spam', 'complaint' => 'complaint',
        default => null,
    };
    if ($type === null) {
        return 'Ignored';
    }
    $tag = trim((string) ($p['X-Mailin-custom'] ?? '') . ' ' . (string) ($p['message-id'] ?? ''));
    return [['email' => (string) ($p['email'] ?? ''), 'type' => $type, 'detail' => trim($event . ': ' . (string) ($p['reason'] ?? '')), 'tag' => $tag,
        'ext_id' => 'brevo:' . (string) ($p['id'] ?? '') . ':' . $event . ':' . (string) ($p['ts_event'] ?? $p['ts'] ?? hash('sha256', $body))]];
}
