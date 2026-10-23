<?php
declare(strict_types=1);

// Copy to tests/config.test.php (git-ignored). Tests DROP and recreate every
// table in this database: never point it at real data.
return [
    // false while the /install wizard is still running; true locks the wizard.
    'installed' => true,
    'debug'    => true,
    'app_key'  => 'test-key-not-secret',
    'base_url' => 'http://127.0.0.1:8099',
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'gather_test',
        'user' => 'gather',
        'pass' => '',
    ],
    'mail' => ['driver' => 'file', 'from_email' => 'no-reply@example.test', 'from_name' => 'Gather tests'],
];
