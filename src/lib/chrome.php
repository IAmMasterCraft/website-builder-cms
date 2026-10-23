<?php
declare(strict_types=1);

/** Fallback main menu used until a menu is assigned to "main". */
function default_main_menu(): array
{
    $out = [];
    foreach (['home', 'news', 'events', 'gallery', 'leaders', 'downloads'] as $k) {
        [$label, $href] = menu_list_targets()[$k];
        $out[] = ['label' => $label, 'href' => $href, 'new_tab' => false, 'active' => menu_is_active($href, request_path()), 'children' => []];
    }
    return $out;
}

/** Everything the public layout prints around the page (header, footer). */
function site_chrome(): array
{
    $path = request_path();
    $main = menu_tree('main', $path);
    $hasMainMenu = q_val("SELECT id FROM menus WHERE location = 'main'") !== null;
    $footer = [];
    foreach (['footer_1', 'footer_2', 'footer_3', 'footer_4'] as $loc) {
        $items = menu_tree($loc, $path);
        if ($items !== []) {
            $footer[] = ['title' => (string) q_val('SELECT name FROM menus WHERE location = ?', [$loc]), 'items' => $items];
        }
    }
    $social = [];
    foreach ((array) setting('social', []) as $network => $url) {
        if (is_string($url) && preg_match('#^https://#i', $url)) {
            $social[(string) $network] = $url;
        }
    }
    return [
        'site_name' => (string) setting('site.name', 'Gather'),
        'tagline' => (string) setting('site.tagline', ''),
        'logo' => media_find((int) setting('site.logo_media_id', 0) ?: null),
        'topbar' => (bool) setting('header.topbar', true),
        'show_auth' => (bool) setting('header.show_auth', true),
        'sticky' => (bool) setting('header.sticky', true),
        'phone' => (string) setting('contact.phone', ''),
        'email' => (string) setting('contact.email', ''),
        'address' => (string) setting('contact.address', ''),
        'charity_number' => (string) setting('org.charity_number', ''),
        'social' => $social,
        'topbar_menu' => menu_tree('topbar', $path),
        'main_menu' => $hasMainMenu ? $main : default_main_menu(),
        'footer_menus' => $footer,
        'footer_about' => (string) setting('site.footer_about', ''),
        'latest_posts' => posts_latest(3, current_user()),
        'user' => current_user(),
        'path' => $path,
        'flash' => flash(),
        'theme_css' => theme_css_url(),
        'year' => (new DateTimeImmutable('now', site_tz()))->format('Y'),
    ];
}

/** Admin sidebar: sections visible to the current user. */
function admin_nav(?array $user): array
{
    $sections = [
        'Site' => [
            ['/admin', 'Overview', 'home', null],
            ['/admin/pages', 'Pages', 'file', 'pages_menus'],
            ['/admin/menus', 'Menus', 'menu', 'pages_menus'],
            ['/admin/media', 'Media library', 'image', 'gallery_media'],
        ],
        'Content' => [
            ['/admin/posts', 'Posts', 'pen', 'content'],
            ['/admin/categories', 'Categories', 'tag', 'content'],
            ['/admin/events', 'Events', 'calendar', 'events'],
            ['/admin/albums', 'Gallery', 'grid', 'gallery_media'],
            ['/admin/downloads', 'Downloads', 'download', 'content'],
            ['/admin/leaders', 'Leaders', 'users', 'content'],
            ['/admin/leader-groups', 'Leader groups', 'layers', 'content'],
            ['/admin/awards', 'Awards', 'award', 'content'],
            ['/admin/faqs', 'FAQ', 'help', 'content'],
            ['/admin/branches', 'Branches', 'pin', 'content'],
            ['/admin/groups', 'Groups', 'flag', 'members'],
        ],
        'System' => [
            ['/admin/settings', 'Site settings', 'settings', 'super_admin'],
        ],
    ];
    $out = [];
    foreach ($sections as $title => $links) {
        $visible = array_values(array_filter($links, static fn (array $l): bool => $l[3] === null || user_can($user, $l[3])));
        if ($visible !== []) {
            $out[$title] = $visible;
        }
    }
    return $out;
}

function admin_chrome(): array
{
    $user = current_user();
    $unread = (int) q_val('SELECT COUNT(*) FROM contact_messages WHERE read_at IS NULL');
    $pendingComments = (int) q_val("SELECT COUNT(*) FROM comments WHERE status IN ('pending','reported')");
    $pendingJobs = (int) q_val("SELECT COUNT(*) FROM jobs WHERE status = 'pending'");
    return [
        'site_name' => (string) setting('site.name', 'Gather'),
        'user' => $user,
        'nav' => admin_nav($user),
        'path' => request_path(),
        'flash' => flash(),
        'notifications' => $unread + $pendingComments + $pendingJobs,
        'notification_detail' => ['unread' => $unread, 'comments' => $pendingComments, 'jobs' => $pendingJobs],
    ];
}
