<?php
declare(strict_types=1);

/*
 * Online radio (§6). The station is built from uploaded audio/video:
 *  - slots play one media item or a playlist from a start time, repeating
 *    none/daily/weekly (same local wall-clock time in the site time zone;
 *    every occurrence is compared in UTC); slots never overlap;
 *  - when no slot is active the fallback loop playlist plays, aligned to
 *    the Unix epoch so every listener hears the same position;
 *  - with no slot and an empty loop the station is off air.
 * Durations are measured in the admin's browser at upload; media with an
 * unknown duration cannot be scheduled.
 */

const RADIO_MAX_SLOT_SECONDS = 86400;
const RADIO_CHECK_WINDOW_DAYS = 18;
const RADIO_LOOKAHEAD_DAYS = 14;
const RADIO_DAY_NAMES = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

function radio_enabled(): bool
{
    return (bool) setting('radio.enabled', false);
}

function radio_loop_playlist_id(): ?int
{
    $id = (int) setting('radio.loop_playlist_id', 0);
    return $id > 0 ? $id : null;
}

/** Duration typed by the admin's browser: null for unknown, else seconds (0 < d <= 24 h). */
function radio_parse_duration(string $raw): ?float
{
    $raw = trim($raw);
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }
    $d = round((float) $raw, 3);
    return $d > 0 && $d <= RADIO_MAX_SLOT_SECONDS && is_finite($d) ? $d : null;
}

function radio_fmt_duration(?float $s): string
{
    if ($s === null) {
        return 'unknown';
    }
    $s = (int) round($s);
    return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
}

/** radio_media rows joined with their file. */
function radio_media_sql(): string
{
    return 'SELECT r.*, m.kind, m.mime, m.path, m.original_name, m.visibility, m.size, c.name AS category_name, c.slug AS category_slug
              FROM radio_media r JOIN media m ON m.id = r.media_id LEFT JOIN radio_categories c ON c.id = r.category_id';
}

function radio_media_find(int $id): ?array
{
    return q_one(radio_media_sql() . ' WHERE r.id = ?', [$id]);
}

/** Where a radio item is used (playlists, slots); deletion is blocked while used (§12). */
function radio_media_usage(int $id): array
{
    $out = [];
    foreach (q_all('SELECT DISTINCT p.id, p.name FROM radio_playlist_items i JOIN radio_playlists p ON p.id = i.playlist_id WHERE i.radio_media_id = ?', [$id]) as $p) {
        $out[] = 'Playlist “' . $p['name'] . '”';
    }
    $n = (int) q_val('SELECT COUNT(*) FROM radio_slots WHERE media_id = ?', [$id]);
    if ($n > 0) {
        $out[] = pluralise($n, 'schedule slot');
    }
    return $out;
}

/** @return list<array> items in order with their media data */
function radio_playlist_items(int $playlistId): array
{
    return q_all(
        'SELECT r.*, m.kind, m.mime, m.path, m.original_name, i.id AS item_id, i.sort
           FROM radio_playlist_items i JOIN radio_media r ON r.id = i.radio_media_id JOIN media m ON m.id = r.media_id
          WHERE i.playlist_id = ? ORDER BY i.sort, i.id',
        [$playlistId]
    );
}

/** Total seconds; null when empty or any item has an unknown duration. */
function radio_playlist_duration(int $playlistId): ?float
{
    $items = radio_playlist_items($playlistId);
    if ($items === []) {
        return null;
    }
    $total = 0.0;
    foreach ($items as $i) {
        if ($i['duration'] === null || (float) $i['duration'] <= 0) {
            return null;
        }
        $total += (float) $i['duration'];
    }
    return $total;
}

/** Slot length for a source, or an error message. @return array{0:?int,1:?string} */
function radio_source_duration(?int $mediaId, ?int $playlistId): array
{
    if ($mediaId !== null) {
        $m = radio_media_find($mediaId);
        if ($m === null) {
            return [null, 'Choose a media item.'];
        }
        if ($m['duration'] === null) {
            return [null, '“' . $m['title'] . '” has an unknown duration, so it cannot be scheduled. Open it in the library and measure its duration first.'];
        }
        $d = (float) $m['duration'];
    } elseif ($playlistId !== null) {
        if (q_val('SELECT id FROM radio_playlists WHERE id = ?', [$playlistId]) === null) {
            return [null, 'Choose a playlist.'];
        }
        $d = radio_playlist_duration($playlistId);
        if ($d === null) {
            return [null, 'This playlist is empty or contains media with an unknown duration, so it cannot be scheduled.'];
        }
    } else {
        return [null, 'Choose a media item or a playlist.'];
    }
    $secs = (int) ceil($d);
    if ($secs > RADIO_MAX_SLOT_SECONDS) {
        return [null, 'A slot can be at most 24 hours long.'];
    }
    return [$secs, null];
}

/** Bit for ISO weekday N (1 = Monday). */
function radio_day_bit(int $isoDay): int
{
    return 1 << ($isoDay - 1);
}

/** @param list<int> $days ISO weekdays */
function radio_days_mask(array $days): int
{
    $mask = 0;
    foreach ($days as $d) {
        if ($d >= 1 && $d <= 7) {
            $mask |= radio_day_bit($d);
        }
    }
    return $mask;
}

/** @return list<int> ISO weekdays in a mask */
function radio_mask_days(int $mask): array
{
    return array_values(array_filter(range(1, 7), static fn (int $d): bool => ($mask & radio_day_bit($d)) !== 0));
}

/**
 * Occurrences [start, end] (unix seconds) of a slot that overlap [$from, $to).
 * Repeats keep the local wall-clock time of start_at in $tz.
 * @return list<array{0:int,1:int}>
 */
function radio_slot_occurrences(array $slot, int $from, int $to, ?DateTimeZone $tz = null): array
{
    $tz ??= site_tz();
    $dur = (int) $slot['duration'];
    $first = new DateTimeImmutable((string) $slot['start_at'], utc());
    $firstTs = $first->getTimestamp();
    $rule = (string) $slot['repeat_rule'];
    if ($rule === 'none') {
        return $firstTs < $to && $firstTs + $dur > $from ? [[$firstTs, $firstTs + $dur]] : [];
    }
    $localFirst = $first->setTimezone($tz);
    $time = $localFirst->format('H:i:s');
    $until = !empty($slot['repeat_until']) ? (string) $slot['repeat_until'] : null;
    $mask = (int) $slot['days_mask'];
    $startDay = (new DateTimeImmutable('@' . max($firstTs, $from - $dur - 86400)))->setTimezone($tz)->setTime(0, 0);
    if ($startDay < $localFirst->setTime(0, 0)) {
        $startDay = $localFirst->setTime(0, 0);
    }
    $out = [];
    for ($day = $startDay, $guard = 0; $guard < 800; $day = $day->modify('+1 day'), $guard++) {
        $ymd = $day->format('Y-m-d');
        if ($until !== null && $ymd > $until) {
            break;
        }
        if ($rule === 'weekly' && ($mask & radio_day_bit((int) $day->format('N'))) === 0) {
            continue;
        }
        $occ = new DateTimeImmutable($ymd . ' ' . $time, $tz);
        $s = $occ->getTimestamp();
        if ($s >= $to) {
            break;
        }
        if ($s >= $firstTs && $s + $dur > $from) {
            $out[] = [$s, $s + $dur];
        }
    }
    return $out;
}

/**
 * Validate slot input.
 * @return array{0:array,1:array<string,string>} [row data, errors]
 */
function radio_slot_validate(array $in, ?int $ignoreId = null, ?DateTimeZone $tz = null): array
{
    $tz ??= site_tz();
    $e = [];
    $source = (string) ($in['source'] ?? '');
    $mediaId = null;
    $playlistId = null;
    if (preg_match('/^media:(\d+)$/', $source, $m)) {
        $mediaId = (int) $m[1];
    } elseif (preg_match('/^playlist:(\d+)$/', $source, $m)) {
        $playlistId = (int) $m[1];
    }
    [$duration, $err] = radio_source_duration($mediaId, $playlistId);
    if ($err !== null) {
        $e['source'] = $err;
    }
    $start = local_to_utc((string) ($in['start'] ?? ''), $tz);
    if ($start === null) {
        $e['start'] = trim((string) ($in['start'] ?? '')) !== '' ? (string) local_time_problem((string) $in['start'], $tz) : 'Enter the start date and time.';
    }
    $rule = in_array($in['repeat_rule'] ?? 'none', ['none', 'daily', 'weekly'], true) ? (string) ($in['repeat_rule'] ?? 'none') : 'none';
    $days = array_map('intval', is_array($in['days'] ?? null) ? $in['days'] : []);
    $mask = $rule === 'weekly' ? radio_days_mask($days) : 0;
    if ($rule === 'weekly' && $mask === 0) {
        $e['days'] = 'Choose at least one day of the week.';
    }
    $until = trim((string) ($in['repeat_until'] ?? ''));
    if ($until !== '') {
        $u = DateTimeImmutable::createFromFormat('!Y-m-d', $until, $tz);
        if ($u === false || $u->format('Y-m-d') !== $until) {
            $e['repeat_until'] = 'Enter a valid date.';
        } elseif ($start !== null && $until < utc_to_local($start, $tz)->format('Y-m-d')) {
            $e['repeat_until'] = 'The end date is before the first broadcast.';
        }
    }
    $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 160);
    $row = [
        'title' => $title, 'start_at' => $start, 'duration' => $duration, 'repeat_rule' => $rule, 'days_mask' => $mask,
        'repeat_until' => $rule !== 'none' && $until !== '' && !isset($e['repeat_until']) ? $until : null,
        'media_id' => $mediaId, 'playlist_id' => $playlistId,
    ];
    if ($e === []) {
        $conflicts = radio_slot_conflicts($row, $ignoreId, $tz);
        if ($conflicts !== []) {
            $e['start'] = 'This overlaps ' . implode('; ', array_slice($conflicts, 0, 3)) . '. Slots must not overlap.';
        }
    }
    return [$row, $e];
}

/** Create the schedule lock row (outside any transaction). */
function radio_schedule_lock_row(): void
{
    q("INSERT IGNORE INTO settings (`key`, `value`) VALUES ('radio.schedule_lock', '0')");
}

/**
 * Inside a transaction: serialise schedule changes, so two admins adding
 * slots at the same moment cannot both pass the overlap check.
 */
function radio_schedule_lock(): void
{
    q("SELECT `value` FROM settings WHERE `key` = 'radio.schedule_lock' FOR UPDATE");
}

/**
 * Validate and save a slot under the schedule lock (overlap check and write
 * in one transaction). $id null creates a slot.
 * @return array{0:?int,1:array,2:array<string,string>} [slot id or null, row, errors]
 */
function radio_slot_save(array $in, ?int $id = null, ?int $userId = null, ?DateTimeZone $tz = null): array
{
    radio_schedule_lock_row();
    return db_tx(static function () use ($in, $id, $userId, $tz): array {
        radio_schedule_lock();
        [$row, $errors] = radio_slot_validate($in, $id, $tz);
        if ($errors) {
            return [null, $row, $errors];
        }
        if ($id === null) {
            $id = db_insert('radio_slots', $row + ['created_by' => $userId]);
        } else {
            db_update('radio_slots', $id, $row);
        }
        return [$id, $row, []];
    });
}

/**
 * Slots that would overlap if the schedule were read in $tz (repeats keep
 * their local wall-clock time, so changing the site time zone can create
 * clashes). Returns human descriptions.
 * @return list<string>
 */
function radio_schedule_conflicts(DateTimeZone $tz): array
{
    $out = [];
    foreach (q_all('SELECT * FROM radio_slots ORDER BY start_at') as $slot) {
        $c = radio_slot_conflicts($slot, (int) $slot['id'], $tz);
        if ($c !== []) {
            $out[] = '“' . radio_slot_label($slot) . '” overlaps ' . $c[0];
        }
    }
    return $out;
}

/** Human labels of slots that overlap the candidate (and self-overlap of its repeats). */
function radio_slot_conflicts(array $cand, ?int $ignoreId = null, ?DateTimeZone $tz = null): array
{
    $tz ??= site_tz();
    $candStart = (new DateTimeImmutable((string) $cand['start_at'], utc()))->getTimestamp();
    $out = [];
    // A repeating slot must not overlap its own next occurrence.
    if ($cand['repeat_rule'] !== 'none') {
        $occ = radio_slot_occurrences($cand, $candStart, $candStart + 15 * 86400, $tz);
        for ($i = 1, $n = count($occ); $i < $n; $i++) {
            if ($occ[$i][0] < $occ[$i - 1][1]) {
                $out[] = 'its own next repeat (the programme is longer than the gap between repeats)';
                break;
            }
        }
    }
    $sql = 'SELECT * FROM radio_slots' . ($ignoreId !== null ? ' WHERE id <> ?' : '');
    foreach (q_all($sql, $ignoreId !== null ? [$ignoreId] : []) as $other) {
        $otherStart = (new DateTimeImmutable((string) $other['start_at'], utc()))->getTimestamp();
        $from = max($candStart, $otherStart) - 2 * 86400;
        $to = $from + RADIO_CHECK_WINDOW_DAYS * 86400;
        $a = radio_slot_occurrences($cand, $from, $to, $tz);
        $b = radio_slot_occurrences($other, $from, $to, $tz);
        foreach ($a as [$as, $ae]) {
            foreach ($b as [$bs, $be]) {
                if ($as < $be && $bs < $ae) {
                    $out[] = '“' . radio_slot_label($other) . '” on ' . (new DateTimeImmutable('@' . $bs))->setTimezone($tz)->format('D j M, H:i');
                    continue 3;
                }
            }
        }
    }
    return $out;
}

function radio_slot_label(array $slot): string
{
    if (($slot['title'] ?? '') !== '') {
        return (string) $slot['title'];
    }
    if (!empty($slot['media_id'])) {
        return (string) (q_val('SELECT title FROM radio_media WHERE id = ?', [$slot['media_id']]) ?? 'Programme');
    }
    return (string) (q_val('SELECT name FROM radio_playlists WHERE id = ?', [$slot['playlist_id']]) ?? 'Playlist');
}

/** Human repeat rule. */
function radio_repeat_label(array $slot): string
{
    return match ($slot['repeat_rule']) {
        'daily' => 'Every day',
        'weekly' => 'Weekly: ' . implode(', ', array_map(static fn (int $d): string => RADIO_DAY_NAMES[$d], radio_mask_days((int) $slot['days_mask']))),
        default => 'Once',
    } . (!empty($slot['repeat_until']) ? ' until ' . (new DateTimeImmutable((string) $slot['repeat_until']))->format('j M Y') : '');
}

/**
 * After a playlist changes, update the length of slots that play it.
 * Throws RuntimeException (caller rolls back) if a slot would now overlap
 * another or the playlist can no longer be scheduled.
 */
function radio_playlist_resync_slots(int $playlistId): void
{
    foreach (q_all('SELECT * FROM radio_slots WHERE playlist_id = ?', [$playlistId]) as $slot) {
        [$secs, $err] = radio_source_duration(null, $playlistId);
        if ($err !== null) {
            throw new RuntimeException('The playlist is scheduled, so it must keep known durations: ' . $err);
        }
        $slot['duration'] = $secs;
        $conflicts = radio_slot_conflicts($slot, (int) $slot['id']);
        if ($conflicts !== []) {
            throw new RuntimeException('With this change the scheduled slot “' . radio_slot_label($slot) . '” would overlap ' . $conflicts[0] . '.');
        }
        q('UPDATE radio_slots SET duration = ? WHERE id = ?', [$secs, $slot['id']]);
    }
    if (radio_loop_playlist_id() === $playlistId && radio_playlist_items($playlistId) !== [] && radio_playlist_duration($playlistId) === null) {
        throw new RuntimeException('This playlist is the fallback loop, so every item needs a known duration.');
    }
}

/** Public JSON for a radio item. */
function radio_media_json(array $r): array
{
    $cover = media_find($r['cover_media_id'] ? (int) $r['cover_media_id'] : null);
    return [
        'id' => (int) $r['id'],
        'title' => (string) $r['title'],
        'artist' => (string) $r['artist'],
        'description' => (string) ($r['description'] ?? ''),
        'url' => media_original_url(['id' => $r['media_id'], 'original_name' => $r['original_name'], 'path' => $r['path']]),
        'media_type' => $r['kind'] === 'video' ? 'video' : 'audio',
        'mime_type' => (string) $r['mime'],
        'duration' => $r['duration'] !== null ? round((float) $r['duration'], 3) : null,
        'cover_image' => $cover !== null ? media_url($cover, 800) : null,
    ];
}

/**
 * Locate the item playing at $pos seconds into a list of items.
 * @return array{0:?array,1:float,2:?array} [item, offset into it, next item]
 */
function radio_locate(array $items, float $pos): array
{
    $acc = 0.0;
    foreach (array_values($items) as $i => $item) {
        $d = (float) $item['duration'];
        if ($pos < $acc + $d) {
            return [$item, $pos - $acc, $items[$i + 1] ?? null];
        }
        $acc += $d;
    }
    return [null, 0.0, null];
}

/** Items of a slot (one media item or the playlist), known durations only. */
function radio_slot_items(array $slot): array
{
    if (!empty($slot['media_id'])) {
        $m = radio_media_find((int) $slot['media_id']);
        return $m !== null && $m['duration'] !== null ? [$m] : [];
    }
    return array_values(array_filter(radio_playlist_items((int) $slot['playlist_id']), static fn (array $i): bool => $i['duration'] !== null && (float) $i['duration'] > 0));
}

/** Loop playlist items with a positive total, or []. */
function radio_loop_items(): array
{
    $id = radio_loop_playlist_id();
    if ($id === null) {
        return [];
    }
    return array_values(array_filter(radio_playlist_items($id), static fn (array $i): bool => $i['duration'] !== null && (float) $i['duration'] > 0));
}

/** The next slot occurrence starting at or after $from (within the look-ahead window). */
function radio_next_occurrence(array $slots, int $from, ?DateTimeZone $tz = null): ?array
{
    $best = null;
    foreach ($slots as $slot) {
        foreach (radio_slot_occurrences($slot, $from, $from + RADIO_LOOKAHEAD_DAYS * 86400, $tz) as [$s, $e]) {
            if ($s >= $from && ($best === null || $s < $best['start'])) {
                $best = ['slot' => $slot, 'start' => $s, 'end' => $e];
            }
        }
    }
    return $best;
}

function radio_next_for_slot(array $occ, float $now): array
{
    $items = radio_slot_items($occ['slot']);
    $first = $items[0] ?? null;
    return [
        'type' => 'scheduled',
        'title' => radio_slot_label($occ['slot']),
        'artist' => (string) ($first['artist'] ?? ''),
        'starts_in' => round(max(0, $occ['start'] - $now), 3),
    ];
}

/**
 * The now-playing state (§6.2), computed from server time in UTC.
 * $now: unix time with fractions (injectable for tests).
 */
function radio_now_playing(?float $now = null, ?DateTimeZone $tz = null): array
{
    $now ??= microtime(true);
    $tz ??= site_tz();
    $sec = (int) floor($now);
    $ms = (int) floor(($now - $sec) * 1000);
    $out = [
        'status' => 'offline',
        'server_time' => gmdate('Y-m-d\TH:i:s', $sec) . sprintf('.%03dZ', $ms),
        'media' => null,
        'offset' => null,
        'remaining' => null,
        'next' => null,
        'next_check_in' => 60,
    ];
    if (!radio_enabled()) {
        return $out;
    }
    $slots = q_all('SELECT * FROM radio_slots');
    $loop = radio_loop_items();

    // 1. A scheduled slot that is on now.
    foreach ($slots as $slot) {
        foreach (radio_slot_occurrences($slot, $sec - 1, $sec + 2, $tz) as [$s, $e]) {
            if ($s > $now || $now >= $e) {
                continue;
            }
            [$item, $offset, $nextItem] = radio_locate(radio_slot_items($slot), $now - $s);
            if ($item === null) {
                continue;
            }
            $remaining = min((float) $item['duration'] - $offset, $e - $now);
            $out['status'] = 'scheduled';
            $out['media'] = radio_media_json($item);
            $out['offset'] = round($offset, 3);
            $out['remaining'] = round($remaining, 3);
            if ($nextItem !== null && $now + $remaining < $e) {
                $out['next'] = ['type' => 'scheduled', 'title' => (string) $nextItem['title'], 'artist' => (string) $nextItem['artist'], 'starts_in' => round($remaining, 3)];
            } else {
                $after = radio_next_occurrence($slots, $e, $tz);
                if ($after !== null && $after['start'] <= $e + 1) {
                    $out['next'] = radio_next_for_slot($after, $now);
                } elseif ($loop !== []) {
                    [$li] = radio_loop_at($loop, (float) $e);
                    $out['next'] = ['type' => 'loop', 'title' => (string) $li['title'], 'artist' => (string) $li['artist'], 'starts_in' => round($e - $now, 3)];
                } elseif ($after !== null) {
                    $out['next'] = radio_next_for_slot($after, $now);
                }
            }
            $out['next_check_in'] = round(max(0.5, min($remaining, 60)), 3);
            return $out;
        }
    }

    $nextOcc = radio_next_occurrence($slots, (int) ceil($now), $tz);

    // 2. The fallback loop.
    if ($loop !== []) {
        [$item, $offset, $nextItem] = radio_loop_at($loop, $now);
        $remaining = (float) $item['duration'] - $offset;
        $out['status'] = 'loop';
        $out['media'] = radio_media_json($item);
        $out['offset'] = round($offset, 3);
        if ($nextOcc !== null && $nextOcc['start'] - $now <= $remaining) {
            $remaining = $nextOcc['start'] - $now;
            $out['next'] = radio_next_for_slot($nextOcc, $now);
        } else {
            $out['next'] = ['type' => 'loop', 'title' => (string) $nextItem['title'], 'artist' => (string) $nextItem['artist'], 'starts_in' => round($remaining, 3)];
        }
        $out['remaining'] = round($remaining, 3);
        $out['next_check_in'] = round(max(0.5, min($remaining, 60)), 3);
        return $out;
    }

    // 3. Off air, with the next show if there is one.
    if ($nextOcc !== null) {
        $out['next'] = radio_next_for_slot($nextOcc, $now);
        $out['next_check_in'] = round(max(0.5, min($nextOcc['start'] - $now, 60)), 3);
    }
    return $out;
}

/**
 * Position in the loop playlist at $t, aligned to the Unix epoch.
 * @return array{0:array,1:float,2:array} [item, offset, next item (wraps)]
 */
function radio_loop_at(array $loop, float $t): array
{
    $total = array_sum(array_map(static fn (array $i): float => (float) $i['duration'], $loop));
    $pos = fmod($t, $total);
    [$item, $offset, $next] = radio_locate($loop, $pos);
    if ($item === null) {
        // Floating-point edge at the very end of the cycle.
        $item = $loop[0];
        $offset = 0.0;
        $next = $loop[1] ?? $loop[0];
    }
    return [$item, $offset, $next ?? $loop[0]];
}

/** Station status for the header button and sections: on air when enabled and something is playing. */
function radio_on_air(): bool
{
    static $cache = null;
    if ($cache === null) {
        try {
            $cache = radio_enabled() && radio_now_playing()['status'] !== 'offline';
        } catch (PDOException) {
            $cache = false;
        }
    }
    return $cache;
}

/**
 * On-demand library (§6.1): newest first, filter by category, search.
 * @return array{rows:list<array>, total:int}
 */
function radio_library(string $category, string $q, int $limit, int $offset): array
{
    $where = ["r.on_demand = 1", "m.visibility = 'public'", '(r.publish_at IS NULL OR r.publish_at <= UTC_TIMESTAMP())'];
    $params = [];
    if ($category !== '') {
        $where[] = 'c.slug = ?';
        $params[] = $category;
    }
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $where[] = '(r.title LIKE ? OR r.artist LIKE ? OR r.description LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    $w = implode(' AND ', $where);
    return [
        'rows' => q_all(radio_media_sql() . " WHERE $w ORDER BY COALESCE(r.publish_at, r.created_at) DESC, r.id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset, $params),
        'total' => (int) q_val("SELECT COUNT(*) FROM radio_media r JOIN media m ON m.id = r.media_id LEFT JOIN radio_categories c ON c.id = r.category_id WHERE $w", $params),
    ];
}
