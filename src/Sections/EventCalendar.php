<?php
declare(strict_types=1);

namespace Sections;

final class EventCalendar extends Section
{
    public const KEY = 'event_calendar';
    public const LABEL = 'Event calendar';
    public const DESCRIPTION = 'This month’s events as a calendar grid, linking to the full calendar.';
    public const GROUP = 'Content';

    public static function schema(): array
    {
        return array_merge(self::headerFields('Calendar'), self::linkFields('Full calendar', '/events/calendar'));
    }

    public static function data(array $settings, array $ctx): array
    {
        $viewer = $ctx['viewer'] ?? null;
        $tz = site_tz();
        $now = new \DateTimeImmutable('now', $tz);
        $anchor = $now->modify('first day of this month')->setTime(0, 0);
        $events = q_all(
            'SELECT e.* FROM events e WHERE ' . published_sql('e') . ' AND ' . visibility_sql('e', $viewer)
            . ' AND e.start_at < ? AND COALESCE(e.end_at, e.start_at) >= ? ORDER BY e.start_at',
            [$anchor->modify('+1 month +7 days')->setTimezone(utc())->format('Y-m-d H:i:s'), $anchor->modify('-7 days')->setTimezone(utc())->format('Y-m-d H:i:s')]
        );
        return [
            'weeks' => \Site\Events::monthGrid((int) $anchor->format('Y'), (int) $anchor->format('n'), $tz, $events, $now),
            'label' => $anchor->format('F Y'),
            'next' => '/events/calendar?month=' . $anchor->modify('+1 month')->format('Y-m'),
        ];
    }
}
