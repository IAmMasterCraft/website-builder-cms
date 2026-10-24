<?php
declare(strict_types=1);

// Loads every helper and the class autoloader. Has no side effects (no
// session, no headers), so CLI scripts and tests can include it safely.
if (!defined('BASE')) {
    define('BASE', dirname(__DIR__));
}
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

spl_autoload_register(static function (string $class): void {
    if (!preg_match('/^[A-Za-z0-9_\\\\]+$/', $class)) {
        return;
    }
    $file = BASE . '/src/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

foreach ([
    'config', 'db', 'http', 'csrf', 'view', 'settings', 'time', 'text', 'ui', 'auth', 'mail', 'email_templates', 'mailqueue', 'migrate',
    'media', 'content', 'menus', 'search', 'feeds', 'chrome', 'design', 'theme', 'forms', 'builder', 'members', 'cards', 'audience', 'campaigns', 'notices', 'birthdays', 'jobs', 'comments', 'radio', 'cron', 'install', 'demo',
] as $lib) {
    require BASE . '/src/lib/' . $lib . '.php';
}
