<?php
declare(strict_types=1);

namespace Site;

/** /radio (live player + on-demand library, §6.1) and the now-playing API (§6.2). */
final class Radio
{
    public static function page(array $p): void
    {
        $category = slugify(query_param('category'));
        $q = mb_substr(query_param('q'), 0, 100);
        $pg = paging(12);
        $lib = radio_library($category, $q, $pg['per'], $pg['offset']);
        $cats = [];
        foreach (q_all('SELECT name, slug FROM radio_categories ORDER BY sort, name') as $c) {
            $cats[$c['slug']] = $c['name'];
        }
        media_preload(array_filter(array_map('intval', array_column($lib['rows'], 'cover_media_id'))));
        render('site/radio', [
            'title' => 'Radio', 'meta_description' => 'Listen live to our community radio and catch up on programmes.',
            'rows' => $lib['rows'], 'total' => $lib['total'], 'page' => $pg['page'], 'per' => $pg['per'],
            'categories' => $cats, 'category' => array_key_exists($category, $cats) ? $category : '', 'q' => $q,
            'enabled' => radio_enabled(), 'canonical' => '/radio', 'body_class' => 'radio-page',
        ]);
    }

    /** Computed from server time (UTC) so every listener hears the same position. Cacheable for 1 s. */
    public static function nowPlaying(array $p): void
    {
        header('Cache-Control: public, max-age=1');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(radio_now_playing(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
