<?php
declare(strict_types=1);

/** Path of the active config file (GATHER_CONFIG lets tests point at their own). */
function config_path(): string
{
    $env = getenv('GATHER_CONFIG');
    return is_string($env) && $env !== '' ? $env : BASE . '/config.php';
}

/** @param array<string,mixed>|null $replace */
function config_all(?array $replace = null): array
{
    static $config = null;
    if ($replace !== null) {
        $config = $replace;
    }
    if ($config === null) {
        $file = config_path();
        $config = is_file($file) ? (array) require $file : [];
    }
    return $config;
}

/** Read a config value by dot path, e.g. config('db.host'). */
function config(string $key, mixed $default = null): mixed
{
    $value = config_all();
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function app_installed(): bool
{
    return config('installed') === true;
}

function base_url(string $path = ''): string
{
    return rtrim((string) config('base_url', ''), '/') . $path;
}

/** Shortest accepted app_key (the installer writes 64 hex characters). */
const APP_KEY_MIN_LENGTH = 32;

/** Why the configured app_key cannot be used, or null when it is fine. */
function app_key_problem(?string $key = null): ?string
{
    $key ??= is_string(config('app_key')) ? (string) config('app_key') : '';
    if ($key === '' || $key === 'change-me') {
        return 'app_key in config.php is not set. Generate one with: php -r \'echo bin2hex(random_bytes(32));\'';
    }
    if (strlen($key) < APP_KEY_MIN_LENGTH) {
        return 'app_key in config.php is too short (at least ' . APP_KEY_MIN_LENGTH . ' characters; the installer writes 64).';
    }
    return null;
}

/**
 * A key for one purpose, derived from app_key with HKDF, so the key that
 * signs links is never the key that encrypts stored secrets.
 * Throws when app_key is missing or weak: there is no fallback key.
 */
function app_subkey(string $purpose): string
{
    if (($problem = app_key_problem()) !== null) {
        throw new RuntimeException($problem);
    }
    return hash_hkdf('sha256', (string) config('app_key'), 32, 'gather:' . $purpose);
}
