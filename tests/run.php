<?php
declare(strict_types=1);

// Zero-dependency test runner.  php tests/run.php [filter]
// Uses tests/config.test.php: every table in that database is dropped and
// recreated from migrations/ at the start of the run.
require __DIR__ . '/bootstrap.php';

$GLOBALS['__tests'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][$name] = $fn;
}

function same(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionError(($msg !== '' ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function ok(bool $cond, string $msg = 'expected true'): void
{
    if (!$cond) {
        throw new AssertionError($msg);
    }
}

function contains(string $needle, string $haystack, string $msg = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new AssertionError(($msg !== '' ? "$msg: " : '') . 'expected to find ' . var_export($needle, true) . ' in ' . var_export(mb_substr($haystack, 0, 400), true));
    }
}

function not_contains(string $needle, string $haystack, string $msg = ''): void
{
    if (str_contains($haystack, $needle)) {
        throw new AssertionError(($msg !== '' ? "$msg: " : '') . 'did not expect ' . var_export($needle, true) . ' in ' . var_export(mb_substr($haystack, 0, 400), true));
    }
}

/** @return Throwable the caught exception */
function throws(string $class, callable $fn): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }
        throw new AssertionError("expected $class, got " . get_class($e) . ': ' . $e->getMessage());
    }
    throw new AssertionError("expected $class to be thrown");
}

/** Run as a given user (or logged out with null). */
function act_as(?int $userId): void
{
    $_SESSION = $userId === null ? [] : ['user_id' => $userId];
    current_user(true);
}

/** Fresh request superglobals. */
function request(string $method = 'GET', string $uri = '/', array $post = [], array $get = []): void
{
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $uri;
    $_POST = $post;
    $_GET = $get;
    $_FILES = [];
}

// Fresh schema.
drop_all_tables(db());
migrate(db());
settings_all([]);

require __DIR__ . '/fixtures.php';
foreach (glob(__DIR__ . '/*_test.php') ?: [] as $file) {
    require $file;
}

$filter = $argv[1] ?? '';
$failed = 0;
$ran = 0;
$start = microtime(true);
foreach ($GLOBALS['__tests'] as $name => $fn) {
    if ($filter !== '' && !str_contains($name, $filter)) {
        continue;
    }
    $ran++;
    $_SESSION = [];
    try {
        $fn();
        echo "ok    $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL  $name\n      " . get_class($e) . ': ' . $e->getMessage() . "\n      at " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}
printf("\n%d tests, %d failed (%.1fs)\n", $ran, $failed, microtime(true) - $start);
exit($failed > 0 ? 1 : 0);
