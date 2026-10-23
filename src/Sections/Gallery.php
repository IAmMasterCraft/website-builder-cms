<?php
declare(strict_types=1);

namespace Sections;

final class Gallery extends Section
{
    public const KEY = 'gallery';
    public const LABEL = 'Gallery';
    public const DESCRIPTION = 'Photos from one album or the latest uploads, as a grid, masonry or strip.';
    public const GROUP = 'Content';

    public static function schema(): array
    {
        return array_merge(self::headerFields('From the gallery'), [
            ['key' => 'source', 'type' => 'select', 'label' => 'Photos from', 'options' => ['latest' => 'Latest photos from all albums', 'album' => 'One album'], 'default' => 'latest'],
            ['key' => 'album_id', 'type' => 'select', 'label' => 'Album', 'options' => 'album_options', 'show_if' => ['source', ['album']]],
            self::countField(8, 48, 'How many photos'),
            ['key' => 'layout', 'type' => 'select', 'label' => 'Layout', 'options' => ['grid' => 'Square grid', 'masonry' => 'Masonry (natural shapes)', 'strip' => 'Strip (swipe sideways)'], 'default' => 'grid'],
        ], self::linkFields('View all albums', '/gallery'));
    }

    public static function data(array $settings, array $ctx): array
    {
        $viewer = $ctx['viewer'] ?? null;
        $albumOk = published_sql('a') . ' AND ' . visibility_sql('a', $viewer);
        $limit = (int) $settings['count'];
        if ($settings['source'] === 'album' && (int) $settings['album_id'] > 0) {
            $photos = q_all("SELECT ph.* FROM album_photos ph JOIN albums a ON a.id = ph.album_id WHERE a.id = ? AND $albumOk ORDER BY ph.sort, ph.id LIMIT $limit", [(int) $settings['album_id']]);
        } else {
            $photos = q_all("SELECT ph.* FROM album_photos ph JOIN albums a ON a.id = ph.album_id WHERE $albumOk ORDER BY ph.created_at DESC, ph.id DESC LIMIT $limit");
        }
        media_preload(array_column($photos, 'media_id'));
        return ['photos' => $photos];
    }
}
