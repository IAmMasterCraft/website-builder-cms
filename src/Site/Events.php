<?php
declare(strict_types=1);

namespace Site;

use DateTimeImmutable;
use DateTimeZone;

final class Events
{
    public const WHEN = ['upcoming' => 'Upcoming', 'today' => 'Today', 'past' => 'Past'];

    public static function index(array $p): void
    {
        $viewer = current_user();
        $when = query_param('when', 'upcoming');
        $when = array_key_exists($when, self::WHEN) ? $when : 'upcoming';
        [$cond, $params] = event_when_sql($when, new DateTimeImmutable('now', utc()), site_tz());
        $where = published_sql('e') . ' AND ' . visibility_sql('e', $viewer) . ' AND ' . $cond;
        $pg = paging(12);
        $total = (int) q_val("SELECT COUNT(*) FROM events e WHERE $where", $params);
        $rows = q_all(
            "SELECT e.* FROM events e WHERE $where ORDER BY e.start_at " . ($when === 'past' ? 'DESC' : 'ASC') . ' LIMIT ' . $pg['per'] . ' OFFSET ' . $pg['offset'],
            $params
        );
        render('site/events_index', [
            'title' => 'Events', 'rows' => $rows, 'total' => $total, 'page' => $pg['page'], 'per' => $pg['per'],
            'when' => $when, 'query' => $when !== 'upcoming' ? ['when' => $when] : [], 'canonical' => '/events',
        ]);
    }

    /**
     * Weeks (Mon–Sun) covering a month. Each day: date (Y-m-d), day number,
     * in_month, is_today, events (rows whose local date span includes the day).
     * @return list<list<array{date:string, day:int, in_month:bool, is_today:bool, events:list<array>}>>
     */
    public static function monthGrid(int $year, int $month, DateTimeZone $tz, array $events, ?DateTimeImmutable $now = null): array
    {
        $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $tz);
        $start = $first->modify('-' . ((int) $first->format('N') - 1) . ' days');
        $last = $first->modify('last day of this month');
        $end = $last->modify('+' . (7 - (int) $last->format('N')) . ' days');
        $today = ($now ?? new DateTimeImmutable('now', $tz))->setTimezone($tz)->format('Y-m-d');
        $byDay = self::bucketByDay($events, $tz);
        $days = [];
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            $days[] = [
                'date' => $key,
                'day' => (int) $d->format('j'),
                'in_month' => (int) $d->format('n') === $month,
                'is_today' => $key === $today,
                'events' => $byDay[$key] ?? [],
            ];
        }
        return array_chunk($days, 7);
    }

    /** @return array<string, list<array>> local Y-m-d => events touching that day */
    public static function bucketByDay(array $events, DateTimeZone $tz): array
    {
        $out = [];
        foreach ($events as $e) {
            $s = utc_to_local($e['start_at'], $tz);
            $f = utc_to_local($e['end_at'] ?: $e['start_at'], $tz);
            if ($s === null || $f === null) {
                continue;
            }
            $day = $s->setTime(0, 0);
            $guard = 0;
            while ($day <= $f && $guard++ < 62) {
                $out[$day->format('Y-m-d')][] = $e;
                $day = $day->modify('+1 day');
            }
        }
        return $out;
    }

    public static function calendar(array $p): void
    {
        $viewer = current_user();
        $tz = site_tz();
        $view = query_param('view', 'month');
        $view = in_array($view, ['month', 'week', 'list'], true) ? $view : 'month';
        $now = new DateTimeImmutable('now', $tz);

        $monthParam = query_param('month');
        $anchor = preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $monthParam, $m) && (int) $m[1] >= 1970 && (int) $m[1] <= 2200
            ? new DateTimeImmutable($m[1] . '-' . $m[2] . '-01', $tz) : $now->modify('first day of this month')->setTime(0, 0);
        $dateParam = query_param('date');
        $weekAnchor = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam) && ($d = DateTimeImmutable::createFromFormat('!Y-m-d', $dateParam, $tz)) !== false
            ? $d : $now->setTime(0, 0);
        $weekStart = $weekAnchor->modify('-' . ((int) $weekAnchor->format('N') - 1) . ' days');

        if ($view === 'week') {
            $from = $weekStart;
            $to = $weekStart->modify('+7 days');
        } else {
            $from = $anchor->modify('-7 days');
            $to = $anchor->modify('+1 month +7 days');
        }
        $events = q_all(
            'SELECT e.* FROM events e WHERE ' . published_sql('e') . ' AND ' . visibility_sql('e', $viewer)
            . ' AND e.start_at < ? AND COALESCE(e.end_at, e.start_at) >= ? ORDER BY e.start_at',
            [$to->setTimezone(utc())->format('Y-m-d H:i:s'), $from->setTimezone(utc())->format('Y-m-d H:i:s')]
        );
        $data = [
            'title' => 'Event calendar', 'view' => $view, 'events' => $events, 'tz' => $tz, 'canonical' => '/events/calendar', 'noindex' => $monthParam !== '' || $dateParam !== '',
        ];
        if ($view === 'month') {
            $data += [
                'weeks' => self::monthGrid((int) $anchor->format('Y'), (int) $anchor->format('n'), $tz, $events, $now),
                'label' => $anchor->format('F Y'),
                'prev' => '/events/calendar?month=' . $anchor->modify('-1 month')->format('Y-m'),
                'next' => '/events/calendar?month=' . $anchor->modify('+1 month')->format('Y-m'),
                'current' => '/events/calendar',
            ];
        } elseif ($view === 'week') {
            $days = [];
            $bucket = self::bucketByDay($events, $tz);
            for ($i = 0; $i < 7; $i++) {
                $d = $weekStart->modify("+$i days");
                $days[] = ['date' => $d, 'is_today' => $d->format('Y-m-d') === $now->format('Y-m-d'), 'events' => $bucket[$d->format('Y-m-d')] ?? []];
            }
            $data += [
                'days' => $days,
                'label' => 'Week of ' . $weekStart->format('j M Y'),
                'prev' => '/events/calendar?view=week&date=' . $weekStart->modify('-7 days')->format('Y-m-d'),
                'next' => '/events/calendar?view=week&date=' . $weekStart->modify('+7 days')->format('Y-m-d'),
                'current' => '/events/calendar?view=week',
            ];
        } else {
            [$cond, $params] = event_when_sql('upcoming', new DateTimeImmutable('now', utc()), $tz);
            $data['events'] = q_all('SELECT e.* FROM events e WHERE ' . published_sql('e') . ' AND ' . visibility_sql('e', $viewer) . " AND $cond ORDER BY e.start_at LIMIT 100", $params);
            $data += ['label' => 'Upcoming events', 'prev' => null, 'next' => null, 'current' => null];
        }
        render('site/events_calendar', $data);
    }

    /** Published event visible to the viewer, or 404 (members-only shows a teaser). */
    private static function find(string $slug): array
    {
        $row = q_one('SELECT * FROM events WHERE slug = ? AND ' . published_sql(), [$slug]);
        if ($row === null && is_staff(current_user())) {
            $row = q_one('SELECT * FROM events WHERE slug = ?', [$slug]);
        }
        if ($row === null) {
            abort(404);
        }
        return event_load_extra($row);
    }

    public static function show(array $p): void
    {
        $event = self::find($p['slug']);
        $viewer = current_user();
        $locked = $event['visibility'] === 'members' && !viewer_is_member($viewer);
        $old = take_old_input();
        $block = event_registration_block($event, $viewer);
        $cover = media_find($event['cover_media_id'] ? (int) $event['cover_media_id'] : null);
        render('site/event_show', [
            'title' => $event['seo_title'] ?: $event['title'],
            'meta_description' => $event['seo_description'] ?: excerpt($event['summary'] ?: $event['body'], 300),
            'event' => $event,
            'locked' => $locked,
            'cover' => $cover,
            'block' => $block,
            'viewer' => $viewer,
            'old' => $old['values'],
            'errors' => $old['errors'],
            'is_past' => event_end_at($event) < utc_now(),
            'share_url' => base_url(event_url($event)),
            'canonical' => event_url($event),
            'og_image' => $cover ? media_url($cover, 1600) : null,
        ]);
    }

    public static function register(array $p): void
    {
        csrf_check();
        $event = self::find($p['slug']);
        $viewer = current_user();
        if ($event['visibility'] === 'members' && !viewer_is_member($viewer)) {
            abort(403);
        }
        $back = event_url($event) . '#register';
        // Honeypot and rate limit (per IP) against automated sign-ups.
        if (input('website') !== '') {
            flash(t('event.registered'));
            redirect($back);
        }
        $bucket = 'event-register|' . client_ip();
        if (rate_limited($bucket, 10, 3600)) {
            abort(429);
        }
        rate_hit($bucket);
        $input = [
            'name' => $viewer ? ($viewer['display_name'] ?: input('name')) : input('name'),
            'email' => $viewer ? $viewer['email'] : input('email'),
            'phone' => input('phone'),
            'quantity' => input('quantity', '1'),
            'ticket_id' => input('ticket_id'),
        ];
        [$id, $errors] = event_register($event, $input, $viewer);
        if ($id === null) {
            remember_input($input, $errors);
            flash($errors['form'] ?? 'Please check the registration form.', 'error');
            redirect($back);
        }
        $ticket = $input['ticket_id'] !== '' ? q_one('SELECT name, price_label FROM event_tickets WHERE id = ?', [(int) $input['ticket_id']]) : null;
        send_template_mail($input['email'], $input['name'], 'event_registration', [
            'name' => $input['name'],
            'event' => $event,
            'quantity' => (int) $input['quantity'],
            'ticket' => $ticket,
            'event_url' => base_url(event_url($event)),
            'ics_url' => base_url('/events/' . $event['slug'] . '.ics'),
        ]);
        flash(t('event.registered'));
        redirect(event_url($event) . '#register');
    }

    public static function ics(array $p): void
    {
        $event = self::find($p['slug']);
        if ($event['visibility'] === 'members' && !viewer_is_member(current_user())) {
            abort(403);
        }
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $event['slug'] . '.ics"');
        echo ics_calendar([$event], (string) $event['title']);
    }
}
