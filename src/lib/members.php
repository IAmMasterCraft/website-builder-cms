<?php
declare(strict_types=1);

/*
 * Membership (§4). Birthdays are here because the birthday-celebrants
 * section (§3.1) and the dashboard share them.
 */

/**
 * Month/day pairs that count as a birthday on the given local days.
 * 29 February is celebrated on 28 February in non-leap years (§4.5).
 * @return list<array{0:int,1:int}>
 */
function birthday_days(string $range, ?DateTimeImmutable $now = null): array
{
    $today = ($now ?? new DateTimeImmutable('now', site_tz()))->setTimezone(site_tz())->setTime(0, 0);
    $n = $range === 'week' ? 7 : 1;
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $d = $today->modify("+$i days");
        $m = (int) $d->format('n');
        $day = (int) $d->format('j');
        $out[] = [$m, $day];
        if ($m === 2 && $day === 28 && $d->format('L') === '0') {
            $out[] = [2, 29];
        }
    }
    return $out;
}

/**
 * Active members who opted in to the celebrants list, birthday within range.
 * Returns display names only (first name + last initial): no year, no photo.
 * @return list<array{name:string, day:int, month:int, initial:string}>
 */
function birthday_celebrants(string $range, ?DateTimeImmutable $now = null): array
{
    $days = birthday_days($range, $now);
    $cond = implode(' OR ', array_fill(0, count($days), '(m.birth_month = ? AND m.birth_day = ?)'));
    $rows = q_all(
        "SELECT m.first_name, m.last_name, m.birth_day, m.birth_month, m.prefs FROM members m JOIN users u ON u.id = m.user_id
          WHERE u.status = 'active' AND ($cond) ORDER BY m.birth_month, m.birth_day, m.first_name LIMIT 200",
        array_merge(...$days)
    );
    $out = [];
    foreach ($rows as $r) {
        if (!member_pref($r, 'celebrants')) {
            continue;
        }
        $last = trim((string) $r['last_name']);
        $out[] = [
            'name' => trim($r['first_name'] . ($last !== '' ? ' ' . mb_substr($last, 0, 1) . '.' : '')),
            'initial' => mb_strtoupper(mb_substr((string) $r['first_name'], 0, 1)),
            'day' => (int) $r['birth_day'], 'month' => (int) $r['birth_month'],
        ];
    }
    return $out;
}

/** Email and privacy preferences (§4.4) with their defaults. */
function member_pref_defaults(): array
{
    return ['newsletters' => false, 'birthday_greeting' => true, 'celebrants' => false];
}

function member_prefs(array $memberRow): array
{
    $p = json_decode((string) ($memberRow['prefs'] ?? ''), true);
    $out = member_pref_defaults();
    foreach ($out as $k => $v) {
        if (is_array($p) && array_key_exists($k, $p)) {
            $out[$k] = (bool) $p[$k];
        }
    }
    return $out;
}

function member_pref(array $memberRow, string $key): bool
{
    return member_prefs($memberRow)[$key] ?? false;
}
