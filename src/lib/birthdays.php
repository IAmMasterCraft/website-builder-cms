<?php
declare(strict_types=1);

use Core\Mail\Message;

/*
 * Daily birthday job (§4.5) at the configured local time (default 07:00):
 *  1. opted-in celebrants get the birthday greeting (one birthday_sends row
 *     per member per year makes it idempotent);
 *  2. admins get a digest of today's and the next 7 days' birthdays (once
 *     per local date).
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

/**
 * Run the birthday job for the local day of $now.
 * @return array{greeted:int, already:int, digests:int}
 */
function birthday_run(?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable('now', utc());
    $local = $now->setTimezone(site_tz());
    $today = $local->setTime(0, 0);
    $year = (int) $today->format('Y');
    $stats = ['greeted' => 0, 'already' => 0, 'digests' => 0];

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

    $upcoming = birthday_members(birthday_dates_ahead($today, 7));
    $mark = 'birthdays.digest.' . $today->format('Y-m-d');
    if ($upcoming !== [] && q('INSERT IGNORE INTO settings (`key`, `value`) VALUES (?, ?)', [$mark, json_encode(true)])->rowCount() === 1) {
        $fmt = static function (array $m): string {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $m['celebrated_on'], site_tz());
            return member_full_name($m) . ($m['member_number'] ? ' (' . $m['member_number'] . ')' : '') . ' · ' . ($d ? $d->format('D j M') : '') . ((int) $m['birth_month'] === 2 && (int) $m['birth_day'] === 29 ? ' (29 Feb)' : '');
        };
        $todayYmd = $today->format('Y-m-d');
        $todayList = array_map($fmt, array_values(array_filter($upcoming, static fn (array $m): bool => $m['celebrated_on'] === $todayYmd)));
        $weekList = array_map($fmt, array_values(array_filter($upcoming, static fn (array $m): bool => $m['celebrated_on'] !== $todayYmd)));
        $admins = q_all("SELECT u.id, u.email, u.display_name FROM users u WHERE u.status = 'active' AND (u.role = 'super_admin' OR (u.role = 'admin' AND EXISTS (SELECT 1 FROM admin_permissions p WHERE p.user_id = u.id AND p.permission = 'members')))");
        foreach ($admins as $a) {
            $p = email_template_render('birthday_digest', [
                'first_name' => explode(' ', (string) $a['display_name'])[0], 'date' => $local->format('l j F Y'),
                'today_list' => $todayList ? implode("\n", $todayList) : 'No birthdays today.', 'week_list' => $weekList ? implode("\n", $weekList) : 'None.',
                'admin_url' => base_url('/admin'),
            ]);
            try {
                mail_queue_message(new Message((string) $a['email'], (string) $a['display_name'], $p['subject'], $p['text'], $p['html']), 'digest', $mark . ':' . $a['id'], 0, '', $now->getTimestamp());
                $stats['digests']++;
            } catch (InvalidArgumentException) {
                continue;
            }
        }
    }
    return $stats;
}

/** Cron: once per local day, at or after the configured time. */
function cron_birthdays(?DateTimeImmutable $now = null): string
{
    $s = birthday_run($now);
    return "{$s['greeted']} greeting(s), {$s['digests']} digest(s)";
}
