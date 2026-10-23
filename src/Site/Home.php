<?php
declare(strict_types=1);

namespace Site;

final class Home
{
    public static function show(array $p): void
    {
        $viewer = current_user();
        $homeId = (int) setting('home.page_id', 0);
        if ($homeId > 0) {
            $page = Pages::findVisible('id', (string) $homeId, $viewer);
            if ($page !== null) {
                Pages::renderPage($page, true);
                return;
            }
        }
        [$cond, $params] = event_when_sql('upcoming', new \DateTimeImmutable('now', utc()), site_tz());
        $events = q_all(
            'SELECT e.* FROM events e WHERE ' . published_sql('e') . ' AND ' . visibility_sql('e', $viewer) . " AND $cond ORDER BY e.start_at LIMIT 4",
            $params
        );
        $posts = posts_latest(3, $viewer);
        $albums = q_all('SELECT * FROM albums WHERE ' . published_sql() . ' AND ' . visibility_sql('', $viewer) . ' ORDER BY COALESCE(album_date, DATE(created_at)) DESC LIMIT 3');
        media_preload(array_merge(array_column($posts, 'cover_media_id'), array_column($albums, 'cover_media_id')));
        $heroImage = null;
        foreach (array_merge($events, $posts) as $item) {
            if (!empty($item['cover_media_id'])) {
                $heroImage = media_find((int) $item['cover_media_id']);
                break;
            }
        }
        $stats = [
            'events' => (int) q_val('SELECT COUNT(*) FROM events WHERE ' . published_sql()),
            'groups' => (int) q_val("SELECT COUNT(*) FROM groups_orgs WHERE status = 'active'"),
            'branches' => (int) q_val('SELECT COUNT(*) FROM branches WHERE ' . published_sql()),
            'photos' => (int) q_val('SELECT COUNT(*) FROM album_photos'),
        ];
        render('site/home', [
            'title' => '',
            'events' => $events,
            'posts' => $posts,
            'albums' => $albums,
            'hero_image' => $heroImage,
            'stats' => $stats,
            'canonical' => '/',
        ]);
    }
}
