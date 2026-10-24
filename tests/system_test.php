<?php
declare(strict_types=1);

// Phase 7: backups (dump, restore, zip, schedule, retention) and system health.

/** Table => [create statement without AUTO_INCREMENT, row count, checksum]. */
function db_fingerprint(): array
{
    $out = [];
    foreach (db()->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as [$t]) {
        $create = (string) db()->query('SHOW CREATE TABLE ' . db_ident((string) $t))->fetch(PDO::FETCH_NUM)[1];
        $sum = db()->query('CHECKSUM TABLE ' . db_ident((string) $t))->fetch(PDO::FETCH_NUM)[1];
        $out[$t] = [
            preg_replace('/ AUTO_INCREMENT=\d+/', '', $create),
            (int) q_val('SELECT COUNT(*) FROM ' . db_ident((string) $t)),
            (string) $sum,
        ];
    }
    ksort($out);
    return $out;
}

function tmp_file(string $suffix): string
{
    return sys_get_temp_dir() . '/gather-test-' . bin2hex(random_bytes(5)) . $suffix;
}

test('database dump restores into gather_test with identical tables and data', function (): void {
    // Awkward values: quotes, backslashes, semicolons, newlines, emoji, NULLs.
    $tricky = "It's a \"test\"; with \\ backslash,\nnew line, -- not a comment, /* nor this */ and emoji \u{1F389}";
    setting_set('backup.probe', ['text' => $tricky, 'n' => 3.25]);
    $admin = fixture_admin();
    fixture_content('posts', ['category_id' => (string) q_val("SELECT id FROM categories WHERE slug = 'news'"), 'title' => "Backup; probe 'post'", 'body' => '<p>' . e($tricky) . '</p>', 'status' => 'published', 'visibility' => 'public'], $admin);
    q('INSERT INTO audit_log (user_id, action, item_type, item_id, summary, ip) VALUES (NULL, ?, ?, NULL, ?, ?)', ['probe', '', $tricky, '::1']);
    $before = db_fingerprint();
    ok(count($before) > 40, 'every table is covered');

    foreach ([true, false] as $gzip) {
        $file = tmp_file($gzip ? '.sql.gz' : '.sql');
        $fh = fopen($file, 'wb');
        $stats = backup_dump_db($fh, $gzip);
        fclose($fh);
        same(count($before), $stats['tables']);
        $raw = $gzip ? (string) gzdecode((string) file_get_contents($file)) : (string) file_get_contents($file);
        contains('SET FOREIGN_KEY_CHECKS = 0;', $raw);
        contains('CREATE TABLE `users`', $raw);
        not_contains("\nnew line", $raw, 'newlines inside values are escaped, one statement per line');

        drop_all_tables(db());
        same(0, (int) q_val('SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE()'));
        $n = backup_restore($file, db());
        ok($n > count($before), "restored $n statements");
        settings_all([]);
        same($before, db_fingerprint(), ($gzip ? 'gzip' : 'plain') . ' dump round-trips exactly');
        same($tricky, setting('backup.probe')['text']);
        same([], health_pending_migrations(), 'schema_migrations restored too');
        unlink($file);
    }
});

test('SQL splitter handles quotes, escapes and comments', function (): void {
    $file = tmp_file('.sql');
    file_put_contents($file, "-- header; with semicolon\n# hash comment;\nSELECT 'a;b', \"c\\\";d\";\nSELECT `we;ird`\n, 'it''s';\n\nSELECT 'multi\nline;';\nSELECT 1");
    same(["SELECT 'a;b', \"c\\\";d\"", "SELECT `we;ird`\n, 'it''s'", "SELECT 'multi\nline;'", 'SELECT 1'], iterator_to_array(backup_sql_statements($file), false));
    unlink($file);
});

test('ZipStream writes archives ZipArchive and unzip can read (normal and Zip64)', function (): void {
    $dir = tmp_file('-zipsrc');
    mkdir($dir);
    $files = ['a.txt' => 'hello', 'sub/b.bin' => random_bytes(70000), 'empty.txt' => '', 'naïve ünïcode.txt' => 'utf-8 name'];
    foreach ($files as $name => $data) {
        is_dir(dirname("$dir/$name")) || mkdir(dirname("$dir/$name"), 0777, true);
        file_put_contents("$dir/$name", $data);
    }
    foreach ([false, true] as $zip64) {
        $zipFile = tmp_file('.zip');
        $fh = fopen($zipFile, 'wb');
        $zip = new ZipStream($fh, $zip64);
        foreach ($files as $name => $_) {
            $zip->addFile('uploads/' . $name, "$dir/$name");
        }
        $zip->finish();
        fclose($fh);
        if (class_exists('ZipArchive')) {
            $za = new ZipArchive();
            same(true, $za->open($zipFile, ZipArchive::CHECKCONS), ($zip64 ? 'zip64' : 'zip') . ' passes ZipArchive consistency check');
            same(count($files), $za->numFiles);
            foreach ($files as $name => $data) {
                same($data, $za->getFromName('uploads/' . $name), "content of $name");
            }
            $za->close();
        }
        if (is_executable('/usr/bin/unzip')) {
            $out = [];
            $code = 0;
            exec('/usr/bin/unzip -tq ' . escapeshellarg($zipFile) . ' 2>&1', $out, $code);
            same(0, $code, 'unzip -t: ' . implode(' ', $out));
        }
        unlink($zipFile);
    }
});

test('uploads zip includes public variants, private originals and variants', function (): void {
    $id = fixture_image_media(null, 'members');
    $m = media_find($id);
    $zipFile = tmp_file('.zip');
    $fh = fopen($zipFile, 'wb');
    $n = backup_zip_uploads($fh)['files'];
    fclose($fh);
    ok($n > 0);
    $za = new ZipArchive();
    $za->open($zipFile);
    $names = [];
    for ($i = 0; $i < $za->numFiles; $i++) {
        $names[] = (string) $za->getNameIndex($i);
    }
    $za->close();
    unlink($zipFile);
    ok((bool) array_filter($names, static fn (string $nm): bool => str_starts_with($nm, 'storage/originals/') && str_contains($nm, basename((string) $m['path']))), 'original of the new image is in the zip');
    ok((bool) array_filter($names, static fn (string $nm): bool => str_starts_with($nm, 'storage/variants/')), 'private variants are in the zip');
    ok(!array_filter($names, static fn (string $nm): bool => str_contains($nm, '/._')), 'no macOS metadata files');
});

test('backup_run stores files in storage/backups and keeps the newest N of each kind', function (): void {
    foreach (glob(backup_dir() . '/gather-*') ?: [] as $f) {
        unlink($f);
    }
    setting_set('backups', ['enabled' => true, 'time' => '03:00', 'keep' => 2, 'uploads' => false]);
    $t = 1_900_000_000;
    foreach ([0, 60, 120] as $i => $offset) {
        backup_run($i === 2, $t + $offset);
    }
    $names = array_column(backup_list(), 'name');
    same(3, count($names), 'two database backups and one uploads zip: ' . implode(', ', $names));
    same('gather-' . gmdate('Ymd-His', $t + 120) . '-db.sql.gz', $names[0] === 'gather-' . gmdate('Ymd-His', $t + 120) . '-uploads.zip' ? $names[1] : $names[0]);
    ok(!in_array('gather-' . gmdate('Ymd-His', $t) . '-db.sql.gz', $names, true), 'oldest database backup pruned');
    same(null, backup_path('../config.php'));
    same(null, backup_path('gather-20260101-000000-db.sql.gz'), 'missing file');
    ok(backup_path($names[0]) !== null);
    same('0640', sprintf('%04o', fileperms((string) backup_path($names[0])) & 0777), 'backups are not world-readable');
    // Cron job: off -> nothing; on -> writes.
    setting_set('backups', ['enabled' => false, 'time' => '03:00', 'keep' => 2, 'uploads' => false]);
    same('off', cron_backups());
    setting_set('backups', ['enabled' => true, 'time' => '03:00', 'keep' => 2, 'uploads' => false]);
    contains('db.sql.gz', cron_backups());
    same(2, count(array_filter(backup_list(), static fn (array $b): bool => $b['kind'] === 'db')), 'retention applied by the cron job too');
    foreach (glob(backup_dir() . '/gather-*') ?: [] as $f) {
        unlink($f);
    }
    setting_set('backups', ['enabled' => false]);
});

test('backups cron job runs daily at the configured local time', function (): void {
    setting_set('backups', ['enabled' => true, 'time' => '02:30', 'keep' => 7]);
    $job = cron_jobs()['backups'];
    same('02:30', $job['daily_at']);
    $tz = new DateTimeZone('Europe/London');
    $at = static fn (string $s): int => (new DateTimeImmutable($s, $tz))->getTimestamp();
    ok(!cron_is_due($job, null, $at('2026-07-01 02:00'), $tz));
    ok(cron_is_due($job, null, $at('2026-07-01 02:31'), $tz));
    ok(!cron_is_due($job, $at('2026-07-01 02:31'), $at('2026-07-01 23:00'), $tz), 'once per day');
    setting_set('backups', ['enabled' => false]);
});

test('health checks report PHP, extensions, database, cron, storage and uploads', function (): void {
    $g = health_checks();
    same(['PHP', 'Database', 'Cron', 'Storage', 'Uploads', 'Mail and site', 'Bounces and complaints'], array_keys($g));
    $find = static function (string $label) use ($g): ?array {
        foreach ($g as $items) {
            foreach ($items as $i) {
                if ($i['label'] === $label) {
                    return $i;
                }
            }
        }
        return null;
    };
    same('ok', $find('PHP version')['status']);
    same('ok', $find('Extension pdo_mysql')['status']);
    same('ok', $find('Migrations')['status']);
    contains('none pending', $find('Migrations')['detail']);
    ok($find('Disk space') !== null && $find('Writable storage/logs')['status'] === 'ok');
    ok($find('upload_max_filesize') !== null && $find('Largest upload') !== null);
    ok($find('Mail transport') !== null);
    foreach ($g as $items) {
        foreach ($items as $i) {
            ok(in_array($i['status'], ['ok', 'warn', 'bad'], true), $i['label']);
        }
    }
    $s = health_summary($g);
    same(array_sum(array_map('count', $g)), $s['ok'] + $s['warn'] + $s['bad']);
});

test('health flags stale cron jobs, missing cron and overdue scheduled items', function (): void {
    $now = time();
    setting_set('cron.jobs', ['publish_scheduled' => $now - 30, 'cleanup' => $now - 4 * 3600]);
    setting_set('cron.last_run', gmdate('Y-m-d H:i:s', $now - 30));
    $jobs = array_column(health_cron_jobs($now), null, 'name');
    same(false, $jobs['publish_scheduled']['stale']);
    same(true, $jobs['cleanup']['stale'], 'hourly job not run for 4 h');
    same(true, $jobs['email_queue']['stale'], 'never run');
    ok(health_cron_running($now));
    setting_set('cron.last_run', gmdate('Y-m-d H:i:s', $now - 3600));
    ok(!health_cron_running($now));
    $id = fixture_content('posts', ['category_id' => (string) q_val("SELECT id FROM categories WHERE slug = 'news'"), 'title' => 'Overdue scheduled', 'body' => '<p>x</p>', 'status' => 'scheduled', 'publish_at' => local_in('+1 day'), 'visibility' => 'public']);
    q('UPDATE posts SET publish_at = UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE id = ?', [$id]);
    ok(health_overdue_scheduled() >= 1, 'overdue item counted');
    publish_due();
    same('published', q_val('SELECT status FROM posts WHERE id = ?', [$id]));
});

test('lazy publish fallback publishes due items on a page view at most once a minute', function (): void {
    $id = fixture_content('posts', ['category_id' => (string) q_val("SELECT id FROM categories WHERE slug = 'news'"), 'title' => 'Lazy publish', 'body' => '<p>x</p>', 'status' => 'scheduled', 'publish_at' => local_in('+1 day'), 'visibility' => 'public']);
    q('UPDATE posts SET publish_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = ?', [$id]);
    $marker = BASE . '/storage/cache/publish-check';
    is_file($marker) && unlink($marker);
    publish_due_lazily();
    same('published', q_val('SELECT status FROM posts WHERE id = ?', [$id]));
    $id2 = fixture_content('posts', ['category_id' => (string) q_val("SELECT id FROM categories WHERE slug = 'news'"), 'title' => 'Lazy publish 2', 'body' => '<p>x</p>', 'status' => 'scheduled', 'publish_at' => local_in('+1 day'), 'visibility' => 'public']);
    q('UPDATE posts SET publish_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = ?', [$id2]);
    publish_due_lazily();
    same('scheduled', q_val('SELECT status FROM posts WHERE id = ?', [$id2]), 'throttled within the minute');
    touch($marker, time() - 120);
    publish_due_lazily();
    same('published', q_val('SELECT status FROM posts WHERE id = ?', [$id2]));
});

test('ini_bytes and human_bytes', function (): void {
    same(64 * 1024 * 1024, ini_bytes('64M'));
    same(2 * 1024 ** 3, ini_bytes('2G'));
    same(512 * 1024, ini_bytes('512k'));
    same(-1, ini_bytes('-1'));
    same('1.5 MB', human_bytes(1.5 * 1024 * 1024));
    same('900 B', human_bytes(900));
});

test('analytics: only allow-listed providers with valid IDs; CSP widened just for them', function (): void {
    setting_set('analytics', ['provider' => 'evil', 'site_id' => 'x']);
    same(null, analytics_config());
    setting_set('analytics', ['provider' => 'ga4', 'site_id' => '"><script>']);
    same(null, analytics_config(), 'bad id disables analytics');
    setting_set('analytics', ['provider' => 'ga4', 'site_id' => 'G-AB12CD34EF', 'banner' => '']);
    $a = analytics_config();
    same(true, $a['cookies']);
    same(analytics_default_banner(), $a['banner']);
    $csp = csp_header_value($a);
    contains("script-src 'self' https://www.googletagmanager.com", $csp);
    not_contains('unsafe-inline', $csp);
    not_contains('googletagmanager', csp_header_value(), 'default CSP has no third-party scripts');
    setting_set('analytics', ['provider' => 'plausible', 'site_id' => 'example.org']);
    same(false, analytics_config()['cookies'], 'cookieless provider: no banner');
    setting_set('analytics', []);
    same(null, analytics_config());
});
