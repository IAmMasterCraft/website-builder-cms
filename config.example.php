<?php
declare(strict_types=1);

// Copy to config.php (the /install wizard writes it for you) and fill in.
// config.php holds secrets: it is git-ignored and must never be committed.
return [
    // false while the /install wizard is still running; true locks the wizard.
    'installed' => true,
    'debug'    => false,
    // Random secret (at least 32 characters) for signed links and the stored SMTP password.
    // The site refuses to run without it. Generate with: php -r 'echo bin2hex(random_bytes(32));'
    'app_key'  => '',
    // Public base URL without trailing slash; used in emails, feeds and the sitemap.
    'base_url' => 'http://localhost:8080',
    // Only behind a reverse proxy or CDN that you control: list its IP addresses so the
    // site believes its X-Forwarded-For / X-Forwarded-Proto headers. Empty = trust nobody.
    'trusted_proxies' => [],
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'gather',
        'user' => 'gather',
        'pass' => '',
    ],
    'mail' => [
        // Default transport until a super admin sets up SMTP in Admin -> Email settings.
        // 'file' writes every email to storage/mail/*.eml (development).
        'driver'     => 'file',
        'from_email' => 'no-reply@example.org',
        'from_name'  => 'Gather',
    ],
];
