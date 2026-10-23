<?php
declare(strict_types=1);

use Core\HttpException;
use Core\Redirect;

test('passwords are hashed with Argon2id and verified', function (): void {
    $id = fixture_member();
    $hash = (string) q_val('SELECT password_hash FROM users WHERE id = ?', [$id]);
    ok(str_starts_with($hash, '$argon2id$'), 'argon2id hash');
    ok(password_verify('correct horse battery', $hash));
});

test('create_user validates email, role and password length', function (): void {
    throws(InvalidArgumentException::class, fn () => create_user('bad', 'correct horse battery', 'member', 'X'));
    throws(InvalidArgumentException::class, fn () => create_user('a@b.test', 'short', 'member', 'X'));
    throws(InvalidArgumentException::class, fn () => create_user('a@b.test', 'correct horse battery', 'owner', 'X'));
    throws(InvalidArgumentException::class, fn () => create_user('a2@b.test', 'correct horse battery', 'admin', 'X', 'active', ['everything']));
});

test('attempt_login accepts the right password and rejects wrong or unknown accounts', function (): void {
    $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
    create_user('login-ok@example.test', 'correct horse battery', 'member', 'L');
    ok(attempt_login('LOGIN-OK@example.test', 'correct horse battery') !== null, 'case-insensitive email');
    same(null, attempt_login('login-ok@example.test', 'wrong password!'));
    same(null, attempt_login('nobody@example.test', 'correct horse battery'));
});

test('suspended accounts cannot log in', function (): void {
    $_SERVER['REMOTE_ADDR'] = '10.0.0.2';
    create_user('suspended@example.test', 'correct horse battery', 'member', 'S', 'suspended');
    same(null, attempt_login('suspended@example.test', 'correct horse battery'));
});

test('login locks out after 10 failures for that IP and email', function (): void {
    $_SERVER['REMOTE_ADDR'] = '10.0.0.3';
    create_user('lock@example.test', 'correct horse battery', 'member', 'L');
    for ($i = 0; $i < LOGIN_MAX_FAILURES; $i++) {
        same(null, attempt_login('lock@example.test', 'nope nope nope'));
    }
    same(429, throws(HttpException::class, fn () => attempt_login('lock@example.test', 'correct horse battery'))->status, 'even the right password is refused while locked');
    $_SERVER['REMOTE_ADDR'] = '10.0.0.4';
    ok(attempt_login('lock@example.test', 'correct horse battery') !== null, 'another IP is not locked');
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
});

test('login_user stores the user in the session; logout clears it', function (): void {
    $id = fixture_member();
    $_SESSION = ['csrf' => 'old'];
    login_user($id);
    same($id, $_SESSION['user_id']);
    ok(!isset($_SESSION['csrf']), 'CSRF token rotated on login');
    same($id, current_user()['id']);
    logout_user();
    same(null, current_user(true));
});

test('super admin has every permission; admin only granted ones', function (): void {
    $super = q_one('SELECT * FROM users WHERE id = ?', [fixture_admin('super_admin')]) + ['permissions' => []];
    $admin = q_one('SELECT * FROM users WHERE id = ?', [fixture_admin('admin', ['content', 'events'])]) + ['permissions' => ['content', 'events']];
    $member = q_one('SELECT * FROM users WHERE id = ?', [fixture_member()]) + ['permissions' => []];
    foreach (array_keys(admin_permissions()) as $p) {
        ok(user_can($super, $p), "super admin can $p");
    }
    ok(user_can($super, 'super_admin'));
    ok(user_can($admin, 'content') && user_can($admin, 'events'));
    ok(!user_can($admin, 'members') && !user_can($admin, 'pages_menus') && !user_can($admin, 'super_admin'));
    ok(!user_can($member, 'content'));
    ok(!is_staff($member) && is_staff($admin) && is_staff($super));
});

test('require_permission: 403 for admins without the permission (acceptance 11)', function (): void {
    request('GET', '/admin/groups');
    act_as(fixture_admin('admin', ['content']));
    require_permission('content');
    same(403, throws(HttpException::class, fn () => require_permission('members'))->status);
    same(403, throws(HttpException::class, fn () => require_permission('super_admin'))->status);
});

test('members cannot open /admin (403); visitors are sent to login', function (): void {
    request('GET', '/admin');
    act_as(fixture_member());
    same(403, throws(HttpException::class, 'require_staff')->status);
    act_as(null);
    $r = throws(Redirect::class, 'require_staff');
    same('/login?next=%2Fadmin', $r->location);
});

test('admin routes enforce permissions through the real handlers', function (): void {
    require_once BASE . '/src/routes.php';
    act_as(fixture_admin('admin', ['events']));
    request('GET', '/admin/posts');
    same(403, throws(HttpException::class, fn () => Admin\Content::index(['type' => 'posts']))->status);
    request('GET', '/admin/media');
    same(403, throws(HttpException::class, fn () => Admin\Media::index([]))->status);
    request('GET', '/admin/settings');
    same(403, throws(HttpException::class, fn () => Admin\Settings::edit([]))->status);
    request('GET', '/admin/nope');
    same(404, throws(HttpException::class, fn () => Admin\Content::index(['type' => 'nope']))->status);
});

test('POST handlers check CSRF before anything else', function (): void {
    act_as(fixture_admin());
    request('POST', '/admin/posts', ['title' => 'x']);
    same(419, throws(HttpException::class, fn () => Admin\Content::store(['type' => 'posts']))->status);
    request('POST', '/login', ['email' => 'a@b.c', 'password' => 'x']);
    same(419, throws(HttpException::class, fn () => Site\Auth::login([]))->status);
});

test('password reset tokens are single use and expire', function (): void {
    $id = fixture_member();
    $token = create_password_reset($id);
    same(64, strlen($token));
    same($id, find_password_reset($token));
    same(null, find_password_reset(str_repeat('a', 64)));
    same(null, find_password_reset('../etc'));
    ok(!complete_password_reset($token, 'short'), 'weak password refused');
    ok(complete_password_reset($token, 'a brand new password'));
    same(null, find_password_reset($token), 'used token is invalid');
    ok(password_verify('a brand new password', (string) q_val('SELECT password_hash FROM users WHERE id = ?', [$id])));
    $t2 = create_password_reset($id);
    q('UPDATE password_resets SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE');
    same(null, find_password_reset($t2), 'expired token is invalid');
});

test('rate limiter counts hits per bucket in a window', function (): void {
    ok(!rate_limited('t|x', 2, 60));
    rate_hit('t|x');
    rate_hit('t|x');
    ok(rate_limited('t|x', 2, 60));
    ok(!rate_limited('t|y', 2, 60));
});
