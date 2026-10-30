<?php
declare(strict_types=1);

namespace Admin;

/**
 * D1: birthday list for admins with the members permission (filter by
 * month, CSV export) and the super admin's notice settings (daily / weekly /
 * monthly emails and who receives them).
 */
final class Birthdays
{
    private static function month(): int
    {
        $m = (int) query_param('month');
        return $m >= 1 && $m <= 12 ? $m : (int) (new \DateTimeImmutable('now', site_tz()))->format('n');
    }

    /** Members with a birthday in month $m of the current year (29 Feb on 28 Feb in non-leap years). */
    private static function rows(int $m): array
    {
        $year = (int) (new \DateTimeImmutable('now', site_tz()))->format('Y');
        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m), site_tz());
        return birthday_period_members('month', $first);
    }

    public static function index(array $p): void
    {
        require_permission('members');
        $m = self::month();
        render('admin/birthdays', ['title' => 'Birthdays', 'month' => $m, 'rows' => self::rows($m), 'today' => (new \DateTimeImmutable('now', site_tz()))->format('Y-m-d')], 'admin');
    }

    public static function csv(array $p): void
    {
        require_permission('members');
        $m = self::month();
        $rows = self::rows($m);
        audit('export', 'birthdays', null, count($rows) . ' rows (' . date('F', mktime(0, 0, 0, $m, 1)) . ')');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="birthdays-' . sprintf('%02d', $m) . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['name', 'member_number', 'day', 'month'], ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($out, [csv_safe(member_full_name($r)), csv_safe((string) $r['member_number']), (string) (int) $r['birth_day'], (string) (int) $r['birth_month']], ',', '"', '');
        }
        fclose($out);
    }

    public static function settings(array $p): void
    {
        require_permission('super_admin');
        $old = take_old_input();
        render('admin/birthday_settings', [
            'title' => 'Birthday notices', 's' => birthday_notice_settings(), 'errors' => $old['errors'] ?? [],
            'staff' => birthday_staff_choices(), 'recipients' => birthday_notice_recipients(), 'time' => birthday_time(),
        ], 'admin');
    }

    public static function saveSettings(array $p): void
    {
        csrf_check();
        require_permission('super_admin');
        $staffIds = array_map('intval', array_column(birthday_staff_choices(), 'id'));
        $chosen = array_values(array_intersect(array_map('intval', input_list('chosen')), $staffIds));
        $mode = input('recipients') === 'chosen' ? 'chosen' : 'members';
        if ($mode === 'chosen' && $chosen === []) {
            remember_input($_POST, ['chosen' => 'Choose at least one person, or send to every admin who can manage members.']);
            flash('Choose at least one person to receive the birthday notices.', 'error');
            redirect('/admin/settings/birthdays');
        }
        $s = [
            'daily' => input('daily') === '1', 'weekly' => input('weekly') === '1', 'monthly' => input('monthly') === '1',
            'skip_empty' => input('skip_empty') === '1', 'recipients' => $mode, 'chosen' => $chosen,
        ];
        setting_set('birthdays.notices', $s);
        audit('update', 'settings', null, 'birthday notices: ' . implode(', ', array_keys(array_filter(['daily' => $s['daily'], 'weekly' => $s['weekly'], 'monthly' => $s['monthly']]))) . '; recipients: ' . ($mode === 'chosen' ? count($chosen) . ' chosen' : 'admins who manage members'));
        flash('Birthday notice settings saved.');
        redirect('/admin/settings/birthdays');
    }
}
