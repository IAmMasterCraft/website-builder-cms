<?php
declare(strict_types=1);

namespace Sections;

final class UpcomingEvents extends Section
{
    public const KEY = 'upcoming_events';
    public const LABEL = 'Upcoming events';
    public const DESCRIPTION = 'The next events on the calendar, with date tiles.';
    public const GROUP = 'Content';

    public static function schema(): array
    {
        return array_merge(self::headerFields('Upcoming events'), [
            self::countField(4),
            ['key' => 'layout', 'type' => 'select', 'label' => 'Layout', 'options' => ['cards' => 'Photo cards with date badges', 'list' => 'List with date tiles', 'grid' => 'Two columns'], 'default' => 'cards'],
            ['key' => 'empty_text', 'type' => 'text', 'label' => 'Text when there are no events', 'max' => 160, 'default' => 'No upcoming events right now. New dates are added regularly.'],
        ], self::linkFields('Open the calendar', '/events/calendar'));
    }

    public static function data(array $settings, array $ctx): array
    {
        $viewer = $ctx['viewer'] ?? null;
        [$cond, $params] = event_when_sql('upcoming', new \DateTimeImmutable('now', utc()), site_tz());
        $rows = q_all(
            'SELECT e.* FROM events e WHERE ' . published_sql('e') . ' AND ' . visibility_sql('e', $viewer) . " AND $cond ORDER BY e.start_at LIMIT " . (int) $settings['count'],
            $params
        );
        media_preload(array_column($rows, 'cover_media_id'));
        return ['events' => $rows];
    }
}
