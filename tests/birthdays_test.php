<?php
declare(strict_types=1);

// Addendum D1: daily, weekly and monthly birthday notices for admins.

function bday_reset(array $settings = []): void
{
    reset_comms();
    q("DELETE FROM users WHERE role IN ('member','group')");
    setting_set('birthdays.notices', $settings + ['daily' => true, 'weekly' => true, 'monthly' => true, 'skip_empty' => true, 'recipients' => 'members', 'chosen' => []]);
}

/** Queued notice rows for a dedupe prefix: to_email => text body. */
function bday_queued(string $prefix): array
{
    $out = [];
    foreach (q_all('SELECT to_email, text_body FROM email_queue WHERE dedupe_key LIKE ?', [$prefix . '%']) as $r) {
        $out[(string) $r['to_email']] = (string) $r['text_body'];
    }
    return $out;
}

test('D1: notice settings default to all three on, skip empty, admins who manage members', function (): void {
    q("DELETE FROM settings WHERE `key` = 'birthdays.notices'");
    settings_all([]);
    $s = birthday_notice_settings();
    same([true, true, true, true, 'members', []], [$s['daily'], $s['weekly'], $s['monthly'], $s['skip_empty'], $s['recipients'], $s['chosen']]);
    ok(isset(email_template_defs()['birthday_digest_weekly'], email_template_defs()['birthday_digest_monthly'], email_template_defs()['birthday_digest']));
});

test('D1: weekly notice on Mondays only (Monday-Sunday), once per recipient per ISO week', function (): void {
    bday_reset();
    $tz = site_tz();
    $super = fixture_admin('super_admin');
    $membersAdmin = fixture_admin('admin', ['members']);
    $radioAdmin = fixture_admin('admin', ['radio']);
    $mon = fixture_full_member(['first_name' => 'Monday', 'birth_day' => 2, 'birth_month' => 3, 'number' => 'BD-1']);
    $sun = fixture_full_member(['first_name' => 'Sunday', 'birth_day' => 8, 'birth_month' => 3]);
    $next = fixture_full_member(['first_name' => 'NextMon', 'birth_day' => 9, 'birth_month' => 3]);
    q('UPDATE members SET birth_year = 1980 WHERE id = ?', [$mon['member_id']]);
    // 2026-03-02 is a Monday.
    $s = birthday_run(new DateTimeImmutable('2026-03-02 08:00', $tz));
    ok($s['weekly'] >= 2, 'weekly notices queued');
    $w = bday_queued('bday:w:2026-W10:');
    $superEmail = (string) q_val('SELECT email FROM users WHERE id = ?', [$super]);
    $membersEmail = (string) q_val('SELECT email FROM users WHERE id = ?', [$membersAdmin]);
    $radioEmail = (string) q_val('SELECT email FROM users WHERE id = ?', [$radioAdmin]);
    ok(isset($w[$superEmail], $w[$membersEmail]), 'super admins and members admins');
    ok(!isset($w[$radioEmail]), 'not admins without the members permission');
    $body = $w[$superEmail];
    contains('Monday Okafor (BD-1) · Mon 2 Mar', $body);
    contains('Sunday Okafor', $body);
    not_contains('NextMon', $body, 'next Monday belongs to next week');
    not_contains('1980', $body, 'never the birth year');
    contains('/admin/members/' . $mon['member_id'], $body);
    contains('/admin/messages/new?to=' . $mon['user_id'], $body);
    same(0, birthday_run(new DateTimeImmutable('2026-03-02 15:00', $tz))['weekly'], 'second run the same week sends nothing');
    same(0, birthday_run(new DateTimeImmutable('2026-03-03 08:00', $tz))['weekly'], 'not on Tuesdays');
    unset($sun, $next);
});

test('D1: monthly notice on the 1st, grouped by week, 29 Feb on 28 Feb in non-leap years', function (): void {
    bday_reset();
    $tz = site_tz();
    $super = fixture_admin('super_admin');
    $leap = fixture_full_member(['first_name' => 'Leap', 'birth_day' => 29, 'birth_month' => 2]);
    $early = fixture_full_member(['first_name' => 'Early', 'birth_day' => 3, 'birth_month' => 2]);
    $s = birthday_run(new DateTimeImmutable('2027-02-01 09:00', $tz));
    ok($s['monthly'] >= 1);
    $body = bday_queued('bday:m:2027-02:')[(string) q_val('SELECT email FROM users WHERE id = ?', [$super])] ?? '';
    contains('Week of 1 February', $body);
    contains('Early Okafor', $body);
    contains('Week of 22 February', $body);
    contains('Leap Okafor · Sun 28 Feb (birthday 29 Feb, celebrated on 28 Feb this year)', $body);
    same(0, birthday_run(new DateTimeImmutable('2027-02-01 18:00', $tz))['monthly'], 'idempotent per month');
    same(0, birthday_run(new DateTimeImmutable('2027-02-02 09:00', $tz))['monthly'], 'only on the 1st');
    // Leap year: 29 Feb itself.
    birthday_run(new DateTimeImmutable('2028-02-01 09:00', $tz));
    $body = bday_queued('bday:m:2028-02:')[(string) q_val('SELECT email FROM users WHERE id = ?', [$super])] ?? '';
    contains('Leap Okafor · Tue 29 Feb (29 Feb)', $body);
    unset($early, $leap);
});

test('D1: daily notice, skip empty switch and per-notice switches', function (): void {
    bday_reset();
    $tz = site_tz();
    $super = fixture_admin('super_admin');
    $email = (string) q_val('SELECT email FROM users WHERE id = ?', [$super]);
    // Nobody has a birthday on 10 June: nothing with skip_empty on.
    fixture_full_member(['first_name' => 'Soon', 'birth_day' => 12, 'birth_month' => 6]);
    same(0, birthday_run(new DateTimeImmutable('2026-06-10 08:00', $tz))['digests']);
    bday_reset(['skip_empty' => false]);
    $super = fixture_admin('super_admin');
    $email = (string) q_val('SELECT email FROM users WHERE id = ?', [$super]);
    fixture_full_member(['first_name' => 'Soon', 'birth_day' => 12, 'birth_month' => 6]);
    birthday_run(new DateTimeImmutable('2026-06-10 08:00', $tz));
    $body = bday_queued('bday:d:2026-06-10:')[$email] ?? '';
    contains('No birthdays today.', $body);
    contains('Soon Okafor', $body, 'next 7 days');
    bday_reset(['daily' => false, 'weekly' => false, 'monthly' => false]);
    fixture_admin('super_admin');
    fixture_full_member(['first_name' => 'Today', 'birth_day' => 1, 'birth_month' => 6]);
    $s = birthday_run(new DateTimeImmutable('2026-06-01 08:00', $tz)); // a Monday and the 1st
    same([1, 0], [$s['greeted'], $s['digests']], 'greetings still go out with every notice switched off');
});

test('D1: chosen recipients, suppressed admins skipped, essential at send time', function (): void {
    bday_reset();
    $tz = site_tz();
    $a = fixture_admin('admin', ['members', 'radio']);
    $b = fixture_admin('super_admin');
    $c = fixture_admin('admin', ['members']);
    $radioOnly = fixture_admin('admin', ['radio']);
    $emails = array_column(q_all('SELECT id, email FROM users WHERE id IN (?, ?, ?)', [$a, $b, $c]), 'email', 'id');
    q("INSERT INTO email_suppressions (email, reason) VALUES (?, 'unsubscribe')", [$emails[$c]]);
    setting_set('birthdays.notices', ['recipients' => 'chosen', 'chosen' => [$a, $c, $radioOnly]]);
    $ids = array_column(birthday_notice_recipients(), 'id');
    same([$a], $ids, 'chosen people only, suppressed address skipped, no one without the members permission');
    ok(!in_array($radioOnly, array_map('intval', array_column(birthday_staff_choices(), 'id')), true), 'review: only members admins and super admins can be chosen');
    ok(!birthday_is_recipient(dm_user($radioOnly)));
    ok(birthday_is_recipient(dm_user($a)) && !birthday_is_recipient(dm_user($b)));
    fixture_full_member(['first_name' => 'Today', 'birth_day' => 5, 'birth_month' => 5]);
    birthday_run(new DateTimeImmutable('2026-05-05 08:00', $tz));
    same([$emails[$a]], array_keys(bday_queued('bday:d:2026-05-05:')));
    // An address suppressed after queueing is skipped when the queue runs.
    q("INSERT INTO email_suppressions (email, reason) VALUES (?, 'complaint')", [$emails[$a]]);
    rec_mailer();
    mail_queue_run(time() + 60);
    same('suppressed', q_val("SELECT status FROM email_queue WHERE dedupe_key LIKE 'bday:d:2026-05-05:%'"));
    contains('2 birthdays today', admin_notification_label(['birthdays' => 2]));
});

test('D1: dashboard counts and periods (week = Monday-Sunday, month)', function (): void {
    bday_reset();
    $tz = site_tz();
    fixture_full_member(['first_name' => 'A', 'birth_day' => 30, 'birth_month' => 10]);
    fixture_full_member(['first_name' => 'B', 'birth_day' => 1, 'birth_month' => 11]);
    fixture_full_member(['first_name' => 'C', 'birth_day' => 26, 'birth_month' => 10]);
    $today = new DateTimeImmutable('2026-10-30', $tz); // a Friday
    same(1, birthday_count('today', $today));
    same(3, birthday_count('week', $today), 'Mon 26 Oct - Sun 1 Nov');
    same(2, birthday_count('month', $today));
});
