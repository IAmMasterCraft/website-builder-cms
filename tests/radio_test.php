<?php
declare(strict_types=1);

// Phase 6: radio schedule validation, repeat expansion and the
// now-playing computation at boundaries (injected clock, UTC).

function reset_radio(): void
{
    q('DELETE FROM radio_slots');
    q('DELETE FROM radio_playlist_items');
    q('DELETE FROM radio_playlists');
    q('DELETE FROM radio_media');
    setting_set('radio.enabled', true);
    setting_set('radio.loop_playlist_id', null);
    settings_all([]);
}

/** A radio item backed by a small real MP3-typed media row (no file needed for scheduling maths). */
function fixture_radio(string $title, ?float $duration, string $kind = 'audio', string $artist = 'Host'): int
{
    static $n = 0;
    $n++;
    $media = db_insert('media', ['kind' => $kind, 'path' => "radio/test-$n." . ($kind === 'video' ? 'mp4' : 'mp3'), 'original_name' => "test-$n." . ($kind === 'video' ? 'mp4' : 'mp3'),
        'mime' => $kind === 'video' ? 'video/mp4' : 'audio/mpeg', 'size' => 1000, 'visibility' => 'public', 'duration' => $duration]);
    return db_insert('radio_media', ['media_id' => $media, 'title' => $title, 'artist' => $artist, 'duration' => $duration]);
}

function fixture_playlist(string $name, array $mediaIds): int
{
    $id = db_insert('radio_playlists', ['name' => $name]);
    foreach ($mediaIds as $i => $m) {
        q('INSERT INTO radio_playlist_items (playlist_id, radio_media_id, sort) VALUES (?, ?, ?)', [$id, $m, $i]);
    }
    return $id;
}

/** Insert a slot directly (start in UTC). */
function fixture_slot(string $startUtc, int $duration, array $over = []): int
{
    return db_insert('radio_slots', $over + ['start_at' => $startUtc, 'duration' => $duration, 'repeat_rule' => 'none', 'days_mask' => 0]);
}

function ts(string $utc): float
{
    return (float) (new DateTimeImmutable($utc, utc()))->getTimestamp();
}

test('repeat rules expand in local time: none, daily, weekly days, until; DST keeps wall-clock time', function (): void {
    $tz = new DateTimeZone('Europe/London');
    // 19:00 London on Mon 23 Mar 2026 (GMT) = 19:00 UTC.
    $slot = ['start_at' => '2026-03-23 19:00:00', 'duration' => 3600, 'repeat_rule' => 'none', 'days_mask' => 0, 'repeat_until' => null];
    $from = (int) ts('2026-03-20 00:00:00');
    $to = (int) ts('2026-04-06 00:00:00');
    same([[(int) ts('2026-03-23 19:00:00'), (int) ts('2026-03-23 20:00:00')]], radio_slot_occurrences($slot, $from, $to, $tz));
    $daily = radio_slot_occurrences(['repeat_rule' => 'daily'] + $slot, $from, $to, $tz);
    same(14, count($daily), '23 Mar to 5 Apr inclusive');
    same((int) ts('2026-03-28 19:00:00'), $daily[5][0], 'Sat 28 Mar still GMT');
    same((int) ts('2026-03-29 18:00:00'), $daily[6][0], 'after the clocks go forward: 19:00 BST = 18:00 UTC');
    $weekly = radio_slot_occurrences(['repeat_rule' => 'weekly', 'days_mask' => radio_days_mask([1, 3])] + $slot, $from, $to, $tz);
    $dows = array_map(static fn (array $o): string => (new DateTimeImmutable('@' . $o[0]))->setTimezone($tz)->format('D'), $weekly);
    same(['Mon', 'Wed', 'Mon', 'Wed'], $dows, 'Mon 23, Wed 25, Mon 30 Mar, Wed 1 Apr');
    $until = radio_slot_occurrences(['repeat_rule' => 'daily', 'repeat_until' => '2026-03-25'] + $slot, $from, $to, $tz);
    same(3, count($until));
    same([], radio_slot_occurrences(['repeat_rule' => 'daily'] + $slot, (int) ts('2026-03-01 00:00:00'), (int) ts('2026-03-23 18:00:00'), $tz), 'nothing before the first broadcast');
    same([1, 3, 7], radio_mask_days(radio_days_mask([7, 1, 3, 9])));
});

test('slot validation: unknown durations, overlaps (incl. repeats), self-overlap, back-to-back allowed', function (): void {
    reset_radio();
    $tz = site_tz();
    $hour = fixture_radio('Morning show', 3600.0);
    $unknown = fixture_radio('Mystery tape', null);
    $local = static fn (string $d): string => $d; // datetime-local in the site time zone
    [, $e] = radio_slot_validate(['source' => 'media:' . $unknown, 'start' => '2026-05-04T09:00', 'repeat_rule' => 'none'], null, $tz);
    contains('unknown duration', $e['source'] ?? '');
    $empty = fixture_playlist('Empty', []);
    [, $e] = radio_slot_validate(['source' => 'playlist:' . $empty, 'start' => '2026-05-04T09:00'], null, $tz);
    ok(isset($e['source']), 'empty playlists cannot be scheduled');
    $withUnknown = fixture_playlist('Mixed', [$hour, $unknown]);
    [, $e] = radio_slot_validate(['source' => 'playlist:' . $withUnknown, 'start' => '2026-05-04T09:00'], null, $tz);
    ok(isset($e['source']), 'playlists with unknown durations cannot be scheduled');

    [$row, $e] = radio_slot_validate(['source' => 'media:' . $hour, 'start' => $local('2026-05-04T09:00'), 'repeat_rule' => 'weekly', 'days' => ['1', '5']], null, $tz);
    same([], $e);
    same(3600, $row['duration']);
    $weeklyId = db_insert('radio_slots', $row);

    [, $e] = radio_slot_validate(['source' => 'media:' . $hour, 'start' => '2026-05-15T09:30', 'repeat_rule' => 'none'], null, $tz);
    contains('overlaps', $e['start'] ?? '', 'a one-off on a later Friday clashes with the weekly repeat');
    [, $e] = radio_slot_validate(['source' => 'media:' . $hour, 'start' => '2026-05-14T09:30', 'repeat_rule' => 'none'], null, $tz);
    same([], $e, 'Thursday is free');
    [, $e] = radio_slot_validate(['source' => 'media:' . $hour, 'start' => '2026-05-04T10:00', 'repeat_rule' => 'daily'], null, $tz);
    same([], $e, 'back-to-back (end == start) is not an overlap');
    [, $e] = radio_slot_validate(['source' => 'media:' . $hour, 'start' => '2026-05-03T09:30', 'repeat_rule' => 'daily'], null, $tz);
    contains('overlaps', $e['start'] ?? '', 'a daily repeat starting earlier hits the Monday show');
    [, $e] = radio_slot_validate(['source' => 'media:' . $hour, 'start' => '2026-05-04T09:00', 'repeat_rule' => 'weekly', 'days' => ['1', '5']], $weeklyId, $tz);
    same([], $e, 'editing a slot ignores itself');
    [, $e] = radio_slot_validate(['source' => 'media:' . $hour, 'start' => '2026-06-01T09:00', 'repeat_rule' => 'weekly', 'days' => []], null, $tz);
    ok(isset($e['days']));

    $long = fixture_radio('Marathon', 90000.0);
    [, $e] = radio_slot_validate(['source' => 'media:' . $long, 'start' => '2027-01-01T00:00'], null, $tz);
    ok(isset($e['source']), 'longer than 24 h');
    $twenty = fixture_radio('All day', 86000.0);
    q('DELETE FROM radio_slots');
    [, $e] = radio_slot_validate(['source' => 'media:' . $twenty, 'start' => '2027-01-04T00:00', 'repeat_rule' => 'weekly', 'days' => ['1', '2']], null, $tz);
    same([], $e, 'Mon+Tue with a 23.9 h programme fits');
    $thirty = fixture_playlist('Two days', [$twenty, $twenty]);
    [, $e] = radio_slot_validate(['source' => 'playlist:' . $thirty, 'start' => '2027-01-04T00:00', 'repeat_rule' => 'daily'], null, $tz);
    ok(isset($e['source']) || isset($e['start']), 'a programme longer than the gap between its repeats is refused');
});

test('playlist changes that would make a scheduled slot overlap are refused', function (): void {
    reset_radio();
    $a = fixture_radio('A', 1800.0);
    $b = fixture_radio('B', 1800.0);
    $pl = fixture_playlist('Evening', [$a]);
    fixture_slot('2026-07-01 18:00:00', 1800, ['playlist_id' => $pl]);
    fixture_slot('2026-07-01 18:30:00', 1800, ['media_id' => $b]);
    q('INSERT INTO radio_playlist_items (playlist_id, radio_media_id, sort) VALUES (?, ?, 1)', [$pl, $b]);
    throws(RuntimeException::class, fn () => radio_playlist_resync_slots($pl));
    q('DELETE FROM radio_slots WHERE media_id = ?', [$b]);
    radio_playlist_resync_slots($pl);
    same(3600, (int) q_val('SELECT duration FROM radio_slots WHERE playlist_id = ?', [$pl]), 'slot length follows the playlist');
    same(['Playlist “Evening”'], radio_media_usage($a), 'used media cannot be deleted');
});

test('now-playing: scheduled offset, next item, boundaries, loop, offline, JSON shape', function (): void {
    reset_radio();
    $tz = new DateTimeZone('UTC');
    $news = fixture_radio('News hour', 600.0, 'audio', 'Desk');
    $talk1 = fixture_radio('Talk part 1', 300.0, 'audio', 'Ama');
    $talk2 = fixture_radio('Talk part 2', 400.0, 'video', 'Ama');
    $j1 = fixture_radio('Jingle loop 1', 100.0, 'audio', 'Loop');
    $j2 = fixture_radio('Jingle loop 2', 50.0, 'audio', 'Loop');
    $talks = fixture_playlist('Talks', [$talk1, $talk2]);

    // Off air: nothing scheduled, no loop.
    $np = radio_now_playing(ts('2026-08-01 11:00:00'), $tz);
    same(['status', 'server_time', 'media', 'offset', 'remaining', 'next', 'next_check_in'], array_keys($np), 'exact top-level shape (§6.2)');
    same('offline', $np['status']);
    same(null, $np['media']);
    same(null, $np['next']);
    same('2026-08-01T11:00:00.000Z', $np['server_time']);

    fixture_slot('2026-08-01 12:00:00', 600, ['media_id' => $news, 'title' => 'Midday news']);
    fixture_slot('2026-08-01 12:10:00', 700, ['playlist_id' => $talks]);
    $np = radio_now_playing(ts('2026-08-01 11:59:00'), $tz);
    same('offline', $np['status']);
    same(['type' => 'scheduled', 'title' => 'Midday news', 'artist' => 'Desk', 'starts_in' => 60.0], $np['next'], 'off air shows the next show');

    // Exactly at the slot start: offset 0.
    $np = radio_now_playing(ts('2026-08-01 12:00:00'), $tz);
    same('scheduled', $np['status']);
    same(0.0, $np['offset']);
    same(600.0, $np['remaining']);
    same(['id', 'title', 'artist', 'description', 'url', 'media_type', 'mime_type', 'duration', 'cover_image'], array_keys($np['media']), 'exact media shape');
    same('audio', $np['media']['media_type']);
    same('audio/mpeg', $np['media']['mime_type']);
    ok(str_starts_with($np['media']['url'], '/media/'));
    same('Talks', $np['next']['title'], 'back-to-back slot is next');

    $np = radio_now_playing(ts('2026-08-01 12:04:10') + 0.25, $tz);
    same(250.25, $np['offset'], 'offset from server time, with fractions');
    same(349.75, $np['remaining']);
    same(60.0, $np['next_check_in'], 'polls at most every 60 s');

    // Last instant of the slot and the boundary.
    $np = radio_now_playing(ts('2026-08-01 12:09:59') + 0.999, $tz);
    same('News hour', $np['media']['title']);
    $np = radio_now_playing(ts('2026-08-01 12:10:00'), $tz);
    same('Talk part 1', $np['media']['title'], 'at the boundary the next slot starts');
    same(0.0, $np['offset']);
    same(['type' => 'scheduled', 'title' => 'Talk part 2', 'artist' => 'Ama', 'starts_in' => 300.0], $np['next'], 'next item in the playlist');

    // Inside the playlist, second item (video).
    $np = radio_now_playing(ts('2026-08-01 12:15:30'), $tz);
    same('Talk part 2', $np['media']['title']);
    same('video', $np['media']['media_type']);
    same(30.0, $np['offset']);
    same(370.0, $np['remaining']);
    same(null, $np['next'], 'nothing after the last slot and no loop');

    // After the schedule: offline again.
    same('offline', radio_now_playing(ts('2026-08-01 12:21:40'), $tz)['status']);

    // Fallback loop: aligned to the Unix epoch, deterministic for everyone.
    $loop = fixture_playlist('Loop', [$j1, $j2]);
    setting_set('radio.loop_playlist_id', $loop);
    $t = 1_800_000_000.0; // divisible by 150
    $np = radio_now_playing($t + 120.5, $tz);
    same('loop', $np['status']);
    same('Jingle loop 2', $np['media']['title']);
    same(20.5, $np['offset']);
    same(29.5, $np['remaining']);
    same(['type' => 'loop', 'title' => 'Jingle loop 1', 'artist' => 'Loop', 'starts_in' => 29.5], $np['next'], 'loop wraps around');
    same(radio_now_playing($t + 120.5, $tz), radio_now_playing($t + 120.5, $tz), 'same answer for every listener');
    same(0.0, radio_now_playing($t + 150, $tz)['offset'], 'cycle boundary');

    // The loop yields to the next scheduled show.
    $np = radio_now_playing(ts('2026-08-01 11:59:30'), $tz);
    same('loop', $np['status']);
    same(30.0, $np['remaining'], 'cut short at the next slot');
    same('scheduled', $np['next']['type']);
    same('Midday news', $np['next']['title']);
    // At the end of the schedule the loop takes over.
    $np = radio_now_playing(ts('2026-08-01 12:21:00'), $tz);
    same('Talk part 2', $np['media']['title']);
    same('loop', $np['next']['type']);

    // Media with unknown duration never plays; station off = offline.
    setting_set('radio.enabled', false);
    same('offline', radio_now_playing(ts('2026-08-01 12:05:00'), $tz)['status']);
    setting_set('radio.enabled', true);
});

test('now-playing honours repeat rules in the site time zone', function (): void {
    reset_radio();
    $tz = new DateTimeZone('Europe/London');
    $show = fixture_radio('Drive time', 1800.0);
    // 17:00 London daily from 1 Mar 2026.
    fixture_slot('2026-03-01 17:00:00', 1800, ['media_id' => $show, 'repeat_rule' => 'daily']);
    same('scheduled', radio_now_playing(ts('2026-03-10 17:10:00'), $tz)['status'], 'GMT: 17:10 UTC');
    same('offline', radio_now_playing(ts('2026-04-10 17:10:00'), $tz)['status'], 'BST: 17:10 UTC is 18:10 local');
    $np = radio_now_playing(ts('2026-04-10 16:10:00'), $tz);
    same('scheduled', $np['status']);
    same(600.0, $np['offset']);
});
