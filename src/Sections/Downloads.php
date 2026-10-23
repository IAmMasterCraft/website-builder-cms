<?php
declare(strict_types=1);

namespace Sections;

final class Downloads extends Section
{
    public const KEY = 'downloads';
    public const LABEL = 'Downloads list';
    public const DESCRIPTION = 'Documents and files to download; members-only files show a lock.';
    public const GROUP = 'Content';

    public static function schema(): array
    {
        return array_merge(self::headerFields('Documents'), [
            ['key' => 'category', 'type' => 'select', 'label' => 'Category', 'options' => 'download_category_any_options', 'default' => ''],
            self::countField(5, 30),
        ], self::linkFields('All downloads', '/downloads'));
    }

    public static function data(array $settings, array $ctx): array
    {
        $params = [];
        $where = published_sql('d');
        if ((string) $settings['category'] !== '') {
            $where .= ' AND d.category = ?';
            $params[] = (string) $settings['category'];
        }
        $rows = q_all("SELECT d.*, m.mime, m.size, m.path FROM downloads d JOIN media m ON m.id = d.file_media_id WHERE $where ORDER BY COALESCE(d.publish_at, d.created_at) DESC LIMIT " . (int) $settings['count'], $params);
        return ['rows' => $rows, 'is_member' => viewer_is_member($ctx['viewer'] ?? null)];
    }
}
