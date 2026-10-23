<?php
declare(strict_types=1);

function make_event(array $over = []): array
{
    $input = $over + ['title' => 'Event ' . bin2hex(random_bytes(3)), 'start_at' => local_in('+7 days'), 'end_at' => local_in('+7 days', '12:00'),
        'timezone' => 'Europe/London', 'status' => 'published', 'registration_open' => '1', 'guest_registration' => '1'];
    $id = fixture_content('events', $input);
    return content_find(content_type('events'), $id);
}

test('event filters: today, upcoming and past (F6)', function (): void {
    $tz = new DateTimeZone('Europe/London');
    $now = new DateTimeImmutable('2026-03-10 12:00:00', new DateTimeZone('UTC'));
    q('DELETE FROM events');
    foreach ([['Past', '2026-03-01 10:00:00', '2026-03-01 12:00:00'], ['Earlier today', '2026-03-10 08:00:00', '2026-03-10 09:00:00'],
              ['Later today', '2026-03-10 18:00:00', null], ['Next week', '2026-03-17 10:00:00', null], ['Multi-day ongoing', '2026-03-09 10:00:00', '2026-03-11 10:00:00']] as [$t, $s, $e]) {
        db_insert('events', ['title' => $t, 'slug' => slugify($t), 'start_at' => $s, 'end_at' => $e, 'status' => 'published']);
    }
    $titles = function (string $when) use ($now, $tz): array {
        [$cond, $params] = event_when_sql($when, $now, $tz);
        return array_column(q_all("SELECT e.title FROM events e WHERE $cond ORDER BY e.start_at", $params), 'title');
    };
    same(['Multi-day ongoing', 'Earlier today', 'Later today'], $titles('today'));
    same(['Multi-day ongoing', 'Later today', 'Next week'], $titles('upcoming'));
    same(['Past', 'Earlier today'], $titles('past'));
});

test('registration is blocked when closed, past, after the deadline or for guests when members-only', function (): void {
    $member = ['id' => 1, 'role' => 'member', 'status' => 'active'];
    same(null, event_registration_block(make_event(), null));
    same('Registration is not open for this event.', event_registration_block(make_event(['registration_open' => '']), null));
    $past = make_event();
    $past['start_at'] = '2020-01-01 10:00:00';
    $past['end_at'] = '2020-01-01 12:00:00';
    same('This event has already taken place.', event_registration_block($past, null));
    $late = make_event(['registration_deadline' => local_in('+1 day')]);
    $late['registration_deadline'] = '2020-01-01 00:00:00';
    same('Registration has closed.', event_registration_block($late, null));
    $membersOnly = make_event(['guest_registration' => '']);
    same('Please log in as a member to register for this event.', event_registration_block($membersOnly, null));
    same(null, event_registration_block($membersOnly, $member));
});

test('guest and member registration; capacity reached shows full (acceptance 5)', function (): void {
    $event = make_event(['capacity' => '3', 'ticket_name' => ['Standard'], 'ticket_price' => ['Free']]);
    $ticket = (string) $event['ticket_rows'][0]['id'];
    [$id, $errors] = event_register($event, ['name' => 'Guest One', 'email' => 'g1@example.test', 'quantity' => '2', 'ticket_id' => $ticket], null);
    same([], $errors);
    ok($id > 0);
    $memberId = fixture_member();
    $member = q_one('SELECT * FROM users WHERE id = ?', [$memberId]);
    [$id2, $errors2] = event_register($event, ['name' => 'Member', 'email' => $member['email'], 'quantity' => '1', 'ticket_id' => $ticket], $member);
    same([], $errors2);
    same($memberId, (int) q_val('SELECT user_id FROM event_registrations WHERE id = ?', [$id2]));
    same(3, event_registered_count((int) $event['id']));
    [$id3, $errors3] = event_register($event, ['name' => 'Late', 'email' => 'late@example.test', 'quantity' => '1', 'ticket_id' => $ticket], null);
    same(null, $id3);
    same('This event is full.', $errors3['form']);
    q("UPDATE event_registrations SET status = 'cancelled' WHERE id = ?", [$id]);
    same(1, event_registered_count((int) $event['id']), 'cancelled places are released');
    [, $e4] = event_register($event, ['name' => 'Many', 'email' => 'm@example.test', 'quantity' => '3', 'ticket_id' => $ticket], null);
    same('Only 2 places left.', $e4['form']);
});

test('registration validates name, email, quantity and ticket', function (): void {
    $event = make_event(['ticket_name' => ['A'], 'ticket_price' => ['']]);
    [$id, $errors] = event_register($event, ['name' => '', 'email' => 'bad', 'quantity' => '11', 'ticket_id' => '999999'], null);
    same(null, $id);
    same(['name', 'email', 'quantity', 'ticket_id'], array_keys($errors));
});

test('month grid starts on Monday and buckets multi-day events', function (): void {
    $tz = new DateTimeZone('Europe/London');
    $events = [['start_at' => '2026-10-30 10:00:00', 'end_at' => '2026-11-01 10:00:00', 'title' => 'Weekend camp']];
    $weeks = Site\Events::monthGrid(2026, 10, $tz, $events, new DateTimeImmutable('2026-10-23 12:00:00', $tz));
    same('2026-09-28', $weeks[0][0]['date'], 'grid starts on the Monday before the 1st');
    same(5, count($weeks));
    $flat = array_merge(...$weeks);
    $byDate = array_column($flat, null, 'date');
    same(1, count($byDate['2026-10-30']['events']));
    same(1, count($byDate['2026-10-31']['events']));
    same(1, count($byDate['2026-11-01']['events']));
    ok($byDate['2026-10-23']['is_today']);
    ok(!$byDate['2026-11-01']['in_month']);
});

test('ics output: CRLF lines, escaping, folding and UTC times', function (): void {
    $event = ['id' => 7, 'slug' => 'summer-fair', 'title' => 'Summer fair; food, music & more', 'summary' => "Line one\nLine two", 'body' => '',
        'start_at' => '2026-07-01 09:00:00', 'end_at' => null, 'venue_name' => 'The Hall', 'venue_address' => '1 High St, Town',
        'online_url' => null, 'updated_at' => '2026-06-01 08:00:00'];
    $ics = ics_calendar([$event], 'Test calendar');
    ok(str_starts_with($ics, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"));
    ok(str_ends_with($ics, "END:VCALENDAR\r\n"));
    contains("SUMMARY:Summer fair\; food\\, music & more\r\n", $ics);
    contains("DTSTART:20260701T090000Z\r\n", $ics);
    contains("DTEND:20260701T100000Z\r\n", $ics, 'defaults to one hour');
    contains("DESCRIPTION:Line one Line two", $ics);
    contains("LOCATION:The Hall\\, 1 High St\\, Town", $ics);
    contains('UID:event-7@127.0.0.1', $ics);
    foreach (explode("\r\n", $ics) as $line) {
        ok(strlen($line) <= 75, 'line folded: ' . $line);
    }
    $folded = ics_fold('DESCRIPTION:' . str_repeat('é', 60));
    contains("\r\n ", $folded);
    same('DESCRIPTION:' . str_repeat('é', 60), str_replace("\r\n ", '', $folded), 'unfolds to the original');
});

test('ics escaping covers backslashes and newlines', function (): void {
    same('a\\\\b\\nc\\,d\;e', ics_escape("a\\b\nc,d;e"));
});
