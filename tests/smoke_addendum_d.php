<?php
declare(strict_types=1);

// Addendum D1 smoke checks through php -S: birthday list and CSV, notice
// settings, overview tabs and the bell. Included by tests/smoke.php after
// smoke_addendum_c3.php (uses Browser, check(), $admin (super admin), $base,
// login_as(), meta_csrf(), status_is()).

$bdToday = new DateTimeImmutable('now', site_tz());
$bdUid = create_user('bday.smoke@example.test', 'bday-smoke-password', 'member', 'Bea Birthday', 'active');
$bdMid = db_insert('members', ['user_id' => $bdUid, 'first_name' => 'Bea', 'last_name' => 'Birthday', 'membership_type' => 'Standard',
    'birth_day' => (int) $bdToday->format('j'), 'birth_month' => (int) $bdToday->format('n'), 'birth_year' => 1977, 'member_number' => 'BDY-26-0001',
    'verified_at' => gmdate('Y-m-d H:i:s'), 'prefs' => json_encode(['newsletters' => false])]);

$h = $admin->get('/admin/members/birthdays');
check('D1: Members → Birthdays lists this month with number, day and a message link', $h['status'] === 200 && str_contains($h['body'], 'Bea Birthday') && str_contains($h['body'], 'BDY-26-0001')
    && str_contains($h['body'], '/admin/messages/new?to=' . $bdUid) && !str_contains($h['body'], '1977'));
$other = (int) $bdToday->modify('first day of next month')->format('n');
check('D1: … filtered by month', !str_contains($admin->get('/admin/members/birthdays?month=' . $other)['body'], 'Bea Birthday'));
$r = $admin->get('/admin/members/birthdays.csv?month=' . (int) $bdToday->format('n'));
check('D1: CSV export has name, number, day and month only (no year)', $r['status'] === 200 && str_contains($r['type'], 'text/csv') && str_contains($r['body'], "name,member_number,day,month")
    && str_contains($r['body'], '"Bea Birthday",BDY-26-0001,' . $bdToday->format('j') . ',' . $bdToday->format('n')) && !str_contains($r['body'], '1977'));
check('D1: the export is audited', (int) q_val("SELECT COUNT(*) FROM audit_log WHERE action = 'export' AND item_type = 'birthdays'") >= 1);

$h = $admin->get('/admin');
check('D1: overview birthday card has Today / This week / This month tabs with counts', str_contains($h['body'], 'href="/admin?bday=week"') && str_contains($h['body'], 'href="/admin?bday=month"')
    && (bool) preg_match('#aria-current="page">Today <span class="count tabular">\d+</span>#', $h['body']) && str_contains($h['body'], 'Bea Birthday'));
check('D1: … the week tab works without JavaScript', str_contains($admin->get('/admin?bday=week')['body'], 'aria-current="page">This week'));
check('D1: the bell says how many birthdays today', (bool) preg_match('/title="[^"]*\d+ birthdays? today/', $h['body']));

status_is($admin, '/admin/settings/birthdays', 200, 'Birthday notices');
$r = $admin->submit('/admin/settings/birthdays', '/admin/settings/birthdays', ['daily' => '1', 'recipients' => 'chosen']);
check('D1: "only these people" without anyone is refused', $r['status'] === 303 && str_contains($admin->get('/admin/settings/birthdays')['body'], 'Choose at least one person'));
$samId = (int) q_val("SELECT id FROM users WHERE email = 'sam@example.test'");
$r = $admin->submit('/admin/settings/birthdays', '/admin/settings/birthdays', ['daily' => '1', 'monthly' => '1', 'skip_empty' => '1', 'recipients' => 'chosen', 'chosen[]' => (string) $samId]);
$saved = setting_fresh('birthdays.notices');
check('D1: settings saved (weekly off, chosen recipient) and audited', $r['status'] === 303 && $saved['weekly'] === false && $saved['monthly'] === true && $saved['chosen'] === [$samId]
    && (int) q_val("SELECT COUNT(*) FROM audit_log WHERE item_type = 'settings' AND summary LIKE 'birthday notices%'") >= 1);
create_user('bday-radio@example.test', 'bday-radio-password', 'admin', 'Radio Only', 'active', ['radio']);
$radioOnly = login_as($base, 'bday-radio@example.test', 'bday-radio-password');
check('D1: admins without the members permission get no birthday list or card', $radioOnly->get('/admin/members/birthdays')['status'] === 403 && !str_contains($radioOnly->get('/admin')['body'], 'bday-title'));
$admin->submit('/admin/settings/birthdays', '/admin/settings/birthdays', ['daily' => '1', 'weekly' => '1', 'monthly' => '1', 'skip_empty' => '1', 'recipients' => 'members']);
q('DELETE FROM users WHERE id = ?', [$bdUid]);
unset($bdMid);
