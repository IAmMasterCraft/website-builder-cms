<?php
declare(strict_types=1);

namespace Sections;

final class Stats extends Section
{
    public const KEY = 'stats';
    public const LABEL = 'Stats and counters';
    public const DESCRIPTION = 'A row of big numbers: type your own or count members, events and more automatically.';
    public const GROUP = 'Basics';

    public const METRICS = [
        'custom' => 'My own number',
        'members' => 'Active members (counted)',
        'events' => 'Events organised (counted)',
        'branches' => 'Branches (counted)',
        'groups' => 'Affiliated groups (counted)',
        'photos' => 'Photos in the gallery (counted)',
        'posts' => 'News posts (counted)',
    ];

    public static function schema(): array
    {
        return array_merge(self::headerFields(), [
            self::headLayoutField('stacked'),
            ['key' => 'layout', 'type' => 'select', 'label' => 'Layout', 'options' => ['cards' => 'Cards', 'band' => 'Band with icon tiles', 'rules' => 'Big numbers with accent rules'], 'default' => 'cards'],
            ['key' => 'animate', 'type' => 'checkbox', 'label' => 'Count up when scrolled into view', 'default' => true],
            ['key' => 'items', 'type' => 'repeater', 'label' => 'Numbers', 'min' => 1, 'max' => 6, 'item_label' => 'label', 'add_label' => 'Add number',
                'default' => [
                    ['metric' => 'events', 'value' => '', 'label' => 'events organised'],
                    ['metric' => 'branches', 'value' => '', 'label' => 'local branches'],
                    ['metric' => 'groups', 'value' => '', 'label' => 'affiliated groups'],
                    ['metric' => 'photos', 'value' => '', 'label' => 'photos shared'],
                ],
                'fields' => [
                    ['key' => 'metric', 'type' => 'select', 'label' => 'Number', 'options' => self::METRICS, 'default' => 'custom'],
                    ['key' => 'value', 'type' => 'text', 'label' => 'Your number (e.g. 25+ or £12k)', 'max' => 16, 'show_if' => ['metric', ['custom']]],
                    ['key' => 'label', 'type' => 'text', 'label' => 'Label', 'max' => 60, 'required' => true],
                    ['key' => 'icon', 'type' => 'select', 'label' => 'Icon', 'options' => 'icon_options_none', 'default' => ''],
                ]],
        ]);
    }

    public static function defaultStyle(): array
    {
        return ['background' => 'soft', 'spacing' => 'compact'] + parent::defaultStyle();
    }

    public static function data(array $settings, array $ctx): array
    {
        $values = [];
        foreach ($settings['items'] as $i => $item) {
            $values[$i] = match ($item['metric'] ?? 'custom') {
                'members' => (string) (int) q_val("SELECT COUNT(*) FROM members m JOIN users u ON u.id = m.user_id WHERE u.status = 'active'"),
                'events' => (string) (int) q_val('SELECT COUNT(*) FROM events WHERE ' . published_sql()),
                'branches' => (string) (int) q_val('SELECT COUNT(*) FROM branches WHERE ' . published_sql()),
                'groups' => (string) (int) q_val("SELECT COUNT(*) FROM groups_orgs WHERE status = 'active'"),
                'photos' => (string) (int) q_val('SELECT COUNT(*) FROM album_photos'),
                'posts' => (string) (int) q_val('SELECT COUNT(*) FROM posts WHERE ' . published_sql()),
                default => (string) ($item['value'] ?? ''),
            };
        }
        return ['values' => $values];
    }
}
