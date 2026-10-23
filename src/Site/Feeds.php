<?php
declare(strict_types=1);

namespace Site;

final class Feeds
{
    public static function rss(array $p): void
    {
        $posts = q_all(
            "SELECT p.*, c.name AS category_name, c.base FROM posts p JOIN categories c ON c.id = p.category_id
             WHERE " . published_sql('p') . " AND p.visibility = 'public' ORDER BY p.publish_at DESC LIMIT 20"
        );
        header('Content-Type: application/rss+xml; charset=utf-8');
        header('Cache-Control: public, max-age=600');
        echo rss_feed($posts, (string) setting('site.name'), (string) setting('site.tagline'));
    }

    public static function eventsIcs(array $p): void
    {
        $events = q_all(
            "SELECT * FROM events WHERE " . published_sql() . " AND visibility = 'public'
             AND COALESCE(end_at, start_at) >= UTC_TIMESTAMP() - INTERVAL 90 DAY ORDER BY start_at LIMIT 500"
        );
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename="events.ics"');
        header('Cache-Control: public, max-age=900');
        echo ics_calendar($events, setting('site.name') . ' events');
    }

    public static function sitemap(array $p): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo sitemap_xml(sitemap_urls());
    }

    public static function robots(array $p): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        echo "User-agent: *\nDisallow: /admin\nDisallow: /account\nDisallow: /login\nDisallow: /search\nDisallow: /media/\n\nSitemap: " . base_url('/sitemap.xml') . "\n";
    }

    public static function themeCss(array $p): void
    {
        // Any installed theme may be requested (the Design screen previews them);
        // plain=1 leaves out the saved customisations.
        $key = query_param("t");
        $key = array_key_exists($key, theme_list()) ? $key : active_theme();
        $css = theme_css($key, query_param("plain") !== "1");
        $etag = '"' . substr(sha1($css), 0, 16) . '"';
        header('Content-Type: text/css; charset=utf-8');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: ' . $etag);
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            return;
        }
        echo $css;
    }

    /** themes/<key>/preview.png (the themes folder itself is not web-reachable). */
    public static function themePreview(array $p): void
    {
        $key = (string) $p['key'];
        if (!array_key_exists($key, theme_list()) || !is_file(BASE . '/themes/' . $key . '/preview.png')) {
            abort(404);
        }
        send_file(BASE . '/themes/' . $key . '/preview.png', 'image/png', $key . '.png', true, true);
    }
}
