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
