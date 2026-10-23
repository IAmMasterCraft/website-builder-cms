<?php
declare(strict_types=1);

namespace Site;

final class Pages
{
    /** A page the viewer may see: published (staff may preview anything), not hidden for visitors. */
    public static function findVisible(string $column, string $value, ?array $viewer): ?array
    {
        $col = $column === 'id' ? 'id' : 'slug';
        $page = q_one('SELECT * FROM pages WHERE ' . $col . ' = ?', [$value]);
        if ($page === null) {
            return null;
        }
        $live = in_array($page['status'], ['published', 'scheduled'], true) && ($page['publish_at'] === null || $page['publish_at'] <= utc_now());
        if (is_staff($viewer)) {
            return $page;
        }
        if (!$live || $page['visibility'] === 'hidden') {
            return null;
        }
        return $page;
    }

    public static function show(array $p): void
    {
        $viewer = current_user();
        $page = self::findVisible('slug', $p['slug'], $viewer);
        if ($page === null) {
            abort(404);
        }
        if ((int) setting('home.page_id', 0) === (int) $page['id']) {
            redirect('/', 301);
        }
        self::renderPage($page, false);
    }

    public static function renderPage(array $page, bool $isHome): void
    {
        $viewer = current_user();
        if ($page['visibility'] === 'members' && !viewer_is_member($viewer)) {
            render('site/members_only', ['title' => $page['title'], 'noindex' => true], 'site', 403);
            return;
        }
        $live = in_array($page['status'], ['published', 'scheduled'], true) && ($page['publish_at'] === null || $page['publish_at'] <= utc_now());
        $cover = media_find($page['cover_media_id'] ? (int) $page['cover_media_id'] : null);
        render('site/page', [
            'title' => $isHome ? '' : ($page['seo_title'] ?: $page['title']),
            'meta_description' => $page['seo_description'] ?: excerpt($page['summary'] ?: $page['body'], 300),
            'page' => $page,
            'cover' => $cover,
            'is_home' => $isHome,
            'is_preview' => !$live || $page['visibility'] === 'hidden',
            'canonical' => $isHome ? '/' : '/' . $page['slug'],
            'noindex' => $page['visibility'] !== 'public',
        ]);
    }
}
