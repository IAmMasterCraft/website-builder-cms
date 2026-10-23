<?php
declare(strict_types=1);

namespace Sections;

final class Leaders extends Section
{
    public const KEY = 'leaders';
    public const LABEL = 'Leaders';
    public const DESCRIPTION = 'People from one leadership group, as photo cards or a compact list.';
    public const GROUP = 'Community';

    public static function schema(): array
    {
        return array_merge(self::headerFields('Our leaders'), [
            ['key' => 'group_id', 'type' => 'select', 'label' => 'Group', 'options' => 'leader_group_any_options', 'default' => ''],
            ['key' => 'layout', 'type' => 'select', 'label' => 'Layout', 'options' => ['cards' => 'Photo cards', 'list' => 'Compact list'], 'default' => 'cards'],
            self::countField(8, 24),
        ], self::linkFields('Meet everyone', '/leaders'));
    }

    public static function data(array $settings, array $ctx): array
    {
        $today = (new \DateTimeImmutable('now', site_tz()))->format('Y-m-d');
        $params = [$today];
        $where = published_sql('l') . ' AND (l.term_end IS NULL OR l.term_end >= ?)';
        if ((int) $settings['group_id'] > 0) {
            $where .= ' AND l.group_id = ?';
            $params[] = (int) $settings['group_id'];
        }
        $rows = q_all("SELECT l.*, g.slug AS group_slug FROM leaders l JOIN leader_groups g ON g.id = l.group_id WHERE $where ORDER BY g.sort, l.sort, l.name LIMIT " . (int) $settings['count'], $params);
        media_preload(array_column($rows, 'photo_media_id'));
        return ['leaders' => $rows];
    }
}
