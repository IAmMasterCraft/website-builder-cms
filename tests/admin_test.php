<?php
declare(strict_types=1);

// Phase 7: users & roles (last super admin rule), permissions, audit log.

use Core\HttpException;

/** Run $fn with $keep as the only active super admin; other super admins are restored afterwards. */
function with_only_super_admin(int $keep, callable $fn): void
{
    $others = array_map('intval', array_column(q_all("SELECT id FROM users WHERE role = 'super_admin' AND status = 'active' AND id <> ?", [$keep]), 'id'));
    if ($others) {
        q('UPDATE users SET status = \'suspended\' WHERE id IN (' . db_placeholders(count($others)) . ')', $others);
    }
    try {
        $fn();
    } finally {
        if ($others) {
            q('UPDATE users SET status = \'active\' WHERE id IN (' . db_placeholders(count($others)) . ')', $others);
        }
    }
}

test('the last active super admin cannot be demoted or suspended', function (): void {
    $solo = fixture_admin();
    with_only_super_admin($solo, function () use ($solo): void {
        same(1, active_super_admin_count());
        throws(LastSuperAdminException::class, fn () => staff_apply_change($solo, ['role' => 'admin', 'permissions' => ['content']]));
        throws(LastSuperAdminException::class, fn () => staff_apply_change($solo, ['status' => 'suspended']));
        same('super_admin', q_val('SELECT role FROM users WHERE id = ?', [$solo]), 'role unchanged after refusal');
        same('active', q_val('SELECT status FROM users WHERE id = ?', [$solo]));
        // Renaming the last super admin is fine.
        staff_apply_change($solo, ['display_name' => 'Solo Super']);
        same('Solo Super', q_val('SELECT display_name FROM users WHERE id = ?', [$solo]));
    });
});

test('a super admin can be demoted once another active super admin exists', function (): void {
    $a = fixture_admin();
    with_only_super_admin($a, function () use ($a): void {
        $b = fixture_admin();
        same(2, active_super_admin_count());
        staff_apply_change($a, ['role' => 'admin', 'permissions' => ['events', 'radio']]);
        same('admin', q_val('SELECT role FROM users WHERE id = ?', [$a]));
        same(['events', 'radio'], staff_find($a)['permissions']);
        // Now $b is the last one.
        throws(LastSuperAdminException::class, fn () => staff_apply_change($b, ['status' => 'suspended']));
        // A suspended super admin does not count.
        $c = fixture_admin();
        staff_apply_change($c, ['status' => 'suspended']);
        throws(LastSuperAdminException::class, fn () => staff_apply_change($b, ['role' => 'admin', 'permissions' => ['content']]));
        // Promoting $a back lets $b step down.
        staff_apply_change($a, ['role' => 'super_admin']);
        same([], staff_find($a)['permissions'], 'super admins hold no permission rows');
        staff_apply_change($b, ['status' => 'suspended']);
        same(1, active_super_admin_count());
    });
});

test('staff_validate checks name, email, role, permissions and password', function (): void {
    [$d, $e] = staff_validate(['display_name' => '', 'email' => 'nope', 'role' => 'owner', 'status' => 'x', 'permissions' => ['everything']], null);
    same(['display_name', 'email', 'role', 'status', 'permissions'], array_keys($e));
    [$d, $e] = staff_validate(['display_name' => 'Ann', 'email' => 'ann-new@example.test', 'role' => 'admin', 'permissions' => []], null);
    contains('at least one area', $e['permissions'] ?? '');
    [$d, $e] = staff_validate(['display_name' => 'Ann', 'email' => 'ann-new@example.test', 'role' => 'admin', 'permissions' => ['content'], 'password_mode' => 'set', 'password' => 'short'], null);
    same(['password'], array_keys($e));
    [$d, $e] = staff_validate(['display_name' => 'Ann', 'email' => 'Ann-New@Example.test', 'role' => 'super_admin', 'permissions' => ['content']], null);
    same([], $e);
    same('ann-new@example.test', $d['email']);
    same([], $d['permissions'], 'super admins get no permission rows');
    $member = fixture_member();
    [$d, $e] = staff_validate(['display_name' => 'Ann', 'email' => q_val('SELECT email FROM users WHERE id = ?', [$member]), 'role' => 'admin', 'permissions' => ['content']], null);
    contains('member or group account', $e['email'] ?? '');
});

test('staff_create emails an invitation that works for 72 hours', function (): void {
    $dir = sys_get_temp_dir() . '/gather-mail-' . bin2hex(random_bytes(4));
    mailer(new \Core\Mail\FileMailer($dir, 'no-reply@example.test', 'Gather'));
    try {
        [$d] = staff_validate(['display_name' => 'Ivy Invite', 'email' => 'ivy-' . bin2hex(random_bytes(3)) . '@example.test', 'role' => 'admin', 'permissions' => ['jobs']], null);
        $id = staff_create($d, true);
        $eml = quoted_printable_decode((string) file_get_contents(glob($dir . '/*.eml')[0]));
        contains('admin access', $eml);
        ok((bool) preg_match('#/reset/([a-f0-9]{64})#', $eml, $m), 'reset link in the email');
        same($id, find_password_reset($m[1]));
        $hours = (int) q_val('SELECT TIMESTAMPDIFF(HOUR, UTC_TIMESTAMP(), expires_at) FROM password_resets WHERE user_id = ?', [$id]);
        ok($hours >= 71 && $hours <= 72, "invite valid ~72 h (got $hours)");
        same(['jobs'], staff_find($id)['permissions']);
    } finally {
        mailer(null, true);
    }
});

test('a suspended admin is logged out on the next request', function (): void {
    $boss = fixture_admin();
    $admin = fixture_admin('admin', ['content']);
    act_as($admin);
    ok(current_user() !== null);
    act_as($boss);
    staff_apply_change($admin, ['status' => 'suspended']);
    $_SESSION = ['user_id' => $admin];
    same(null, current_user(true), 'suspended session is dropped');
    same([], $_SESSION, 'user id removed from the session');
});

test('permission matrix: each key opens only its own area; super admin opens all', function (): void {
    $keys = array_keys(admin_permissions());
    same(12, count($keys), 'eight permission keys (§2) plus essential_notices (review 2), impersonate (B2), messages (C3) and payments (D2)');
    foreach ($keys as $key) {
        $id = fixture_admin('admin', [$key]);
        act_as($id);
        foreach ([...$keys, 'super_admin'] as $other) {
            same($other === $key, user_can(current_user(), $other), "admin with $key asking for $other");
        }
        throws(HttpException::class, fn () => require_permission('super_admin'));
    }
    act_as(fixture_admin());
    foreach ([...$keys, 'super_admin'] as $k) {
        ok(user_can(current_user(), $k), "super admin can $k");
    }
    act_as(fixture_member());
    $e = throws(HttpException::class, fn () => require_staff());
    same(403, $e->status, 'members get 403 on /admin');
});

test('audit() records who, what, item and IP', function (): void {
    $id = fixture_admin();
    act_as($id);
    $_SERVER['REMOTE_ADDR'] = '192.0.2.7';
    audit('update', 'settings', null, str_repeat('x', 300));
    $row = q_one('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1');
    same($id, (int) $row['user_id']);
    same('update', $row['action']);
    same('settings', $row['item_type']);
    same('192.0.2.7', $row['ip']);
    same(250, mb_strlen((string) $row['summary']), 'summary trimmed');
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
});

test('audit log filters by user, action, item and date', function (): void {
    $a = fixture_admin();
    act_as($a);
    audit('approve', 'job', 42, 'filter-probe one');
    $b = fixture_admin();
    act_as($b);
    audit('delete', 'menu', 7, 'filter-probe two');
    $count = static function (array $f): int {
        [$where, $params] = \Admin\Audit::where($f + ['user' => '', 'action' => '', 'item' => '', 'from' => '', 'to' => '', 'q' => '']);
        return (int) q_val("SELECT COUNT(*) FROM audit_log a $where", $params);
    };
    same(2, $count(['q' => 'filter-probe']));
    same(1, $count(['q' => 'filter-probe', 'user' => (string) $a]));
    same(1, $count(['q' => 'filter-probe', 'action' => 'delete']));
    same(1, $count(['q' => 'filter-probe', 'item' => 'job']));
    $today = (new DateTimeImmutable('now', site_tz()))->format('Y-m-d');
    same(2, $count(['q' => 'filter-probe', 'from' => $today, 'to' => $today]));
    same(0, $count(['q' => 'filter-probe', 'to' => (new DateTimeImmutable('-2 days', site_tz()))->format('Y-m-d')]));
});

test('every admin POST route writes an audit entry (static check of the handlers)', function (): void {
    require_once BASE . '/src/routes.php';
    // Handlers that deliberately do not log, and why.
    $exempt = [
        'Admin\\Builder::save' => 'autosave every few seconds; builder_save_draft() logs one entry per editing session',
        'Admin\\Inbox::read' => 'marking a message read is not a change to site data',
        'Site\\Messages::read' => 'marking a conversation read is not a change to site data (like the inbox)',
        'Admin\\Messages::teamRead' => 'marking a Team inbox thread read is not a change to site data',
        'Admin\\Impersonation::start' => 'impersonation_start() writes the impersonate_start entry before the session switches (tested in impersonation_test.php)',
    ];
    $source = static function (string $class, string $method): string {
        $r = new ReflectionMethod($class, $method);
        $lines = file((string) $r->getFileName()) ?: [];
        return implode('', array_slice($lines, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
    };
    $logs = static function (string $class, string $method, int $depth = 0) use (&$logs, $source): bool {
        $src = $source($class, $method);
        if (str_contains($src, 'audit(')) {
            return true;
        }
        preg_match_all('/(?:self|static)::([A-Za-z_]+)\(/', $src, $m);
        foreach (array_unique($m[1]) as $called) {
            if ($depth < 2 && method_exists($class, $called) && $logs($class, $called, $depth + 1)) {
                return true;
            }
        }
        return false;
    };
    $missing = [];
    $checked = 0;
    foreach (app_routes()->routes() as $route) {
        if ($route['method'] !== 'POST' || !str_starts_with($route['pattern'], '/admin')) {
            continue;
        }
        [$class, $method] = $route['handler'];
        if (isset($exempt[$class . '::' . $method])) {
            continue;
        }
        $checked++;
        if (!$logs($class, $method)) {
            $missing[] = $route['pattern'];
        }
    }
    ok($checked > 80, "checked $checked admin POST routes");
    same([], $missing, 'admin POST routes without an audit entry');
});

test('users and roles handlers refuse admins without super admin rights', function (): void {
    act_as(fixture_admin('admin', array_keys(admin_permissions())));
    request('GET', '/admin/users');
    $e = throws(HttpException::class, fn () => \Admin\Users::index([]));
    same(403, $e->status);
    $e = throws(HttpException::class, fn () => \Admin\Audit::index([]));
    same(403, $e->status);
    $e = throws(HttpException::class, fn () => \Admin\Health::index([]));
    same(403, $e->status);
    $e = throws(HttpException::class, fn () => \Admin\Backups::index([]));
    same(403, $e->status);
});
