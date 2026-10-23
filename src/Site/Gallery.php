<?php
declare(strict_types=1);

namespace Site;

final class Gallery
{
    public static function index(array $p): void
    {
        $viewer = current_user();
        $albums = q_all(
            'SELECT a.*, (SELECT COUNT(*) FROM album_photos ph WHERE ph.album_id = a.id) AS photo_count FROM albums a WHERE '
            . published_sql('a') . ' AND ' . visibility_sql('a', $viewer) . ' ORDER BY COALESCE(a.album_date, DATE(a.created_at)) DESC, a.id DESC'
        );
        media_preload(array_column($albums, 'cover_media_id'));
        render('site/gallery_index', ['title' => 'Gallery', 'albums' => $albums, 'canonical' => '/gallery']);
    }

    public static function album(array $p): void
    {
        $viewer = current_user();
        $album = q_one('SELECT * FROM albums WHERE slug = ? AND ' . published_sql(), [$p['slug']]);
        if ($album === null && is_staff($viewer)) {
            $album = q_one('SELECT * FROM albums WHERE slug = ?', [$p['slug']]);
        }
        if ($album === null) {
            abort(404);
        }
        $locked = $album['visibility'] === 'members' && !viewer_is_member($viewer);
        $pg = paging(48);
        $total = (int) q_val('SELECT COUNT(*) FROM album_photos WHERE album_id = ?', [$album['id']]);
        $photos = $locked ? [] : q_all(
            'SELECT * FROM album_photos WHERE album_id = ? ORDER BY sort, id LIMIT ' . $pg['per'] . ' OFFSET ' . $pg['offset'],
            [$album['id']]
        );
        media_preload(array_column($photos, 'media_id'));
        $others = q_all(
            'SELECT title, slug FROM albums WHERE id <> ? AND ' . published_sql() . ' AND ' . visibility_sql('', $viewer) . ' ORDER BY COALESCE(album_date, DATE(created_at)) DESC LIMIT 12',
            [$album['id']]
        );
        render('site/gallery_album', [
            'title' => $album['seo_title'] ?: $album['title'],
            'meta_description' => $album['seo_description'] ?: excerpt($album['summary'] ?: $album['body'], 300),
            'album' => $album, 'photos' => $photos, 'total' => $total, 'page' => $pg['page'], 'per' => $pg['per'],
            'locked' => $locked, 'others' => $others, 'canonical' => '/gallery/' . $album['slug'],
        ]);
    }
}
