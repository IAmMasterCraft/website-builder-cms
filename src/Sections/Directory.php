<?php
declare(strict_types=1);

namespace Sections;

final class Directory extends Section
{
    public const KEY = 'directory';
    public const LABEL = 'Branches or groups';
    public const DESCRIPTION = 'A directory of local branches or affiliated groups with contact details.';
    public const GROUP = 'Community';

    public static function schema(): array
    {
        return array_merge(self::headerFields('Find us near you'), [
            ['key' => 'source', 'type' => 'select', 'label' => 'Show', 'options' => ['branches' => 'Branches', 'groups' => 'Affiliated groups'], 'default' => 'branches'],
            ['key' => 'layout', 'type' => 'select', 'label' => 'Layout', 'options' => ['cards' => 'Cards with details', 'list' => 'Names only'], 'default' => 'cards'],
            self::countField(6, 60),
        ], self::linkFields('See all', ''));
    }

    public static function data(array $settings, array $ctx): array
    {
        $limit = (int) $settings['count'];
        if ($settings['source'] === 'groups') {
            $rows = q_all("SELECT * FROM groups_orgs WHERE status = 'active' ORDER BY sort, name LIMIT $limit");
            media_preload(array_column($rows, 'logo_media_id'));
        } else {
            $rows = q_all('SELECT * FROM branches WHERE ' . published_sql() . " ORDER BY sort, name LIMIT $limit");
        }
        return ['rows' => $rows];
    }
}
