<?php
declare(strict_types=1);

namespace Admin;

/** In-app help centre for staff (Addendum B3): every guide in docs/, rendered safely. */
final class Help
{
    public static function index(array $p): void
    {
        self::page(['slug' => 'index']);
    }

    public static function page(array $p): void
    {
        require_staff();
        $slug = (string) ($p['slug'] ?? 'index');
        if (!isset(help_manifest()[$slug])) {
            abort(404);
        }
        $page = help_page($slug, 'admin');
        [$prev, $next] = help_neighbours($slug, 'admin');
        render('admin/help', [
            'title' => $page['title'] . ' · Help', 'slug' => $slug, 'page' => $page, 'toc' => help_toc('admin'),
            'prev' => $prev, 'next' => $next, 'q' => '', 'results' => null, 'body_class' => 'help-mode',
        ], 'admin');
    }

    public static function search(array $p): void
    {
        require_staff();
        $q = mb_substr(query_param('q'), 0, 100);
        render('admin/help', [
            'title' => 'Search the help · Help', 'slug' => '', 'page' => null, 'toc' => help_toc('admin'),
            'prev' => null, 'next' => null, 'q' => $q, 'results' => help_search($q, 'admin'), 'body_class' => 'help-mode',
        ], 'admin');
    }

    public static function image(array $p): void
    {
        require_staff();
        self::sendImage((string) $p['name'], false);
    }

    /** Send a docs image (name checked against the allow-list and the docs/images folder). */
    public static function sendImage(string $name, bool $public): never
    {
        $file = help_image_file($name);
        if ($file === null) {
            abort(404);
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = ['webp' => 'image/webp', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'][$ext];
        send_file($file, $mime, basename($file), true, $public);
    }
}
