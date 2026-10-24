<?php
declare(strict_types=1);

/*
 * System health (§7 super admin, §12 "System health warns" when cron is
 * down). Each check: label, status ok|warn|bad, detail. Pure reads, no side
 * effects, so the page is safe to reload and the checks are unit-testable.
 */

const HEALTH_MIN_PHP = '8.2.0';

/** Required and optional PHP extensions => why the app needs them. */
function health_extensions(): array
{
    return [
        'pdo_mysql' => [true, 'database'],
        'gd' => [true, 'image resizing and WebP variants'],
        'fileinfo' => [true, 'upload type checks'],
        'mbstring' => [true, 'text handling'],
        'sodium' => [true, 'encrypted SMTP password'],
        'openssl' => [true, 'SMTP over TLS'],
        'zlib' => [true, 'compressed database backups'],
        'json' => [true, 'settings and APIs'],
        'intl' => [false, 'better slugs from accented titles'],
        'exif' => [false, 'photo orientation from phones'],
        'curl' => [false, 'not used at runtime; handy for checks'],
    ];
}

/** @return array{label:string,status:string,detail:string} */
function health_item(string $label, string $status, string $detail): array
{
    return ['label' => $label, 'status' => $status, 'detail' => $detail];
}

/** Bytes from a php.ini size such as "64M". */
function ini_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '' || $v === '-1') {
        return -1;
    }
    $n = (int) $v;
    return match (strtolower(substr($v, -1))) {
        'g' => $n * 1024 ** 3,
        'm' => $n * 1024 ** 2,
        'k' => $n * 1024,
        default => $n,
    };
}

function human_bytes(int|float $bytes): string
{
    if ($bytes < 0) {
        return 'unlimited';
    }
    foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $i => $unit) {
        if ($bytes < 1024 ** ($i + 1) || $unit === 'TB') {
            return ($i === 0 ? (string) (int) $bytes : number_format($bytes / 1024 ** $i, 1)) . ' ' . $unit;
        }
    }
    return (string) $bytes;
}

/**
 * Cron state per job: last run and whether it is stale.
 * A job is stale when it has not run for 3 of its intervals (+5 min);
 * daily jobs after 26 hours.
 * @return list<array{name:string, every:string, last:?int, stale:bool}>
 */
function health_cron_jobs(?int $now = null): array
{
    $now ??= time();
    $last = (array) setting('cron.jobs', []);
    $out = [];
    foreach (cron_jobs() as $name => $job) {
        $ts = isset($last[$name]) ? (int) $last[$name] : null;
        $limit = isset($job['daily_at']) ? 26 * 3600 : ((int) $job['every'] * 3 + 5) * 60;
        $out[] = [
            'name' => $name,
            'every' => isset($job['daily_at']) ? 'daily at ' . $job['daily_at'] : ((int) $job['every'] === 1 ? 'every minute' : 'every ' . (int) $job['every'] . ' min'),
            'last' => $ts,
            'stale' => $ts === null || $now - $ts > $limit,
        ];
    }
    return $out;
}

/** Is cron itself running (any run in the last 10 minutes)? */
function health_cron_running(?int $now = null): bool
{
    $last = setting('cron.last_run');
    return is_string($last) && strtotime($last . ' UTC') >= ($now ?? time()) - 600;
}

/** Scheduled items whose publish time passed but are still "scheduled". */
function health_overdue_scheduled(): int
{
    $n = 0;
    foreach (['posts', 'events', 'albums', 'downloads', 'pages', 'leaders', 'awards', 'faqs', 'branches'] as $table) {
        $n += (int) q_val('SELECT COUNT(*) FROM ' . db_ident($table) . " WHERE status = 'scheduled' AND publish_at <= UTC_TIMESTAMP() - INTERVAL 5 MINUTE");
    }
    return $n;
}

/** @return list<string> migration files not applied yet */
function health_pending_migrations(): array
{
    $done = array_column(q_all('SELECT version FROM schema_migrations'), 'version');
    return array_values(array_diff(migration_files(), $done));
}

/**
 * All checks grouped for the page.
 * @return array<string, list<array{label:string,status:string,detail:string}>>
 */
function health_checks(?int $now = null): array
{
    $now ??= time();
    $g = [];

    // PHP
    $php = [];
    $php[] = health_item('PHP version', version_compare(PHP_VERSION, HEALTH_MIN_PHP, '>=') ? 'ok' : 'bad', PHP_VERSION . ' (' . PHP_SAPI . '); needs ' . HEALTH_MIN_PHP . ' or newer');
    foreach (health_extensions() as $ext => [$required, $why]) {
        $loaded = extension_loaded($ext);
        $php[] = health_item('Extension ' . $ext, $loaded ? 'ok' : ($required ? 'bad' : 'warn'), ($loaded ? 'loaded' : ($required ? 'missing (required)' : 'missing (optional)')) . ': ' . $why);
    }
    if (extension_loaded('gd')) {
        $webp = (bool) (gd_info()['WebP Support'] ?? false);
        $php[] = health_item('GD WebP support', $webp ? 'ok' : 'bad', $webp ? 'available' : 'GD was built without WebP: image variants cannot be made');
    }
    $php[] = health_item('display_errors', ini_get('display_errors') && !config('debug') ? 'bad' : (config('debug') === true ? 'warn' : 'ok'),
        config('debug') === true ? 'debug is on in config.php: switch it off on a live site' : (ini_get('display_errors') ? 'on: errors could be shown to visitors' : 'off'));
    $php[] = health_item('Shell functions', 'ok', function_exists('exec') && !in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)
        ? 'exec() is available but not needed (backups are pure PHP)' : 'disabled (not needed)');
    $g['PHP'] = $php;

    // Database
    $db = [];
    try {
        $version = (string) q_val('SELECT VERSION()');
        $isMaria = stripos($version, 'mariadb') !== false;
        $num = (string) preg_replace('/^(\d+\.\d+\.\d+).*/', '$1', $version);
        $okVersion = $isMaria ? version_compare($num, '10.6.0', '>=') : version_compare($num, '8.0.0', '>=');
        $db[] = health_item('Database server', $okVersion ? 'ok' : 'warn', ($isMaria ? 'MariaDB ' : 'MySQL ') . $version . ($okVersion ? '' : ' (MySQL 8 or MariaDB 10.6+ recommended)'));
        $pending = health_pending_migrations();
        $db[] = health_item('Migrations', $pending === [] ? 'ok' : 'bad', $pending === [] ? count(migration_files()) . ' applied, none pending' : 'pending: ' . implode(', ', $pending) . ' (run php bin/migrate)');
        $size = (int) q_val('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES WHERE table_schema = DATABASE()');
        $db[] = health_item('Database size', 'ok', human_bytes($size));
    } catch (Throwable $e) {
        $db[] = health_item('Database', 'bad', 'query failed: ' . $e->getMessage());
    }
    $g['Database'] = $db;

    // Cron
    $cron = [];
    $running = health_cron_running($now);
    $last = setting('cron.last_run');
    $cron[] = health_item('Cron', $running ? 'ok' : 'bad', is_string($last)
        ? 'last run ' . fmt_datetime($last) . ($running ? '' : ': not running. Add: * * * * * php ' . BASE . '/bin/cron')
        : 'never ran. Add to the crontab: * * * * * php ' . BASE . '/bin/cron');
    foreach (health_cron_jobs($now) as $job) {
        $cron[] = health_item('Job ' . $job['name'], $job['stale'] ? 'warn' : 'ok', $job['every'] . ' · ' . ($job['last'] !== null ? 'last ' . fmt_datetime(gmdate('Y-m-d H:i:s', $job['last'])) : 'never'));
    }
    $overdue = health_overdue_scheduled();
    $cron[] = health_item('Scheduled publishing', $overdue === 0 ? 'ok' : 'warn', $overdue === 0
        ? 'nothing overdue (visits also publish due items when cron is down)'
        : pluralise($overdue, 'item') . ' past their publish time: they go live on the next page view; check cron');
    $queued = (int) q_val("SELECT COUNT(*) FROM email_queue WHERE status = 'queued' AND created_at < UTC_TIMESTAMP() - INTERVAL 30 MINUTE")
        + (int) q_val("SELECT COUNT(*) FROM campaign_recipients WHERE status = 'queued' AND created_at < UTC_TIMESTAMP() - INTERVAL 30 MINUTE");
    $cron[] = health_item('Email queue', $queued === 0 ? 'ok' : 'warn', $queued === 0 ? 'no emails waiting longer than 30 minutes' : pluralise($queued, 'email') . ' waiting more than 30 minutes');
    $g['Cron'] = $cron;

    // Storage
    $st = [];
    $free = disk_free_space(BASE);
    $total = disk_total_space(BASE);
    if ($free !== false && $total !== false && $total > 0) {
        $pct = $free / $total * 100;
        $st[] = health_item('Disk space', $free < 512 * 1024 ** 2 || $pct < 5 ? 'bad' : ($pct < 15 ? 'warn' : 'ok'), human_bytes($free) . ' free of ' . human_bytes($total) . ' (' . number_format($pct, 0) . '%)');
    } else {
        $st[] = health_item('Disk space', 'warn', 'not available on this host');
    }
    foreach (['storage/logs', 'storage/cache', 'storage/originals', 'storage/variants', 'storage/mail', 'storage/backups', 'public/uploads'] as $dir) {
        $path = BASE . '/' . $dir;
        $w = is_dir($path) && is_writable($path);
        $st[] = health_item('Writable ' . $dir, $w ? 'ok' : 'bad', $w ? 'writable' : (is_dir($path) ? 'not writable by the web server user' : 'missing'));
    }
    $cfg = config_path();
    if (is_file($cfg)) {
        $perm = fileperms($cfg) & 0777;
        $st[] = health_item('config.php permissions', ($perm & 0007) ? 'warn' : 'ok', sprintf('%o', $perm) . (($perm & 0007) ? ': readable by everyone, use 640' : ''));
    }
    $up = backup_uploads_size();
    $st[] = health_item('Uploaded files', 'ok', pluralise($up['files'], 'file') . ', ' . human_bytes($up['bytes']));
    $g['Storage'] = $st;

    // Uploads
    $u = [];
    $max = ini_bytes((string) ini_get('upload_max_filesize'));
    $post = ini_bytes((string) ini_get('post_max_size'));
    $limit = min($max < 0 ? PHP_INT_MAX : $max, $post < 0 || $post === 0 ? PHP_INT_MAX : $post);
    $radioMax = max(media_size_limit('audio'), media_size_limit('video'));
    $u[] = health_item('upload_max_filesize', 'ok', (string) ini_get('upload_max_filesize'));
    $u[] = health_item('post_max_size', $post > 0 && $max > 0 && $post < $max ? 'warn' : 'ok', (string) ini_get('post_max_size') . ($post > 0 && $max > 0 && $post < $max ? ': smaller than upload_max_filesize' : ''));
    $u[] = health_item('Largest upload', $limit < 20 * 1024 ** 2 ? 'bad' : ($limit < $radioMax ? 'warn' : 'ok'),
        human_bytes($limit) . ($limit < $radioMax ? ' (radio media may be up to ' . human_bytes($radioMax) . '; raise both limits in php.ini)' : ''));
    $u[] = health_item('memory_limit', ini_bytes((string) ini_get('memory_limit')) !== -1 && ini_bytes((string) ini_get('memory_limit')) < 128 * 1024 ** 2 ? 'warn' : 'ok', (string) ini_get('memory_limit') . ' (128M or more for resizing large photos)');
    $u[] = health_item('max_execution_time', 'ok', ((int) ini_get('max_execution_time') === 0 ? 'none' : ini_get('max_execution_time') . ' s'));
    $g['Uploads'] = $u;

    // Mail and site
    $m = [];
    $driver = mail_driver();
    $m[] = health_item('Mail transport', $driver === 'smtp' ? 'ok' : 'warn', $driver === 'smtp' ? 'SMTP (' . (string) (((array) setting('mail.smtp', []))['host'] ?? '') . ')' : 'file: emails are written to storage/mail and not delivered. Set up SMTP in Email settings');
    $failed = (int) q_val("SELECT COUNT(*) FROM email_queue WHERE status = 'failed' AND created_at > UTC_TIMESTAMP() - INTERVAL 7 DAY");
    $m[] = health_item('Failed emails (7 days)', $failed === 0 ? 'ok' : 'warn', (string) $failed);
    $base = (string) config('base_url', '');
    $m[] = health_item('Site address', str_starts_with($base, 'https://') ? 'ok' : 'warn', ($base !== '' ? $base : 'not set') . (str_starts_with($base, 'https://') ? '' : ': use https:// on a live site'));
    $b = backup_settings();
    $latest = backup_list()[0] ?? null;
    $m[] = health_item('Scheduled backups', $b['enabled'] ? ($latest && $latest['time'] > $now - 26 * 3600 ? 'ok' : 'warn') : 'warn',
        ($b['enabled'] ? 'on, daily at ' . $b['time'] . ', keeping ' . $b['keep'] : 'off') . ($latest ? ' · newest ' . fmt_datetime(gmdate('Y-m-d H:i:s', $latest['time'])) : ' · none stored'));
    $g['Mail and site'] = $m;
    return $g;
}

/** Worst status across all checks: ok, warn or bad. */
function health_summary(array $groups): array
{
    $count = ['ok' => 0, 'warn' => 0, 'bad' => 0];
    foreach ($groups as $items) {
        foreach ($items as $i) {
            $count[$i['status']]++;
        }
    }
    return $count;
}
