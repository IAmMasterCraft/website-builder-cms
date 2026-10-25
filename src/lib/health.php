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
    $failed = (array) setting('cron.failed', []);
    $out = [];
    foreach (cron_jobs() as $name => $job) {
        $ts = isset($last[$name]) ? (int) $last[$name] : null;
        $failedAt = isset($failed[$name]) ? (int) $failed[$name] : null;
        $limit = isset($job['daily_at']) ? 26 * 3600 : ((int) $job['every'] * 3 + 5) * 60;
        $out[] = [
            'name' => $name,
            'every' => isset($job['daily_at']) ? 'daily at ' . $job['daily_at'] : ((int) $job['every'] === 1 ? 'every minute' : 'every ' . (int) $job['every'] . ' min'),
            'last' => $ts,
            'failed' => $failedAt,
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

/**
 * Likely PHP command-line binaries for the cron line, most specific first.
 * The web request often runs under PHP-FPM, LSAPI or CGI, so PHP_BINARY is
 * not the CLI; the CLI usually sits in PHP_BINDIR (php8.3 on Debian/Ubuntu,
 * php elsewhere) or next to the FPM binary (sbin/ -> bin/). Pure function:
 * no file checks, so it can be tested with any host's values.
 * @return list<string>
 */
function health_php_cli_candidates(string $binary, string $bindir, string $sapi, string $version = PHP_VERSION): array
{
    $mm = (string) preg_replace('/^(\d+\.\d+).*/', '$1', $version);
    $out = [];
    if ($sapi === 'cli' && $binary !== '') {
        $out[] = $binary;
    }
    if ($bindir !== '') {
        $out[] = rtrim($bindir, '/') . '/php' . $mm;
        $out[] = rtrim($bindir, '/') . '/php';
    }
    if ($binary !== '') {
        $dir = dirname($binary);
        if (basename($dir) === 'sbin') {
            $out[] = dirname($dir) . '/bin/php';
        }
        $out[] = $dir . '/php';
    }
    return array_values(array_unique($out));
}

/**
 * The PHP CLI path to show in the cron line: the first candidate that exists
 * and is executable. open_basedir can hide paths outside the site, so when
 * nothing can be confirmed the first candidate is returned unconfirmed.
 * @return array{path:string, confirmed:bool}
 */
function health_php_cli(): array
{
    $candidates = health_php_cli_candidates(PHP_BINARY, PHP_BINDIR, PHP_SAPI);
    foreach ($candidates as $c) {
        // open_basedir makes these checks warn (and return false) outside the site folder.
        if (health_quiet(static fn (): bool => is_file($c) && is_executable($c))) {
            return ['path' => $c, 'confirmed' => true];
        }
    }
    return ['path' => $candidates[0] ?? 'php', 'confirmed' => false];
}

/** Run a file-system probe with warnings silenced (open_basedir); restores the handler. */
function health_quiet(callable $fn): mixed
{
    set_error_handler(static fn (): bool => true);
    try {
        return $fn();
    } finally {
        restore_error_handler();
    }
}

/** Quote a path for a shell line only when it needs it. */
function health_shell_path(string $p): string
{
    return preg_match('#^[A-Za-z0-9_./~+-]+$#', $p) ? $p : escapeshellarg($p);
}

/** The one crontab line for this install (generic: no user or panel assumptions). */
function health_cron_line(string $php, string $base): string
{
    $base = rtrim($base, '/');
    return '* * * * * ' . health_shell_path($php) . ' ' . health_shell_path($base . '/bin/cron')
        . ' >> ' . health_shell_path($base . '/storage/logs/cron.log') . ' 2>&1';
}

/** Cron line for this server, built from the detected PHP CLI and install folder. */
function health_detected_cron_line(): string
{
    return health_cron_line(health_php_cli()['path'], BASE);
}

/**
 * Where the web server's document root points: 'public' (recommended),
 * 'project' (the install folder; the root .htaccess routes into public/),
 * 'other' or 'unknown' (built-in server, CLI).
 */
function health_docroot_mode(string $docroot, string $base): string
{
    if ($docroot === '') {
        return 'unknown';
    }
    $norm = static function (string $p): string {
        $r = health_quiet(static fn (): string|false => realpath($p));
        return rtrim($r !== false ? $r : $p, '/');
    };
    $d = $norm($docroot);
    return match ($d) {
        $norm($base . '/public') => 'public',
        $norm($base) => 'project',
        default => 'other',
    };
}

/** Name of the operating-system user PHP runs as (for permissions), or '' when unknown. */
function health_process_user(): string
{
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $pw = health_quiet(static fn (): array|false => posix_getpwuid(posix_geteuid()));
        if (is_array($pw) && isset($pw['name'])) {
            return (string) $pw['name'];
        }
    }
    $u = function_exists('get_current_user') ? get_current_user() : '';
    return is_string($u) ? $u : '';
}

/**
 * "Server and paths" group: the detected install folder, document root, PHP
 * binary and the cron line to copy. Nothing here assumes a control panel.
 * @return list<array{label:string,status:string,detail:string}>
 */
function health_path_items(): array
{
    $out = [];
    $software = (string) ($_SERVER['SERVER_SOFTWARE'] ?? '');
    $out[] = health_item('Web server', 'ok', ($software !== '' ? $software : 'unknown') . ' · PHP ' . PHP_SAPI);
    $out[] = health_item('Install folder', 'ok', BASE);
    $docroot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
    $mode = health_docroot_mode($docroot, BASE);
    $out[] = health_item('Document root', $mode === 'other' ? 'warn' : 'ok', match ($mode) {
        'public' => $docroot . ' (the public folder: recommended)',
        'project' => $docroot . ' (the install folder: the .htaccess there sends every request into public/. This works on Apache and LiteSpeed; pointing the document root at ' . BASE . '/public is safer if your host allows it)',
        'unknown' => 'not reported (built-in development server or command line)',
        default => $docroot . ': expected ' . BASE . '/public',
    });
    $out[] = health_item('Configuration file', 'ok', config_path());
    $ini = php_ini_loaded_file();
    $scanned = php_ini_scanned_files();
    $userIni = (string) ini_get('user_ini.filename');
    $out[] = health_item('php.ini', 'ok', ($ini !== false ? $ini : 'none loaded')
        . ($scanned !== false && trim($scanned) !== '' ? ' (+ ' . count(array_filter(array_map('trim', explode(',', $scanned)))) . ' extra .ini files)' : '')
        . ($userIni !== '' && (str_contains(PHP_SAPI, 'cgi') || str_contains(PHP_SAPI, 'fpm')) ? '; per-folder settings file: ' . $userIni : ''));
    $basedir = (string) ini_get('open_basedir');
    $out[] = health_item('open_basedir', 'ok', $basedir !== '' ? $basedir : 'not set');
    $user = health_process_user();
    $out[] = health_item('PHP runs as', 'ok', $user !== '' ? $user . ' (this user must be able to write storage/ and public/uploads/; cron should run as the same user)' : 'unknown');
    $cli = health_php_cli();
    $out[] = health_item('PHP for cron', $cli['confirmed'] ? 'ok' : 'warn', $cli['path'] . ($cli['confirmed']
        ? ' (PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . ' command line)'
        : ': could not be confirmed from here. Ask your host for the path of the PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . ' command-line binary'));
    $out[] = health_item('Cron line', 'ok', health_detected_cron_line());
    return $out;
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
    $keyProblem = app_key_problem();
    $php[] = health_item('Secret key (app_key)', $keyProblem === null ? 'ok' : 'bad', $keyProblem ?? 'set (' . strlen((string) config('app_key')) . ' characters)');
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
        ? 'last run ' . fmt_datetime($last) . ($running ? '' : ': not running. Add this line to the crontab of the user PHP runs as: ' . health_detected_cron_line())
        : 'never ran. Add this line to the crontab of the user PHP runs as: ' . health_detected_cron_line());
    foreach (health_cron_jobs($now) as $job) {
        $cron[] = health_item('Job ' . $job['name'], $job['failed'] !== null ? 'bad' : ($job['stale'] ? 'warn' : 'ok'), $job['every'] . ' · ' . ($job['last'] !== null ? 'last ' . fmt_datetime(gmdate('Y-m-d H:i:s', $job['last'])) : 'never')
            . ($job['failed'] !== null ? ' · failed at ' . fmt_datetime(gmdate('Y-m-d H:i:s', $job['failed'])) . ', retried every 15 minutes (see storage/logs/php-error.log)' : ''));
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
    $free = function_exists('disk_free_space') ? disk_free_space(BASE) : false;
    $total = function_exists('disk_total_space') ? disk_total_space(BASE) : false;
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
    $pause = mail_pause_state($now);
    $m[] = health_item('Mail server', $pause === null ? 'ok' : ($pause['active'] ? 'bad' : 'warn'), $pause === null
        ? 'reachable at the last send'
        : ($pause['active'] ? 'unreachable: sending paused until ' . fmt_date(gmdate('Y-m-d H:i:s', $pause['until']), 'H:i') . ' (' . $pause['error'] . ')' : 'was unreachable recently (' . $pause['error'] . '); sending resumes on the next cron run'));
    $failed = (int) q_val("SELECT COUNT(*) FROM email_queue WHERE status = 'failed' AND created_at > UTC_TIMESTAMP() - INTERVAL 7 DAY");
    $m[] = health_item('Failed emails (7 days)', $failed === 0 ? 'ok' : 'warn', (string) $failed);
    $base = (string) config('base_url', '');
    $m[] = health_item('Site address', str_starts_with($base, 'https://') ? 'ok' : 'warn', ($base !== '' ? $base : 'not set') . (str_starts_with($base, 'https://') ? '' : ': use https:// on a live site'));
    $b = backup_settings();
    $latest = backup_list()[0] ?? null;
    $m[] = health_item('Scheduled backups', $b['enabled'] ? ($latest && $latest['time'] > $now - 26 * 3600 ? 'ok' : 'warn') : 'warn',
        ($b['enabled'] ? 'on, daily at ' . $b['time'] . ', keeping ' . $b['keep'] : 'off') . ($latest ? ' · newest ' . fmt_datetime(gmdate('Y-m-d H:i:s', $latest['time'])) : ' · none stored'));
    $g['Mail and site'] = $m;
    $g['Bounces and complaints'] = health_bounce_items($now);
    $g['Server and paths'] = health_path_items();
    return $g;
}

/** A5: bounce and complaint rates, blocks, paused campaigns, the bounce mailbox. @return list<array> */
function health_bounce_items(int $now): array
{
    $out = [];
    $pct = static fn (float $r): string => number_format($r * 100, 2) . '%';
    foreach ([7, 30] as $days) {
        $s = bounce_stats($days, $now);
        $out[] = health_item("Bounce rate ($days days)", $s['bounce_rate'] > BOUNCE_RATE_WARN ? 'warn' : 'ok',
            $pct($s['bounce_rate']) . ' (' . ($s['hard'] + $s['soft']) . ' of ' . $s['sent'] . ' sent)' . ($s['bounce_rate'] > BOUNCE_RATE_WARN ? ': above 2%. Clean the list: check recent imports and the suppression list' : ''));
        $out[] = health_item("Complaint rate ($days days)", $s['complaint_rate'] > COMPLAINT_RATE_WARN ? 'warn' : 'ok',
            $pct($s['complaint_rate']) . ' (' . $s['complaint'] . ')' . ($s['complaint_rate'] > COMPLAINT_RATE_WARN ? ': above 0.1%. Mail only people who opted in and make unsubscribing easy' : ''));
    }
    $blocks = bounce_stats(7, $now)['block'];
    $paused = (int) q_val("SELECT COUNT(*) FROM campaigns WHERE status = 'paused'");
    $out[] = health_item('Blocked as spam or by policy (7 days)', $blocks > 0 || $paused > 0 ? 'warn' : 'ok', $blocks === 0 && $paused === 0 ? 'none'
        : $blocks . ' refusal(s)' . ($paused > 0 ? ', ' . $paused . ' campaign(s) paused: check content, SPF, DKIM and DMARC, then resume' : '') );
    $b = bounce_settings();
    $state = bounce_mailbox_state();
    if ($b['mailbox']['protocol'] === 'off') {
        $out[] = health_item('Bounce mailbox', 'warn', 'not set up: bounces that arrive later by email are not processed (unless a provider webhook is connected)');
    } else {
        $stale = $state['last_poll'] === 0 || $state['last_poll'] < $now - 30 * 60;
        $status = $state['last_error'] !== '' ? 'bad' : ($stale ? 'warn' : 'ok');
        $out[] = health_item('Bounce mailbox', $status, $state['last_error'] !== '' ? 'last check failed: ' . $state['last_error']
            : ($state['last_poll'] > 0 ? 'checked ' . fmt_datetime(gmdate('Y-m-d H:i:s', $state['last_poll'])) . ' (' . $state['last_result'] . ')' : 'not checked yet'));
    }
    $unparsed = $state['unparsed'];
    $out[] = health_item('Unprocessed bounce messages', $unparsed > 0 ? 'warn' : 'ok', $unparsed === 0 ? 'none'
        : $unparsed . ' message(s) could not be read and were set aside (' . ($b['mailbox']['protocol'] === 'imap' ? 'folder “' . $b['mailbox']['aside_folder'] . '”' : 'storage/bounces/') . ')');
    $out[] = health_item('Bounce address', $b['address'] !== '' ? 'ok' : 'warn', $b['address'] !== '' ? $b['address'] . ' (per-message VERP tags)' : 'not set: bounces go to the sender address and are matched by recipient only');
    return $out;
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
