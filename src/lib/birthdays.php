<?php
declare(strict_types=1);

use Core\Mail\Message;

/*
 * Daily birthday job (§4.5) at the configured local time (default 07:00):
 *  1. opted-in celebrants get the birthday greeting (one birthday_sends row
 *     per member per year makes it idempotent);
 *  2. D1 birthday notices for admins, each switchable in Settings →
 *     Birthdays: daily (today, plus the next 7 days), weekly (on Mondays:
 *     Monday-Sunday) and monthly (on the 1st: the whole month grouped by
 *     week). One email per recipient per day / ISO week / month (the queue's
 *     dedupe key), queued as essential admin notices (every suppressed
 *     address is skipped). Never the birth year or age.
 * 29 February is celebrated on 28 February in non-leap years.
 */

/** Local "HH:MM" when the job runs. */
function birthday_time(): string
{
    $t = (string) setting('birthdays.time', '07:00');
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) ? $t : '07:00';
}

/**
 * Month/day pairs for the local days from today to today + $days.
 * @return list<array{0:int,1:int,2:string}> [month, day, Y-m-d it is celebrated on]
 */
function birthday_dates_ahead(DateTimeImmutable $today, int $days): array
{
    $out = [];
    for ($i = 0; $i <= $days; $i++) {
        $d = $today->modify("+$i days");
        $m = (int) $d->format('n');
        $day = (int) $d->format('j');
        $out[] = [$m, $day, $d->format('Y-m-d')];
        if ($m === 2 && $day === 28 && $d->format('L') === '0') {
            $out[] = [2, 29, $d->format('Y-m-d')];
        }
    }
    return $out;
}

/** Active members with a birthday in the given month/day pairs, with the date they are celebrated. */
function birthday_members(array $dates): array
{
    if ($dates === []) {
        return [];
    }
    $cond = implode(' OR ', array_fill(0, count($dates), '(m.birth_month = ? AND m.birth_day = ?)'));
    $params = [];
    foreach ($dates as [$m, $d]) {
        array_push($params, $m, $d);
    }
    $rows = q_all("SELECT m.*, u.email FROM members m JOIN users u ON u.id = m.user_id WHERE u.status = 'active' AND u.role = 'member' AND ($cond) ORDER BY m.birth_month, m.birth_day, m.first_name", $params);
    $on = [];
    foreach ($dates as [$m, $d, $ymd]) {
        $on[$m . '-' . $d] = $ymd;
    }
    foreach ($rows as &$r) {
        $r['celebrated_on'] = $on[$r['birth_month'] . '-' . $r['birth_day']] ?? '';
    }
    unset($r);
    usort($rows, static fn (array $a, array $b): int => [$a['celebrated_on'], $a['first_name']] <=> [$b['celebrated_on'], $b['first_name']]);
    return $rows;
}

/** D1 settings: which notices go out, empty notices, and who receives them. */
function birthday_notice_settings(): array
{
    $s = setting('birthdays.notices');
    $s = is_array($s) ? $s : [];
    return [
        'daily' => (bool) ($s['daily'] ?? true),
        'weekly' => (bool) ($s['weekly'] ?? true),
        'monthly' => (bool) ($s['monthly'] ?? true),
        'skip_empty' => (bool) ($s['skip_empty'] ?? true),
        'recipients' => ($s['recipients'] ?? 'members') === 'chosen' ? 'chosen' : 'members',
        'chosen' => array_values(array_filter(array_map('intval', (array) ($s['chosen'] ?? [])), static fn (int $i): bool => $i > 0)),
    ];
}

/** Active staff who may be chosen as recipients (Settings → Birthdays). */
function birthday_staff_choices(): array
{
    return q_all("SELECT id, email, display_name, role FROM users WHERE role IN ('super_admin','admin') AND status = 'active' ORDER BY display_name, id");
}

/**
 * Who receives the notices: every admin who can manage members (super
 * admins and admins with the members permission) or the chosen people
 * (still active staff). Addresses on the suppression list are left out (A5).
 * @return list<array{id:int,email:string,display_name:string}>
 */
function birthday_notice_recipients(?array $settings = null): array
{
    $s = $settings ?? birthday_notice_settings();
    if ($s['recipients'] === 'chosen') {
        if ($s['chosen'] === []) {
            return [];
        }
        $rows = q_all("SELECT u.id, u.email, u.display_name FROM users u WHERE u.status = 'active' AND u.role IN ('super_admin','admin') AND u.id IN (" . db_placeholders(count($s['chosen'])) . ') ORDER BY u.id', $s['chosen']);
    } else {
        $rows = q_all("SELECT u.id, u.email, u.display_name FROM users u WHERE u.status = 'active' AND (u.role = 'super_admin' OR (u.role = 'admin' AND EXISTS (SELECT 1 FROM admin_permissions p WHERE p.user_id = u.id AND p.permission = 'members'))) ORDER BY u.id");
    }
    $out = [];
    foreach ($rows as $r) {
        if (email_suppression((string) $r['email']) === null) {
            $out[] = ['id' => (int) $r['id'], 'email' => (string) $r['email'], 'display_name' => (string) $r['display_name']];
        }
    }
    return $out;
}

/** Is this staff user one of the notice recipients (bell count)? Suppression does not matter here. */
function birthday_is_recipient(?array $user): bool
{
    if (!is_staff($user)) {
        return false;
    }
    $s = birthday_notice_settings();
    return $s['recipients'] === 'chosen' ? in_array((int) $user['id'], $s['chosen'], true) : user_can($user, 'members');
}

/**
 * Local days of a period: 'today', 'week' (Monday to Sunday of the week
 * containing $today) or 'month' (the whole month).
 * @return array{0:DateTimeImmutable,1:int} [first day, number of further days]
 */
function birthday_period_span(string $period, DateTimeImmutable $today): array
{
    $today = $today->setTime(0, 0);
    return match ($period) {
        'week' => [$today->modify('monday this week'), 6],
        'month' => [$first = $today->modify('first day of this month'), (int) $first->format('t') - 1],
        default => [$today, 0],
    };
}

/** Members with a birthday in the period (with celebrated_on), sorted by date then name. */
function birthday_period_members(string $period, DateTimeImmutable $today): array
{
    [$from, $days] = birthday_period_span($period, $today);
    return birthday_members(birthday_dates_ahead($from, $days));
}

/** Number of birthdays in a period (dashboard tabs, bell), cached per request. */
function birthday_count(string $period, ?DateTimeImmutable $today = null): int
{
    static $cache = [];
    $today ??= (new DateTimeImmutable('now', site_tz()))->setTime(0, 0);
    $key = $period . ':' . $today->format('Y-m-d');
    return $cache[$key] ??= count(birthday_period_members($period, $today));
}

/** "Fri 30 Oct" (plus a 29 Feb note when the birthday is moved to 28 Feb). Never the year. */
function birthday_day_label(array $m, string $format = 'D j M'): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $m['celebrated_on'], site_tz());
    $label = $d ? $d->format($format) : '';
    if ((int) $m['birth_month'] === 2 && (int) $m['birth_day'] === 29) {
        $label .= $d && $d->format('j') === '28' ? ' (birthday 29 Feb, celebrated on 28 Feb this year)' : ' (29 Feb)';
    }
    return $label;
}

/** One notice line: name, member number, day; with links to the admin page and "Send a message" (C3). */
function birthday_notice_line(array $m, bool $links = true): string
{
    $line = '• ' . member_full_name($m) . ($m['member_number'] ? ' (' . $m['member_number'] . ')' : '') . ' · ' . birthday_day_label($m);
    if ($links) {
        $line .= "\n  Member page: " . base_url('/admin/members/' . (int) $m['id'])
            . "\n  Send a message: " . base_url('/admin/messages/new?to=' . (int) $m['user_id']);
    }
    return $line;
}

/** Monthly list grouped by week ("Week of 2 March"), weeks starting on Monday. */
function birthday_month_list(array $members, DateTimeImmutable $first): string
{
    $groups = [];
    foreach ($members as $m) {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $m['celebrated_on'], site_tz());
        if ($d === false) {
            continue;
        }
        $monday = $d->modify('monday this week');
        $start = $monday < $first ? $first : $monday;
        $groups[$start->format('Y-m-d')]['label'] = 'Week of ' . $start->format('j F');
        $groups[$start->format('Y-m-d')]['lines'][] = birthday_notice_line($m);
    }
    $out = [];
    foreach ($groups as $g) {
        $out[] = $g['label'] . "\n" . implode("\n", $g['lines']);
    }
    return implode("\n\n", $out);
}

/**
 * Run the birthday job for the local day of $now: greetings, then the
 * daily, weekly (Mondays) and monthly (1st) notices for admins.
 * @return array{greeted:int, already:int, digests:int, weekly:int, monthly:int}
 */
function birthday_run(?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable('now', utc());
    $local = $now->setTimezone(site_tz());
    $today = $local->setTime(0, 0);
    $year = (int) $today->format('Y');
    $stats = ['greeted' => 0, 'already' => 0, 'digests' => 0, 'weekly' => 0, 'monthly' => 0];

    foreach (birthday_members(birthday_dates_ahead($today, 0)) as $m) {
        if (!member_pref($m, 'birthday_greeting')) {
            continue;
        }
        // The insert is the idempotency check: only the first run of the year sends.
        if (q('INSERT IGNORE INTO birthday_sends (member_id, year) VALUES (?, ?)', [$m['id'], $year])->rowCount() !== 1) {
            $stats['already']++;
            continue;
        }
        $p = email_template_render('birthday_greeting', ['first_name' => $m['first_name'], 'preferences_url' => base_url('/account/preferences'), 'account_url' => base_url('/account')]);
        try {
            mail_queue_message(new Message((string) $m['email'], member_full_name($m), $p['subject'], $p['text'], $p['html']), 'birthday', 'birthday:' . $m['id'] . ':' . $year, 0, '', $now->getTimestamp());
            $stats['greeted']++;
        } catch (InvalidArgumentException $e) {
            error_log('Birthday greeting not queued: ' . $e->getMessage());
        }
    }

    $s = birthday_notice_settings();
    $recipients = null;
    $send = static function (string $template, string $dedupe, array $vars) use (&$recipients, $s, $now): int {
        $recipients ??= birthday_notice_recipients($s);
        $n = 0;
        foreach ($recipients as $a) {
            $p = email_template_render($template, $vars + ['first_name' => explode(' ', $a['display_name'])[0], 'admin_url' => base_url('/admin/members/birthdays')]);
            try {
                if (mail_queue_message(new Message($a['email'], $a['display_name'], $p['subject'], $p['text'], $p['html']), 'digest', $dedupe . ':' . $a['id'], 0, '', $now->getTimestamp())) {
                    $n++;
                }
            } catch (InvalidArgumentException) {
                continue;
            }
        }
        return $n;
    };

    if ($s['daily']) {
        $todayList = birthday_period_members('today', $today);
        if ($todayList !== [] || !$s['skip_empty']) {
            $todayYmd = $today->format('Y-m-d');
            $next = array_values(array_filter(birthday_members(birthday_dates_ahead($today, 7)), static fn (array $m): bool => $m['celebrated_on'] !== $todayYmd));
            $stats['digests'] += $send('birthday_digest', 'bday:d:' . $todayYmd, [
                'date' => $local->format('l j F Y'), 'count' => (string) count($todayList),
                'today_list' => $todayList ? implode("\n", array_map('birthday_notice_line', $todayList)) : 'No birthdays today.',
                'week_list' => $next ? implode("\n", array_map(static fn (array $m): string => birthday_notice_line($m, false), $next)) : 'None.',
            ]);
        }
    }
    if ($s['weekly'] && $today->format('N') === '1') {
        $week = birthday_period_members('week', $today);
        if ($week !== [] || !$s['skip_empty']) {
            $stats['weekly'] = $send('birthday_digest_weekly', 'bday:w:' . $today->format('o-\WW'), [
                'week' => $today->format('j M') . ' – ' . $today->modify('+6 days')->format('j M Y'), 'count' => (string) count($week),
                'birthday_list' => $week ? implode("\n", array_map('birthday_notice_line', $week)) : 'No birthdays this week.',
            ]);
            $stats['digests'] += $stats['weekly'];
        }
    }
    if ($s['monthly'] && $today->format('j') === '1') {
        $month = birthday_period_members('month', $today);
        if ($month !== [] || !$s['skip_empty']) {
            $stats['monthly'] = $send('birthday_digest_monthly', 'bday:m:' . $today->format('Y-m'), [
                'month' => $today->format('F Y'), 'count' => (string) count($month),
                'birthday_list' => $month ? birthday_month_list($month, $today) : 'No birthdays this month.',
            ]);
            $stats['digests'] += $stats['monthly'];
        }
    }
    return $stats;
}

/** Cron: once per local day, at or after the configured time. */
function cron_birthdays(?DateTimeImmutable $now = null): string
{
    $s = birthday_run($now);
    return "{$s['greeted']} greeting(s), {$s['digests']} notice(s)";
}
