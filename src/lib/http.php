<?php
declare(strict_types=1);

use Core\HttpException;
use Core\Redirect;

function request_method(): string
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function request_path(): string
{
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    return \Core\Router::normalise(is_string($path) ? $path : '/');
}

function is_post(): bool
{
    return request_method() === 'POST';
}

/** Trimmed string from POST (or GET when $fromGet). Arrays are rejected. */
function input(string $key, string $default = '', bool $fromGet = false): string
{
    $src = $fromGet ? $_GET : $_POST;
    $v = $src[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function query_param(string $key, string $default = ''): string
{
    return input($key, $default, true);
}

function input_int(string $key, int $default = 0, bool $fromGet = false): int
{
    $v = input($key, '', $fromGet);
    return preg_match('/^-?\d{1,9}$/', $v) ? (int) $v : $default;
}

/** @return list<string> */
function input_list(string $key): array
{
    $v = $_POST[$key] ?? [];
    if (!is_array($v)) {
        return [];
    }
    return array_values(array_map(static fn ($x): string => is_string($x) ? trim($x) : '', $v));
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function abort(int $status, string $message = ''): never
{
    throw new HttpException($status, $message);
}

/** Only same-site relative paths are accepted; anything else falls back to $fallback. */
function safe_redirect_target(string $to, string $fallback = '/'): string
{
    if ($to === '' || !str_starts_with($to, '/') || str_starts_with($to, '//') || str_contains($to, '\\')
        || preg_match('/[\x00-\x1f]/', $to)) {
        return $fallback;
    }
    return $to;
}

function redirect(string $to, int $status = 303): never
{
    throw new Redirect(safe_redirect_target($to), $status);
}

function json_out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Store (when $message given) or take the flash message for the next request. */
function flash(?string $message = null, string $type = 'success'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }
    $v = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($v) ? $v : null;
}

/** Keep submitted values for re-showing a form after a redirect. */
function remember_input(array $values, array $errors): void
{
    unset($values['_csrf'], $values['password'], $values['password_confirm']);
    $_SESSION['old'] = ['values' => $values, 'errors' => $errors];
}

/** @return array{values:array, errors:array} */
function take_old_input(): array
{
    $v = $_SESSION['old'] ?? ['values' => [], 'errors' => []];
    unset($_SESSION['old']);
    return $v;
}

/** Simple sliding-window rate limit backed by the rate_hits table. */
function rate_limited(string $bucket, int $max, int $windowSeconds): bool
{
    $since = gmdate('Y-m-d H:i:s', time() - $windowSeconds);
    $count = (int) q_val('SELECT COUNT(*) FROM rate_hits WHERE bucket = ? AND hit_at > ?', [$bucket, $since]);
    return $count >= $max;
}

function rate_hit(string $bucket): void
{
    q('INSERT INTO rate_hits (bucket, hit_at) VALUES (?, UTC_TIMESTAMP())', [$bucket]);
}
