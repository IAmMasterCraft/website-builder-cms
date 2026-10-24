<?php
declare(strict_types=1);

// Entry for web requests: errors, session, security headers.
require __DIR__ . '/load.php';

$debug = config('debug') === true;
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', BASE . '/storage/logs/php-error.log');
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('gather_sid');
    session_start([
        'cookie_httponly' => true,
        'cookie_secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'cookie_path'     => '/',
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
        'use_only_cookies' => true,
        'gc_maxlifetime'  => 60 * 60 * 8,
    ]);
}

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
header_remove('X-Powered-By');
// Public pages may widen script/connect-src for the chosen analytics provider (public/index.php).
header('Content-Security-Policy: ' . csp_header_value());
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
header('Cross-Origin-Opener-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
if ($https) {
    header('Strict-Transport-Security: max-age=31536000');
}
