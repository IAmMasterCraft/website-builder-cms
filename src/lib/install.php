<?php
declare(strict_types=1);

/*
 * Installer helpers. The wizard (src/Install/Wizard.php) writes config.php
 * with 'installed' => false, runs the migrations, creates the first super
 * admin, sets the theme, optionally loads demo content and then rewrites the
 * config with 'installed' => true, which disables every /install route.
 */

/** Validate DB form input. @return array{0:array,1:array} [db config, errors] */
function install_db_input(array $input): array
{
    $db = [
        'host' => trim((string) ($input['db_host'] ?? '127.0.0.1')),
        'port' => (int) ($input['db_port'] ?? 3306),
        'name' => trim((string) ($input['db_name'] ?? '')),
        'user' => trim((string) ($input['db_user'] ?? '')),
        'pass' => (string) ($input['db_pass'] ?? ''),
    ];
    $errors = [];
    if (!preg_match('/^[A-Za-z0-9.\-]{1,190}$/', $db['host'])) {
        $errors['db_host'] = 'Enter a host name such as localhost or 127.0.0.1.';
    }
    if ($db['port'] < 1 || $db['port'] > 65535) {
        $errors['db_port'] = 'Enter a port between 1 and 65535.';
    }
    if (!preg_match('/^[A-Za-z0-9_\-$]{1,64}$/', $db['name'])) {
        $errors['db_name'] = 'Enter the database name (letters, numbers, _ or -).';
    }
    if ($db['user'] === '' || mb_strlen($db['user']) > 80) {
        $errors['db_user'] = 'Enter the database user.';
    }
    return [$db, $errors];
}

function install_detect_base_url(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host)) {
        $host = 'localhost';
    }
    return ($https ? 'https' : 'http') . '://' . $host;
}

/** Write a PHP config file atomically. */
function install_write_config(string $path, array $config): void
{
    $php = "<?php\ndeclare(strict_types=1);\n\n// Written by the Gather installer on " . gmdate('Y-m-d H:i') . " UTC. Keep this file out of git.\nreturn "
        . var_export($config, true) . ";\n";
    $dir = dirname($path);
    if (!is_writable($dir) && !(is_file($path) && is_writable($path))) {
        throw new RuntimeException('The folder ' . $dir . ' is not writable by the web server.');
    }
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $php, LOCK_EX) === false || !rename($tmp, $path)) {
        throw new RuntimeException('Could not write ' . $path);
    }
    chmod($path, 0640);
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($path, true);
    }
    config_all($config);
}

/** Config array for a new install. */
function install_new_config(array $db, string $baseUrl): array
{
    return [
        'installed' => false,
        'install_token' => bin2hex(random_bytes(16)),
        'debug' => false,
        'app_key' => bin2hex(random_bytes(32)),
        'base_url' => $baseUrl,
        'db' => $db,
        'mail' => ['driver' => 'file', 'from_email' => 'no-reply@' . (parse_url($baseUrl, PHP_URL_HOST) ?: 'localhost'), 'from_name' => 'Gather'],
    ];
}

/** Mark the install finished: drops the token and locks the wizard. */
function install_lock(): void
{
    $config = config_all();
    unset($config['install_token']);
    $config['installed'] = true;
    install_write_config(config_path(), $config);
}

/** The session that started the install is the only one that may continue it. */
function install_session_ok(): bool
{
    $token = (string) config('install_token', '');
    return $token !== '' && is_string($_SESSION['install_token'] ?? null) && hash_equals($token, $_SESSION['install_token']);
}
