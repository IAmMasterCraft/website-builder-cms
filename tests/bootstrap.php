<?php
declare(strict_types=1);

// Test bootstrap: loads helpers without a session or headers and points the
// app at tests/config.test.php (the gather_test database).
putenv('GATHER_CONFIG=' . __DIR__ . '/config.test.php');
if (!is_file(__DIR__ . '/config.test.php')) {
    fwrite(STDERR, "Missing tests/config.test.php (copy tests/config.test.example.php).\n");
    exit(2);
}
require __DIR__ . '/../src/load.php';
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
