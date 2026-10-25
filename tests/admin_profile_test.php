<?php
declare(strict_types=1);

// Admin profile page (/admin/profile): any staff user changes their own
// name, login email (confirmed by link) and password. CSRF, audit, session
// version, rate-limited password re-checks.

use Core\HttpException;
use Core\Redirect;

/** A staff user with a known password; returns its id. */
function profile_staff(string $role = 'admin', array $perms = []): int
{
    return create_user('staff-' . bin2hex(random_bytes(4)) . '@example.test', 'old-password-123', $role, 'Sam Staff', 'active', $perms);
}

/** Run a profile POST handler; returns the redirect target. */
function profile_post(string $method, array $post): string
{
    request('POST', '/admin/profile', $post);
    try {
        \Admin\Profile::$method([]);
    } catch (Redirect $r) {
        return $r->location;
    }
    throw new AssertionError('expected a redirect');
}

test('admin profile: any staff user opens it (no permission needed); members and visitors do not', function (): void {
    act_as(profile_staff('admin', []));
    request('GET', '/admin/profile');
    ob_start();
    \Admin\Profile::edit([]);
    $html = (string) ob_get_clean();
    contains('My profile', $html);
    contains('action="/admin/profile/password"', $html);
    contains('href="/admin/profile"', $html, 'linked from the top-bar profile menu');
    act_as(fixture_member());
    same(403, throws(HttpException::class, fn () => \Admin\Profile::edit([]))->status);
    act_as(null);
    throws(Redirect::class, fn () => \Admin\Profile::edit([]));
});

test('admin profile: every POST needs the CSRF token first', function (): void {
    act_as(profile_staff());
    foreach (['update', 'email', 'emailCancel', 'password'] as $m) {
        request('POST', '/admin/profile', ['display_name' => 'X', 'new_email' => 'x@example.test']);
        same(419, throws(HttpException::class, fn () => \Admin\Profile::$m([]))->status, $m);
    }
    act_as(null);
});

test('admin profile: name change is validated and audited', function (): void {
    $id = profile_staff();
    act_as($id);
    profile_post('update', ['_csrf' => csrf_token(), 'display_name' => '   ']);
    same('Sam Staff', q_val('SELECT display_name FROM users WHERE id = ?', [$id]));
    profile_post('update', ['_csrf' => csrf_token(), 'display_name' => str_repeat('n', 121)]);
    same('Sam Staff', q_val('SELECT display_name FROM users WHERE id = ?', [$id]));
    profile_post('update', ['_csrf' => csrf_token(), 'display_name' => '  Samira   Staff ']);
    same('Samira Staff', q_val('SELECT display_name FROM users WHERE id = ?', [$id]));
    same(1, (int) q_val("SELECT COUNT(*) FROM audit_log WHERE action = 'profile_name' AND item_id = ? AND user_id = ?", [$id, $id]));
    act_as(null);
});

test('admin profile: password change needs the current password, follows the rules, ends other sessions', function (): void {
    $id = profile_staff('super_admin');
    act_as($id);
    $sv = (int) q_val('SELECT session_version FROM users WHERE id = ?', [$id]);
    profile_post('password', ['_csrf' => csrf_token(), 'current' => 'wrong-password', 'password' => 'brand-new-password', 'password_confirm' => 'brand-new-password']);
    ok(password_verify('old-password-123', (string) q_val('SELECT password_hash FROM users WHERE id = ?', [$id])), 'unchanged after a wrong current password');
    profile_post('password', ['_csrf' => csrf_token(), 'current' => 'old-password-123', 'password' => 'short', 'password_confirm' => 'short']);
    profile_post('password', ['_csrf' => csrf_token(), 'current' => 'old-password-123', 'password' => 'brand-new-password', 'password_confirm' => 'different-password']);
    ok(password_verify('old-password-123', (string) q_val('SELECT password_hash FROM users WHERE id = ?', [$id])), 'password_problem and confirmation enforced');
    $other = ['user_id' => $id, 'sv' => $sv]; // another browser's session
    profile_post('password', ['_csrf' => csrf_token(), 'current' => 'old-password-123', 'password' => 'brand-new-password', 'password_confirm' => 'brand-new-password']);
    ok(password_verify('brand-new-password', (string) q_val('SELECT password_hash FROM users WHERE id = ?', [$id])));
    same($sv + 1, (int) q_val('SELECT session_version FROM users WHERE id = ?', [$id]), 'session version bumped');
    same($id, current_user(true)['id'] ?? null, 'this browser stays logged in');
    $mine = $_SESSION;
    $_SESSION = $other;
    same(null, current_user(true), 'other sessions are logged out');
    $_SESSION = $mine;
    same(1, (int) q_val("SELECT COUNT(*) FROM audit_log WHERE action = 'password_change' AND item_id = ?", [$id]));
    act_as(null);
});

test('admin profile: password re-checks are rate limited (10 wrong tries an hour)', function (): void {
    $id = profile_staff();
    act_as($id);
    q('DELETE FROM rate_hits WHERE bucket = ?', ['pwconfirm:' . $id]);
    for ($i = 0; $i < 10; $i++) {
        profile_post('password', ['_csrf' => csrf_token(), 'current' => 'guess-' . $i, 'password' => 'brand-new-password', 'password_confirm' => 'brand-new-password']);
    }
    profile_post('password', ['_csrf' => csrf_token(), 'current' => 'old-password-123', 'password' => 'brand-new-password', 'password_confirm' => 'brand-new-password']);
    ok(password_verify('old-password-123', (string) q_val('SELECT password_hash FROM users WHERE id = ?', [$id])), 'even the right password is refused after 10 wrong ones');
    act_as(null);
});

test('admin profile: email change is confirmed by a link to the new address, then ends other sessions', function (): void {
    $dir = capture_mail();
    try {
        $id = profile_staff();
        $old = (string) q_val('SELECT email FROM users WHERE id = ?', [$id]);
        $new = 'staff-new-' . bin2hex(random_bytes(3)) . '@example.test';
        act_as($id);
        profile_post('email', ['_csrf' => csrf_token(), 'new_email' => $new, 'email_password' => 'wrong']);
        same(null, q_val('SELECT pending_email FROM users WHERE id = ?', [$id]), 'current password required');
        $taken = (string) q_val('SELECT email FROM users WHERE id = ?', [fixture_member()]);
        profile_post('email', ['_csrf' => csrf_token(), 'new_email' => $taken, 'email_password' => 'old-password-123']);
        same(null, q_val('SELECT pending_email FROM users WHERE id = ?', [$id]), 'an address in use is refused');
        $sv = (int) q_val('SELECT session_version FROM users WHERE id = ?', [$id]);
        profile_post('email', ['_csrf' => csrf_token(), 'new_email' => strtoupper($new), 'email_password' => 'old-password-123']);
        same($new, q_val('SELECT pending_email FROM users WHERE id = ?', [$id]));
        same($old, q_val('SELECT email FROM users WHERE id = ?', [$id]), 'login unchanged until confirmed');
        same(1, (int) q_val("SELECT COUNT(*) FROM audit_log WHERE action = 'email_change_request' AND item_id = ?", [$id]));
        $mails = captured($dir);
        ok((bool) preg_match('#/account/email/confirm/([a-f0-9]{64})#', implode("\n", $mails), $m), 'confirmation link sent');
        ok(count(array_filter($mails, static fn (string $x): bool => str_contains($x, 'To: ' . $old) || str_contains($x, '<' . $old . '>'))) >= 1, 'the old address is told');
        // Confirm through the shared confirmation page: staff land back on their profile.
        request('POST', '/account/email/confirm/' . $m[1], ['_csrf' => csrf_token()]);
        $e = throws(Redirect::class, fn () => \Account\Account::emailConfirm(['token' => $m[1]]));
        same($new, q_val('SELECT email FROM users WHERE id = ?', [$id]));
        same($sv + 1, (int) q_val('SELECT session_version FROM users WHERE id = ?', [$id]), 'session version bumped');
        same('/admin/profile', $e->location);
        // Cancel a second pending change.
        profile_post('email', ['_csrf' => csrf_token(), 'new_email' => 'another-' . $new, 'email_password' => 'old-password-123']);
        profile_post('emailCancel', ['_csrf' => csrf_token()]);
        same(null, q_val('SELECT pending_email FROM users WHERE id = ?', [$id]));
        same(1, (int) q_val("SELECT COUNT(*) FROM audit_log WHERE action = 'email_change_cancel' AND item_id = ?", [$id]));
    } finally {
        mailer(null, true);
        act_as(null);
    }
});
