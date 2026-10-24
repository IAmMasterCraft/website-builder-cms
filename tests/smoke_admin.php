<?php
declare(strict_types=1);

// Phase 7 smoke checks, included by tests/smoke.php after smoke_comms.php
// (uses its Browser, check(), status_is(), $admin (super admin), $base and
// the open DB connection to the same test database).

require_once BASE . '/src/routes.php';

/** Log a new browser in. */
function login_as(string $base, string $email, string $password): Browser
{
    $b = new Browser($base);
    $b->submit('/login', '/login', ['email' => $email, 'password' => $password]);
    return $b;
}

/** CSRF token from the <meta name="csrf"> tag of an admin page. */
function meta_csrf(Browser $b, string $page = '/admin'): string
{
    return preg_match('/<meta name="csrf" content="([a-f0-9]{64})"/', $b->get($page)['body'], $m) ? $m[1] : '';
}

/** A concrete path for a route pattern (sample values for each parameter). */
function sample_path(string $pattern, array $ids = []): string
{
    return (string) preg_replace_callback('/\{([a-z_]+)(?::((?:[^{}]|\{\d+(?:,\d*)?\})+))?\}/i', static function (array $m) use ($ids): string {
        return match ($m[1]) {
            'id', 'item', 'reg', 'photo', 'rev' => (string) ($ids[$m[1]] ?? 1),
            'w' => '400',
            'token' => str_contains($m[2] ?? '', '\.') ? 'YWJj.' . str_repeat('0', 32) : str_repeat('a', 64),
            'slug', 'group' => 'no-such-thing',
            'type' => 'posts',
            'key' => 'verify_email',
            'name' => str_contains($m[2] ?? '', 'gather-') ? 'gather-20260101-000000-db.sql.gz' : 'file.txt',
            'number' => 'ORG-26-0001',
            'code' => 'abcdef12',
            default => 'x',
        };
    }, $pattern);
}

/** Permission an admin route needs (independent of the handlers: the spec's §2/§7 areas). */
function expected_permission(string $path): ?string
{
    $rules = [
        // Overview, search and the media picker (used by forms in every area) are open to all staff.
        '#^/admin(/search|/media/picker)?$#' => null,
        '#^/admin/(settings|design|custom-fields|users|audit|backups|health)(\b|/|\.)#' => 'super_admin',
        '#^/admin/(media|albums)(/|$)#' => 'gallery_media',
        '#^/admin/(menus|pages|templates|layout)(/|$)#' => 'pages_menus',
        '#^/admin/events(/|$)#' => 'events',
        '#^/admin/(members|groups)(/|\.|$)#' => 'members',
        '#^/admin/(campaigns|email-templates|email-log|notices|inbox)(/|$)#' => 'communications',
        '#^/admin/jobs(/|$)#' => 'jobs',
        '#^/admin/(comments|posts|downloads|leaders|leader-groups|awards|faqs|branches|categories)(/|$)#' => 'content',
        '#^/admin/radio(/|$)#' => 'radio',
    ];
    foreach ($rules as $re => $perm) {
        if (preg_match($re, $path)) {
            return $perm;
        }
    }
    throw new RuntimeException('No expected permission for ' . $path);
}

$superEmail = 'sam@example.test';

// ---- Users & roles ---------------------------------------------------------
status_is($admin, '/admin/users', 200, 'Users and roles');
status_is($admin, '/admin/users/new', 200, 'Admins may manage');
$mailBefore = count(mail_files());
$r = $admin->submit('/admin/users/new', '/admin/users', ['display_name' => 'Ines Invite', 'email' => 'ines@example.test', 'role' => 'admin', 'permissions[0]' => 'radio', 'permissions[1]' => 'events', 'password_mode' => 'invite']);
$ines = (int) q_val("SELECT id FROM users WHERE email = 'ines@example.test'");
check('super admin creates an admin with two permissions', $r['status'] === 303 && $ines > 0 && q_val('SELECT role FROM users WHERE id = ?', [$ines]) === 'admin');
$invite = newest_mail_to('ines@example.test', $mailBefore);
check('new admin gets an invitation with a password link', $invite !== null && (bool) preg_match('#/reset/([a-f0-9]{64})#', $invite, $im));
$inesBrowser = new Browser($base);
$r = $inesBrowser->submit('/reset/' . $im[1], '/reset/' . $im[1], ['password' => 'ines-new-password', 'password_confirm' => 'ines-new-password']);
$inesBrowser = login_as($base, 'ines@example.test', 'ines-new-password');
status_is($inesBrowser, '/admin/radio', 200);
status_is($inesBrowser, '/admin/posts', 403);
status_is($inesBrowser, '/admin/users', 403);
$r = $admin->submit('/admin/users/new', '/admin/users', ['display_name' => 'Bad', 'email' => 'member@example.test', 'role' => 'admin', 'permissions[0]' => 'radio']);
check('an admin cannot reuse a member’s email', $r['status'] === 303 && str_contains($admin->get('/admin/users/new')['body'], 'member or group account'));
$r = $admin->submit('/admin/users/' . $ines, '/admin/users/' . $ines, ['display_name' => 'Ines Invite', 'email' => 'ines@example.test', 'role' => 'admin', 'status' => 'active', 'permissions[0]' => 'content']);
check('permissions can be changed', $r['status'] === 303 && $inesBrowser->get('/admin/posts')['status'] === 200 && $inesBrowser->get('/admin/radio')['status'] === 403);
$r = $admin->submit('/admin/users', '/admin/users/' . $ines . '/status', ['status' => 'suspended']);
check('suspending an admin logs them out at once', $r['status'] === 303 && $inesBrowser->get('/admin')['status'] === 303);
$admin->submit('/admin/users', '/admin/users/' . $ines . '/status', ['status' => 'active']);

$samId = (int) q_val('SELECT id FROM users WHERE email = ?', [$superEmail]);
$r = $admin->submit('/admin/users', '/admin/users/' . $samId . '/status', ['status' => 'suspended']);
check('a super admin cannot suspend themselves', q_val('SELECT status FROM users WHERE id = ?', [$samId]) === 'active');
$admin->submit('/admin/users/' . $samId, '/admin/users/' . $samId, ['display_name' => 'Sam Smoke', 'email' => $superEmail, 'role' => 'admin', 'status' => 'suspended', 'permissions[0]' => 'content']);
check('own role and status are ignored on the edit form', q_val('SELECT role FROM users WHERE id = ?', [$samId]) === 'super_admin' && q_val('SELECT status FROM users WHERE id = ?', [$samId]) === 'active');
check('edit page explains the last-super-admin rule', str_contains($admin->get('/admin/users/' . $samId)['body'], 'cannot change your own role'));
// A second super admin can demote the first, after which the second is the last one.
$admin->submit('/admin/users/new', '/admin/users', ['display_name' => 'Sue Super', 'email' => 'sue@example.test', 'role' => 'super_admin', 'password_mode' => 'set', 'password' => 'sue-super-password']);
$sueId = (int) q_val("SELECT id FROM users WHERE email = 'sue@example.test'");
$sue = login_as($base, 'sue@example.test', 'sue-super-password');
$r = $sue->submit('/admin/users/' . $samId, '/admin/users/' . $samId, ['display_name' => 'Sam Smoke', 'email' => $superEmail, 'role' => 'admin', 'status' => 'active', 'permissions[0]' => 'content']);
check('another super admin can demote a super admin', q_val('SELECT role FROM users WHERE id = ?', [$samId]) === 'admin');
check('the remaining super admin is marked as the last one', str_contains($sue->get('/admin/users')['body'], 'Users and roles') && !str_contains($sue->get('/admin/users')['body'], 'action="/admin/users/' . $sueId . '/status"'));
$sue->submit('/admin/users/' . $samId, '/admin/users/' . $samId, ['display_name' => 'Sam Smoke', 'email' => $superEmail, 'role' => 'super_admin', 'status' => 'active']);
check('and promote them back', q_val('SELECT role FROM users WHERE id = ?', [$samId]) === 'super_admin');
$sue->submit('/admin/users', '/admin/users/' . $sueId . '/status', ['status' => 'suspended']);
$admin->submit('/admin/users', '/admin/users/' . $sueId . '/status', ['status' => 'suspended']);
check('super admin count stays at least one', (int) q_val("SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND status = 'active'") >= 1);

// ---- Audit log -------------------------------------------------------------
$admin->submit('/admin/menus', '/admin/menus', ['name' => 'Audit probe menu', 'location' => '']);
status_is($admin, '/admin/audit', 200, 'Audit log');
$r = $admin->get('/admin/audit?action=create&item=user');
check('audit log filters by action and item', $r['status'] === 200 && str_contains($r['body'], 'ines@example.test') && !str_contains($r['body'], 'site settings'));
$r = $admin->get('/admin/audit.csv?item=user');
check('audit log CSV export', $r['status'] === 200 && str_contains($r['type'], 'text/csv') && str_contains($r['body'], 'ines@example.test'));
$types = array_column(q_all('SELECT DISTINCT item_type FROM audit_log'), 'item_type');
$areas = ['content' => ['post', 'download', 'leader', 'faq', 'award', 'branch'], 'pages' => ['page'], 'menus' => ['menu', 'menu_item'], 'members' => ['member', 'member_note', 'custom_field'],
    'settings' => ['settings'], 'design' => ['design'], 'comms' => ['campaign', 'notice', 'email_template', 'contact_message'], 'radio' => ['radio_media', 'radio_slot', 'radio_playlist'], 'users' => ['user'], 'events' => ['event', 'registration'], 'media' => ['media']];
$missingAreas = [];
foreach ($areas as $area => $want) {
    if (!array_intersect($want, $types)) {
        $missingAreas[] = $area;
    }
}
check('audit entries were written for every admin area the smoke test touched', $missingAreas === [], 'missing: ' . implode(', ', $missingAreas) . ' (have ' . implode(', ', $types) . ')');
$row = q_one("SELECT a.*, u.email FROM audit_log a JOIN users u ON u.id = a.user_id WHERE a.item_type = 'user' AND a.action = 'update' ORDER BY a.id DESC LIMIT 1");
check('audit rows name the person and the change', $row !== null && $row['email'] !== '' && $row['ip'] === '127.0.0.1' && $row['summary'] !== '');

// ---- Backups -----------------------------------------------------------------
status_is($admin, '/admin/backups', 200, 'Download database');
$r = $admin->post('/admin/backups/database', []);
check('backup download without CSRF token is rejected (419)', $r['status'] === 419);
$fp = static function (): array {
    $o = [];
    foreach (db()->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as [$t]) {
        $o[$t] = (string) db()->query('CHECKSUM TABLE ' . db_ident((string) $t))->fetch(PDO::FETCH_NUM)[1];
    }
    ksort($o);
    return $o;
};
$r = $admin->post('/admin/backups/database', ['_csrf' => $admin->csrf('/admin/backups')]);
$before = $fp();
$sql = (string) gzdecode($r['body']);
check('database backup streams a gzip SQL dump', $r['status'] === 200 && str_contains($r['type'], 'gzip') && str_contains($sql, 'CREATE TABLE `users`') && str_contains($r['headers'], 'no-store'));
$dumpFile = sys_get_temp_dir() . '/gather-smoke-dump-' . bin2hex(random_bytes(4)) . '.sql.gz';
file_put_contents($dumpFile, $r['body']);
drop_all_tables(db());
$n = backup_restore($dumpFile, db());
check('downloaded dump restores into gather_test cleanly (same tables and checksums)', $n > 50 && $fp() === $before);
unlink($dumpFile);
status_is($admin, '/admin', 200, 'Overview');
$zipFile = sys_get_temp_dir() . '/gather-smoke-' . bin2hex(random_bytes(4)) . '.zip';
$r = $admin->postToFile('/admin/backups/uploads', ['_csrf' => $admin->csrf('/admin/backups')], $zipFile);
$za = new ZipArchive();
check('uploads backup streams a valid zip', $r['status'] === 200 && str_contains($r['type'], 'zip') && $za->open($zipFile, ZipArchive::CHECKCONS) === true && $za->numFiles > 10);
$za->close();
unlink($zipFile);
$r = $admin->submit('/admin/backups', '/admin/backups/run', []);
$stored = backup_list()[0]['name'] ?? '';
check('back up to the server now stores a manual backup file', $r['status'] === 303 && str_contains($stored, '-manual-'));
$r = $admin->get('/admin/backups/files/' . $stored);
check('stored backup downloads', $r['status'] === 200 && strlen($r['body']) === (int) filesize(backup_dir() . '/' . $stored));
status_is($admin, '/admin/backups/files/gather-20200101-000000-db.sql.gz', 404);
status_is($admin, '/admin/backups/files/..%2Fconfig.php', 404);
$r = $admin->submit('/admin/backups', '/admin/backups/settings', ['enabled' => '1', 'time' => '04:15', 'keep' => '5']);
check('backup schedule saved', setting_fresh('backups')['time'] === '04:15' && setting_fresh('backups')['keep'] === 5);
$r = $admin->submit('/admin/backups', '/admin/backups/settings', ['enabled' => '1', 'time' => '25:00', 'keep' => '0']);
check('backup schedule validated', $r['status'] === 303 && setting_fresh('backups')['time'] === '04:15');
$r = $admin->submit('/admin/backups', '/admin/backups/files/' . $stored . '/delete', []);
clearstatcache();
check('stored backup deleted', !is_file(backup_dir() . '/' . $stored));
foreach (glob(backup_dir() . '/gather-*') ?: [] as $f) {
    unlink($f);
}
$admin->submit('/admin/backups', '/admin/backups/settings', ['time' => '03:00', 'keep' => '7']);

// ---- System health -----------------------------------------------------------
$r = $admin->get('/admin/health');
check('system health page lists the checks', $r['status'] === 200 && str_contains($r['body'], 'PHP version') && str_contains($r['body'], 'Extension pdo_mysql')
    && str_contains($r['body'], 'Migrations') && str_contains($r['body'], 'Disk space') && str_contains($r['body'], 'upload_max_filesize') && str_contains($r['body'], 'Send a test email'));

// ---- Every admin route: permission matrix (acceptance 11) -----------------------
$routes = app_routes()->routes();
$adminRoutes = [];
foreach ($routes as $route) {
    if (!str_starts_with($route['pattern'], '/admin')) {
        continue;
    }
    if (str_contains($route['pattern'], '{type:')) {
        foreach (array_keys(content_types()) as $tk) {
            $adminRoutes[] = [$route['method'], str_replace('{type:[a-z-]+}', $tk, $route['pattern'])];
        }
    } else {
        $adminRoutes[] = [$route['method'], $route['pattern']];
    }
}
$ids = ['id' => (int) q_val('SELECT MIN(id) FROM users')];
$browsers = [];
foreach (array_keys(admin_permissions()) as $key) {
    create_user("matrix-$key@example.test", 'matrix-password-1', 'admin', "Matrix $key", 'active', [$key]);
    $browsers[$key] = login_as($base, "matrix-$key@example.test", 'matrix-password-1');
}
create_user('matrix-none@example.test', 'matrix-password-1', 'admin', 'Matrix none', 'active', []);
$browsers['none'] = login_as($base, 'matrix-none@example.test', 'matrix-password-1');
$memberB = login_as($base, 'member@example.test', 'member-password-123');
$anonB = new Browser($base);
$getCount = 0;
$postCount = 0;
foreach ($browsers as $key => $b) {
    $token = meta_csrf($b);
    $wrong = [];
    foreach ($adminRoutes as [$method, $pattern]) {
        $path = sample_path($pattern, $ids);
        $need = expected_permission($path);
        $allowed = $need === null || $need === $key;
        if ($method === 'GET') {
            $getCount += $key === 'none' ? 1 : 0;
            $status = $b->get($path)['status'];
            if ($allowed ? $status === 403 : $status !== 403) {
                $wrong[] = "GET $path -> $status";
            }
        } elseif (!$allowed) {
            // Denied POSTs carry a valid token so the permission check (not CSRF) answers.
            $postCount += $key === 'none' ? 1 : 0;
            $status = $b->post($path, ['_csrf' => $token])['status'];
            if ($status !== 403) {
                $wrong[] = "POST $path -> $status";
            }
        }
    }
    check(sprintf('permission matrix: admin with only "%s" gets 403 exactly outside that area', $key), $wrong === [], implode('; ', array_slice($wrong, 0, 8)));
}
$memberWrong = [];
$anonWrong = [];
foreach ($adminRoutes as [$method, $pattern]) {
    if ($method !== 'GET') {
        continue;
    }
    $path = sample_path($pattern, $ids);
    if (($s = $memberB->get($path)['status']) !== 403) {
        $memberWrong[] = "$path -> $s";
    }
    $r = $anonB->get($path);
    if ($r['status'] !== 303 || !str_starts_with($r['location'], '/login')) {
        $anonWrong[] = "$path -> {$r['status']}";
    }
}
check("permission matrix covered $getCount admin GET routes and $postCount POST routes", $getCount > 90 && $postCount > 100);
check('members get 403 on every admin page', $memberWrong === [], implode('; ', array_slice($memberWrong, 0, 8)));
check('visitors are sent to /login from every admin page', $anonWrong === [], implode('; ', array_slice($anonWrong, 0, 8)));

// ---- CSRF: every POST route rejects a request without a token ------------------------
$csrfExempt = [
    // RFC 8058 one-click unsubscribe comes from mail clients without a session; the HMAC-signed token is the credential.
    '/unsubscribe/{token:[A-Za-z0-9_-]{4,300}\.[a-f0-9]{32}}' => 'signed token',
];
$noToken = [];
$badToken = [];
$posts = 0;
foreach ($routes as $route) {
    if ($route['method'] !== 'POST' || isset($csrfExempt[$route['pattern']])) {
        continue;
    }
    $posts++;
    $path = sample_path(str_replace('{type:[a-z-]+}', 'posts', $route['pattern']), $ids);
    foreach (['super admin' => $admin, 'visitor' => $anonB] as $who => $b) {
        if (($s = $b->post($path, ['title' => 'csrf probe'])['status']) !== 419) {
            $noToken[] = "$who POST $path -> $s";
        }
    }
    if (($s = $admin->post($path, ['_csrf' => str_repeat('0', 64)], ['X-CSRF-Token: ' . str_repeat('1', 64), 'Accept: application/json'])['status']) !== 419) {
        $badToken[] = "POST $path -> $s";
    }
}
check("CSRF: all $posts POST routes reject requests without a token (419), logged in or not", $posts > 100 && $noToken === [], implode('; ', array_slice($noToken, 0, 8)));
check('CSRF: wrong form and header tokens are rejected too', $badToken === [], implode('; ', array_slice($badToken, 0, 8)));
check('nothing was created by the CSRF probes', (int) q_val("SELECT COUNT(*) FROM posts WHERE title = 'csrf probe'") === 0);
$r = (new Browser($base))->post('/unsubscribe/' . sample_path('{token:x\.y}'), ['List-Unsubscribe' => 'One-Click']);
check('CSRF-exempt unsubscribe still refuses a forged token (404)', $r['status'] === 404);

// ---- Members change their own email (with re-verification) -----------------------
create_user('mover@example.test', 'mover-password-1', 'member', 'Mo Ver', 'active');
$mover = login_as($base, 'mover@example.test', 'mover-password-1');
status_is($mover, '/account/password', 200, 'Email address');
$r = $mover->submit('/account/password', '/account/email', ['new_email' => 'moved@example.test', 'email_password' => 'wrong-password']);
check('email change needs the current password', $r['status'] === 303 && q_val("SELECT pending_email FROM users WHERE email = 'mover@example.test'") === null);
$before = count(mail_files());
$r = $mover->submit('/account/password', '/account/email', ['new_email' => 'moved@example.test', 'email_password' => 'mover-password-1']);
$confirmMail = newest_mail_to('moved@example.test', $before);
check('email change sends a confirmation to the new address', $r['status'] === 303 && $confirmMail !== null && (bool) preg_match('#/account/email/confirm/([a-f0-9]{64})#', $confirmMail, $cm));
check('the old address is told about the change', newest_mail_to('mover@example.test', $before) !== null);
check('account page shows the pending change', str_contains($mover->get('/account/password')['body'], 'Waiting for confirmation'));
status_is(new Browser($base), '/account/email/confirm/' . $cm[1], 200, 'Yes, confirm');
$r = (new Browser($base))->submit('/account/email/confirm/' . $cm[1], '/account/email/confirm/' . $cm[1], []);
check('confirming switches the login email', $r['status'] === 303 && (int) q_val("SELECT COUNT(*) FROM users WHERE email = 'moved@example.test'") === 1);
$r = login_as($base, 'moved@example.test', 'mover-password-1')->get('/account');
check('member logs in with the new address', $r['status'] === 200);
status_is(new Browser($base), '/account/email/confirm/' . $cm[1], 404);

// ---- Analytics allow-list and cookie banner (§7, §11) ------------------------------
$visitorB = new Browser($base);
$r = $visitorB->get('/');
check('no analytics: no cookie banner and no third-party script hosts in the CSP', !str_contains($r['body'], 'data-cookie-banner') && !str_contains($r['headers'], 'googletagmanager'));
$r = $admin->submit('/admin/settings', '/admin/settings', ['site_name' => 'Smoke Test Society', 'timezone' => 'Europe/London', 'analytics_provider' => 'ga4', 'analytics_id' => 'not-an-id']);
check('analytics ID is validated', $r['status'] === 422 && str_contains($r['body'], 'Measurement ID'));
$r = $admin->submit('/admin/settings', '/admin/settings', ['site_name' => 'Smoke Test Society', 'timezone' => 'Europe/London', 'analytics_provider' => 'ga4', 'analytics_id' => 'G-SMOKE1234', 'cookie_banner' => 'Smoke cookies?']);
$r = $visitorB->get('/');
check('analytics on: banner (hidden until JS) + module + CSP for the provider only', str_contains($r['body'], 'data-cookie-banner hidden') && str_contains($r['body'], 'Smoke cookies?')
    && str_contains($r['body'], 'assets/js/analytics.js') && preg_match('/^Content-Security-Policy:.*googletagmanager/mi', $r['headers']) === 1);
check('admin pages keep the strict CSP', preg_match('/^Content-Security-Policy:.*googletagmanager/mi', $admin->get('/admin')['headers']) === 0);
$admin->submit('/admin/settings', '/admin/settings', ['site_name' => 'Smoke Test Society', 'timezone' => 'Europe/London', 'analytics_provider' => '']);
check('analytics off again: banner gone', !str_contains($visitorB->get('/')['body'], 'data-cookie-banner'));
$r = $visitorB->get('/');
check('security headers on public pages', str_contains($r['headers'], 'X-Content-Type-Options: nosniff') && str_contains($r['headers'], 'Referrer-Policy:') && str_contains($r['headers'], 'Cross-Origin-Opener-Policy: same-origin')
    && !preg_match('/^X-Powered-By/mi', $r['headers']) && preg_match('/gather_sid=[^;]+;.*HttpOnly.*SameSite=Lax/i', $admin->get('/login')['headers'] . (new Browser($base))->get('/login')['headers']) === 1);
