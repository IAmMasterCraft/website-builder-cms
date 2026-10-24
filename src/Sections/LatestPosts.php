<?php
declare(strict_types=1);

namespace Sections;

final class LatestPosts extends Section
{
    public const KEY = 'latest_posts';
    public const LABEL = 'Latest posts';
    public const DESCRIPTION = 'Newest news, blog or project posts as a grid, list or carousel.';
    public const GROUP = 'Content';

    public static function schema(): array
    {
        return array_merge(self::headerFields('News and stories'), [
            ['key' => 'category', 'type' => 'select', 'label' => 'Posts from', 'options' => 'post_source_options', 'default' => 'all'],
            self::countField(3),
            ['key' => 'layout', 'type' => 'select', 'label' => 'Layout', 'options' => ['grid' => 'Grid of cards', 'list' => 'Compact list', 'carousel' => 'Carousel (swipe sideways)'], 'default' => 'grid'],
        ], self::linkFields('All news', '/news'));
    }

    public static function data(array $settings, array $ctx): array
    {
        $viewer = $ctx['viewer'] ?? null;
        $where = published_sql('p') . ' AND ' . visibility_sql('p', $viewer);
        $params = [];
        $src = (string) $settings['category'];
        if (in_array($src, ['news', 'blog', 'projects'], true)) {
            $where .= ' AND c.base = ?';
            $params[] = $src;
        } elseif (preg_match('/^cat:(\d+)$/', $src, $m)) {
            $where .= ' AND p.category_id = ?';
            $params[] = (int) $m[1];
        }
        $rows = q_all(
            "SELECT p.*, c.name AS category_name, c.base, c.slug AS category_slug FROM posts p JOIN categories c ON c.id = p.category_id WHERE $where ORDER BY p.publish_at DESC, p.id DESC LIMIT " . (int) $settings['count'],
            $params
        );
        media_preload(array_column($rows, 'cover_media_id'));
        engagement_preload('post', array_map('intval', array_column($rows, 'id')));
        return ['posts' => $rows];
    }
}
