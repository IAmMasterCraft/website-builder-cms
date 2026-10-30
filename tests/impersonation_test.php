<?php
declare(strict_types=1);

// Addendum B2: "Log in as this member" (impersonation).

use Core\HttpException;
use Core\Redirect;

/** A super admin (or an admin with the given permissions) acting in this "request". */
function imp_admin(array $perms = ['impersonate', 'members'], string $role = 'admin'): array
{
    $id = fixture_admin($role, $role === 'super_admin' ? [] : $perms);
    act_as($id);
    $user = current_user();
    ok($user !== null, 'admin logged in');
    return $user;
}

/** Run the central guard and return what it did: 'pass', 'redirect:<to>' or the HTTP status. */
function imp_guard(string $method, string $path): string
{
    try {
        impersonation_guard($method, $path);
        return 'pass';
    } catch (Redirect $r) {
        return 'redirect:' . $r->location;
    } catch (HttpException $e) {
        return (string) $e->status;
    }
}

test('B2: impersonate is the tenth permission key and opens only its own screen', function (): void {
    same(11, count(admin_permissions()), 'C3 added messages as the eleventh key');
    ok(isset(admin_permissions()['impersonate']), 'impersonate key exists');
    act_as(fixture_admin('admin', ['impersonate']));
    ok(user_can(current_user(), 'impersonate'));
    ok(!user_can(current_user(), 'members'), 'impersonate alone opens no other area');
    act_as(fixture_admin('admin', ['members']));
    $e = throws(HttpException::class, fn () => require_permission('impersonate'));
    same(403, $e->status, 'members permission alone cannot impersonate');
});

test('B2: staff accounts can never be impersonated; members and groups can', function (): void {
    $admin = imp_admin();
    foreach ([fixture_admin(), fixture_admin('admin', ['members'])] as $staffId) {
        $e = throws(DomainException::class, fn () => impersonation_start($admin, $staffId, '', false, true));
        contains('cannot be viewed as a member', $e->getMessage());
    }
    ok(!is_impersonating(), 'nothing started');
    same(0, (int) q_val('SELECT COUNT(*) FROM impersonations WHERE admin_id = ?', [$admin['id']]));
    throws(DomainException::class, fn () => impersonation_start($admin, 999999, '', false, true));
    $m = fixture_full_member();
    impersonation_start($admin, $m['user_id'], '', false, false);
    ok(is_impersonating());
});

test('B2: starting switches the session to the member, keeps the admin separately and writes the audit entry', function (): void {
    $mailer = rec_mailer();
    setting_set('impersonation.notify', true);
    settings_all([]);
    $admin = imp_admin();
    $adminSv = $_SESSION['sv'];
    $m = fixture_full_member(['first_name' => 'Imani', 'last_name' => 'Start']);
    q('UPDATE members SET member_number = ? WHERE id = ?', ['TST-26-0042', $m['member_id']]);
    impersonation_start($admin, $m['user_id'], 'Cannot find   her card', true, false);
    same($m['user_id'], $_SESSION['user_id'], 'session user is the member');
    same($admin['id'], $_SESSION['imp']['admin_id'], 'admin identity kept separately');
    same($adminSv, $_SESSION['imp']['admin_sv']);
    same(true, $_SESSION['imp']['allow_changes']);
    same('/admin/members/' . $m['member_id'], $_SESSION['imp']['return_to']);
    ok($_SESSION['imp']['expires'] - time() <= IMPERSONATION_SECONDS && $_SESSION['imp']['expires'] - time() >= IMPERSONATION_SECONDS - 5, '30-minute hard expiry');
    same($m['user_id'], current_user()['id']);
    ok(!is_staff(current_user()), 'the member has no admin rights');
    $row = q_one("SELECT * FROM audit_log WHERE action = 'impersonate_start' ORDER BY id DESC LIMIT 1");
    same($admin['id'], (int) $row['user_id'], 'start is logged as the admin');
    same(['member', $m['member_id']], [$row['item_type'], (int) $row['item_id']]);
    contains('Imani Start (TST-26-0042)', $row['summary']);
    contains('reason: Cannot find her card', $row['summary']);
    contains('changes allowed', $row['summary']);
    ok(array_key_exists('ip', $row), 'IP recorded');
    $imp = q_one('SELECT * FROM impersonations ORDER BY id DESC LIMIT 1');
    same([$admin['id'], $m['user_id'], 'Cannot find her card', 1], [(int) $imp['admin_id'], (int) $imp['user_id'], $imp['reason'], (int) $imp['allow_changes']]);
    same(1, count($mailer->sent), 'the member is told');
    same($m['email'], $mailer->sent[0]->toEmail);
    contains('administrator viewed your', $mailer->sent[0]->subject);
});

test('B2: the member notice email can be switched off', function (): void {
    $mailer = rec_mailer();
    setting_set('impersonation.notify', false);
    settings_all([]);
    $admin = imp_admin(['impersonate']);
    $m = fixture_full_member();
    impersonation_start($admin, $m['user_id'], '', false, false);
    same(0, count($mailer->sent));
    same('/admin', $_SESSION['imp']['return_to'], 'without the members permission the admin returns to the overview');
    setting_set('impersonation.notify', true);
    settings_all([]);
});

test('B2: nested impersonation is impossible', function (): void {
    rec_mailer();
    $admin = imp_admin();
    $a = fixture_full_member();
    $b = fixture_full_member();
    impersonation_start($admin, $a['user_id'], '', false, false);
    throws(DomainException::class, fn () => impersonation_start($admin, $b['user_id'], '', false, false));
    same($a['user_id'], $_SESSION['user_id']);
    same('403', imp_guard('GET', '/admin/impersonate/' . $b['user_id']), 'the admin area (and its start screen) is closed');
});

test('B2: suspended and unverified accounts need the confirmation tick', function (): void {
    rec_mailer();
    $admin = imp_admin();
    $s = fixture_full_member(['status' => 'suspended']);
    same(1, count(impersonation_warnings(impersonation_target($s['user_id']))));
    $e = throws(DomainException::class, fn () => impersonation_start($admin, $s['user_id'], '', false, false));
    contains('tick the box', $e->getMessage());
    ok(!is_impersonating());
    impersonation_start($admin, $s['user_id'], '', false, true);
    same($s['user_id'], current_user()['id'], 'a suspended member can be viewed after confirming');
    same('pass', imp_guard('GET', '/account'));
    impersonation_end('returned');
    $u = fixture_full_member(['status' => 'unverified']);
    throws(DomainException::class, fn () => impersonation_start(current_user(), $u['user_id'], '', false, false));
    impersonation_start(current_user(), $u['user_id'], '', false, true);
    same($u['user_id'], current_user()['id']);
});

test('B2: restricted actions answer 403 while impersonating (read-only), reading works', function (): void {
    rec_mailer();
    $admin = imp_admin();
    $m = fixture_full_member();
    impersonation_start($admin, $m['user_id'], '', false, false);
    $blocked = [
        ['POST', '/account/password'], ['POST', '/account/email'], ['POST', '/account/email/cancel'], ['POST', '/account/privacy/delete'],
        ['GET', '/account/privacy/export'], ['POST', '/account/preferences'], ['POST', '/account/newsletter/opt-in'],
        ['POST', '/account/newsletter/dismiss'], ['POST', '/unsubscribe/YWJj.' . str_repeat('0', 32)], ['POST', '/like'],
        ['POST', '/comments'], ['POST', '/comments/3/edit'], ['POST', '/comments/3/report'], ['POST', '/jobs/4/report'],
        ['POST', '/account/jobs'], ['GET', '/account/jobs/new'], ['GET', '/account/jobs/4/edit'], ['POST', '/account/jobs/4/delete'],
        ['GET', '/admin'], ['GET', '/admin/members'], ['POST', '/admin/members/1/status'], ['POST', '/contact'],
        ['POST', '/account/profile'], ['POST', '/account/photo'], ['POST', '/account/events/9/cancel'], ['POST', '/events/summer-fair/register'],
        ['POST', '/account/notices/read'], ['POST', '/login'], ['GET', '/join'], ['POST', '/account/verify/resend'],
    ];
    foreach ($blocked as [$method, $path]) {
        same('403', imp_guard($method, $path), "$method $path");
    }
    foreach (['/account', '/account/profile', '/account/card', '/account/events', '/account/notices', '/account/jobs', '/jobs', '/events', '/', '/help', '/account/preferences', '/account/privacy'] as $path) {
        same('pass', imp_guard('GET', $path), "GET $path");
    }
    same('pass', imp_guard('POST', '/impersonation/stop'));
    same('pass', imp_guard('POST', '/logout'));
});

test('B2: "allow changes" opens profile edits and event registration/cancel only', function (): void {
    rec_mailer();
    $admin = imp_admin();
    $m = fixture_full_member();
    impersonation_start($admin, $m['user_id'], '', true, false);
    foreach (['/account/profile', '/account/photo', '/account/events/9/cancel', '/events/summer-fair/register'] as $path) {
        same('pass', imp_guard('POST', $path), "POST $path");
    }
    foreach (['/account/password', '/account/email', '/account/privacy/delete', '/account/preferences', '/like', '/comments'] as $path) {
        same('403', imp_guard('POST', $path), "still blocked: POST $path");
    }
});

test('B2: changes made while impersonating are logged as the admin on behalf of the member', function (): void {
    rec_mailer();
    $admin = imp_admin();
    $m = fixture_full_member();
    impersonation_start($admin, $m['user_id'], '', true, false);
    audit('profile_update', 'member', $m['member_id'], 'profile details');
    $row = q_one('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1');
    same([$admin['id'], $m['user_id']], [(int) $row['user_id'], (int) $row['on_behalf_of']]);
    impersonation_end('returned');
    audit('update', 'settings', null, 'after');
    $row = q_one('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1');
    same(null, $row['on_behalf_of'], 'normal entries have no on-behalf-of');
});

test('B2: "Return to admin" restores the admin session and logs the end with the duration', function (): void {
    rec_mailer();
    $admin = imp_admin();
    $sv = $_SESSION['sv'];
    $m = fixture_full_member(['first_name' => 'Rhea', 'last_name' => 'Turn']);
    impersonation_start($admin, $m['user_id'], '', false, false);
    $_SESSION['imp']['started'] -= 600; // ten minutes ago
    $to = impersonation_end('returned');
    same('/admin/members/' . $m['member_id'], $to);
    same($admin['id'], $_SESSION['user_id']);
    same($sv, $_SESSION['sv']);
    ok(!isset($_SESSION['imp']));
    same($admin['id'], current_user()['id']);
    $row = q_one("SELECT * FROM audit_log WHERE action = 'impersonate_end' ORDER BY id DESC LIMIT 1");
    same($admin['id'], (int) $row['user_id']);
    contains('Rhea Turn after 10 min: returned to admin', $row['summary']);
    ok(q_val("SELECT ended_at FROM impersonations ORDER BY id DESC LIMIT 1") !== null, 'session row closed');
    same('returned', q_val('SELECT end_reason FROM impersonations ORDER BY id DESC LIMIT 1'));
});

test('B2: ends automatically after 30 minutes', function (): void {
    rec_mailer();
    $admin = imp_admin();
    $m = fixture_full_member();
    impersonation_start($admin, $m['user_id'], '', false, false);
    $_SESSION['imp']['expires'] = time() - 1;
    same('redirect:/admin/members/' . $m['member_id'], imp_guard('GET', '/account'));
    ok(!is_impersonating());
    same($admin['id'], current_user()['id'], 'back to the admin');
    same('expired', q_val('SELECT end_reason FROM impersonations ORDER BY id DESC LIMIT 1'));
    contains('ended automatically after 30 minutes', (string) q_val("SELECT summary FROM audit_log WHERE action = 'impersonate_end' ORDER BY id DESC LIMIT 1"));
});

test('B2: a revoked or suspended admin session ends the impersonation and logs everyone out', function (): void {
    rec_mailer();
    foreach (['revoked', 'suspended', 'demoted'] as $case) {
        $admin = imp_admin();
        $m = fixture_full_member();
        impersonation_start($admin, $m['user_id'], '', false, false);
        match ($case) {
            'revoked' => user_end_sessions((int) $admin['id']), // e.g. password changed elsewhere
            'suspended' => q("UPDATE users SET status = 'suspended' WHERE id = ?", [$admin['id']]),
            'demoted' => q("UPDATE users SET role = 'member' WHERE id = ?", [$admin['id']]),
        };
        same('redirect:/login', imp_guard('GET', '/account'), $case);
        ok(!isset($_SESSION['user_id']) && !isset($_SESSION['imp']), "$case: nobody stays logged in");
        same('admin_session_ended', q_val('SELECT end_reason FROM impersonations ORDER BY id DESC LIMIT 1'));
    }
});

test('B2: removing the permission or the member account ends the impersonation', function (): void {
    rec_mailer();
    $admin = imp_admin();
    $m = fixture_full_member();
    impersonation_start($admin, $m['user_id'], '', false, false);
    q("DELETE FROM admin_permissions WHERE user_id = ? AND permission = 'impersonate'", [$admin['id']]);
    same('redirect:/admin/members/' . $m['member_id'], imp_guard('GET', '/account'), 'still has the members permission: back to the member page');
    same($admin['id'], $_SESSION['user_id'], 'admin session continues');
    $admin2 = imp_admin();
    $m2 = fixture_full_member();
    impersonation_start($admin2, $m2['user_id'], '', false, false);
    account_delete($m2['user_id']);
    current_user(true);
    same('redirect:/admin/members/' . $m2['member_id'], imp_guard('GET', '/account'));
    same($admin2['id'], $_SESSION['user_id']);
    same('member_unavailable', q_val('SELECT end_reason FROM impersonations ORDER BY id DESC LIMIT 1'));
});

test('B2: logging out while impersonating ends both sessions', function (): void {
    rec_mailer();
    $admin = imp_admin();
    $m = fixture_full_member();
    impersonation_start($admin, $m['user_id'], '', false, false);
    same('/login', impersonation_end('logout', false));
    same([], array_intersect(['user_id', 'sv', 'imp'], array_keys($_SESSION)));
    same(null, current_user(true));
    same('logout', q_val('SELECT end_reason FROM impersonations ORDER BY id DESC LIMIT 1'));
    same($admin['id'], (int) q_val("SELECT user_id FROM audit_log WHERE action = 'impersonate_end' ORDER BY id DESC LIMIT 1"));
});

test('B2: the banner data names the member, number and end time', function (): void {
    rec_mailer();
    $admin = imp_admin();
    $m = fixture_full_member(['first_name' => 'Bea', 'last_name' => 'Nner']);
    q('UPDATE members SET member_number = ? WHERE id = ?', ['TST-26-0777', $m['member_id']]);
    same(null, impersonation_banner());
    impersonation_start($admin, $m['user_id'], '', false, false);
    $b = impersonation_banner();
    same(['Bea Nner', 'TST-26-0777', false], [$b['name'], $b['number'], $b['allow_changes']]);
    $html = view('partials/impersonation_banner');
    contains('You are viewing as <strong>Bea Nner</strong>', $html);
    contains('TST-26-0777', $html);
    contains('action="/impersonation/stop"', $html);
    contains('name="_csrf"', $html);
});

test('B2: the notice email template is editable and only uses its own placeholders', function (): void {
    $def = email_template_def('impersonation_notice');
    same([], email_template_validate('impersonation_notice', 'Notice from {site_name}', "Hi {first_name}, {admin_name} looked on {date}.", 'Open'));
    ok(email_template_validate('impersonation_notice', 'x', 'Your password is {password}', '') !== [], 'unknown placeholder refused');
    ok(isset($def['placeholders']['admin_name'], $def['placeholders']['date']));
});

// ---- Security review fixes (B2) -----------------------------------------------------

/** Throws from send(): the notice must never break the switch. */
final class ExplodingMailer implements \Core\Mail\Mailer
{
    public function send(\Core\Mail\Message $message): void
    {
        throw new Error('mail transport exploded');
    }
}

test('B2 review: refusals are generic (staff and unknown ids look the same)', function (): void {
    $staff = impersonation_refusal(impersonation_target(fixture_admin()));
    $none = impersonation_refusal(impersonation_target(987654));
    same($staff, $none);
    not_contains('Staff', (string) $staff);
});

test('B2 review: a failing notice email never breaks the switch and is recorded as failed', function (): void {
    mailer(new ExplodingMailer());
    $admin = imp_admin();
    $m = fixture_full_member();
    impersonation_start($admin, $m['user_id'], '', false, false);
    same($m['user_id'], $_SESSION['user_id'], 'the switch completed');
    same('failed', q_val('SELECT notified FROM impersonations ORDER BY id DESC LIMIT 1'));
    contains('member notice: failed', (string) q_val("SELECT summary FROM audit_log WHERE action = 'impersonate_start' ORDER BY id DESC LIMIT 1"));
});

test('B2 review: notice outcome sent/off is recorded; bounced addresses still get the notice', function (): void {
    $mailer = rec_mailer();
    $admin = imp_admin();
    $m = fixture_full_member();
    email_suppress($m['email'], 'hard_bounce', 'test', 'test');
    impersonation_start($admin, $m['user_id'], '', false, false);
    same('sent', q_val('SELECT notified FROM impersonations ORDER BY id DESC LIMIT 1'));
    same([$m['email']], $mailer->to(), 'impersonation_notice bypasses bounce suppression');
    ok(in_array('impersonation_notice', MAIL_BOUNCE_BYPASS_TEMPLATES, true));
    impersonation_end('returned');
    setting_set('impersonation.notify', false);
    settings_all([]);
    $m2 = fixture_full_member();
    impersonation_start(current_user(), $m2['user_id'], '', false, false);
    same('off', q_val('SELECT notified FROM impersonations ORDER BY id DESC LIMIT 1'));
    contains('member notice: off', (string) q_val("SELECT summary FROM audit_log WHERE action = 'impersonate_start' ORDER BY id DESC LIMIT 1"));
    setting_set('impersonation.notify', true);
    settings_all([]);
});

test('B2 review: audit entries use members.id / groups_orgs.id like other member and group entries', function (): void {
    rec_mailer();
    $admin = imp_admin();
    $m = fixture_full_member();
    impersonation_start($admin, $m['user_id'], '', false, false);
    impersonation_end('returned');
    $rows = q_all("SELECT action, item_type, item_id FROM audit_log WHERE action IN ('impersonate_start', 'impersonate_end') ORDER BY id DESC LIMIT 2");
    foreach ($rows as $r) {
        same(['member', $m['member_id']], [$r['item_type'], (int) $r['item_id']], $r['action']);
    }
    $gUid = create_user('grp-' . bin2hex(random_bytes(3)) . '@example.test', 'correct horse battery', 'group', 'Audit Group', 'active');
    $gid = db_insert('groups_orgs', ['user_id' => $gUid, 'name' => 'Audit Group', 'slug' => 'audit-group-' . bin2hex(random_bytes(3)), 'contact_person' => 'Gina']);
    impersonation_start(current_user(), $gUid, '', false, false);
    impersonation_end('returned');
    $rows = q_all("SELECT item_type, item_id FROM audit_log WHERE action IN ('impersonate_start', 'impersonate_end') ORDER BY id DESC LIMIT 2");
    same([['group', $gid], ['group', $gid]], array_map(static fn (array $r): array => [$r['item_type'], (int) $r['item_id']], $rows));
    same('/admin/groups/' . $gid . '/edit', impersonation_return_path('/admin/groups/' . $gid . '/edit', current_user()));
});

test('B2 review: impersonation evidence survives deleting the member account', function (): void {
    rec_mailer();
    $admin = imp_admin();
    $m = fixture_full_member(['first_name' => 'Gone', 'last_name' => 'Later']);
    impersonation_start($admin, $m['user_id'], 'evidence', true, false);
    audit('profile_update', 'member', $m['member_id'], 'profile details');
    impersonation_end('returned');
    $rowId = (int) q_val('SELECT MAX(id) FROM impersonations');
    account_delete($m['user_id']);
    $row = q_one('SELECT * FROM impersonations WHERE id = ?', [$rowId]);
    same([$m['user_id'], 'Gone Later', $m['email'], (int) $admin['id'], (string) $admin['email']], [(int) $row['user_id'], $row['user_name'], $row['user_email'], (int) $row['admin_id'], $row['admin_email']]);
    same($m['user_id'], (int) q_val("SELECT on_behalf_of FROM audit_log WHERE action = 'profile_update' ORDER BY id DESC LIMIT 1"), 'on_behalf_of kept');
});

test('B2 review: the return page falls back to /admin when the admin can no longer open it', function (): void {
    rec_mailer();
    $admin = imp_admin(['impersonate', 'members']);
    $m = fixture_full_member();
    impersonation_start($admin, $m['user_id'], '', false, false);
    q("DELETE FROM admin_permissions WHERE user_id = ? AND permission = 'members'", [$admin['id']]);
    same('/admin', impersonation_end('returned'), 'members permission removed meanwhile');
    same('/admin', impersonation_return_path('/admin/groups/3/edit', current_user()));
    same('/admin', impersonation_return_path('https://evil.example/', current_user()));
    // Impersonate permission removed (guard): also lands on a page the admin can open.
    $admin2 = imp_admin(['impersonate']);
    $m2 = fixture_full_member();
    impersonation_start($admin2, $m2['user_id'], '', false, false);
    $_SESSION['imp']['return_to'] = '/admin/groups/9/edit';
    q("DELETE FROM admin_permissions WHERE user_id = ? AND permission = 'impersonate'", [$admin2['id']]);
    same('redirect:/admin', imp_guard('GET', '/account'));
});
