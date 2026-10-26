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
    [$theme, $themePreview] = request_theme();
    $main = menu_tree('main', $path);
    $hasMainMenu = q_val("SELECT id FROM menus WHERE location = 'main'") !== null;
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
        'footer_blocks' => footer_blocks($path, $social),
        'listen_live' => listen_live_visible(),
        'footer_about' => (string) setting('site.footer_about', ''),
        'footer_cta' => footer_cta_settings(),
        'donate_links' => donate_links(),
        'user' => current_user(),
        'path' => $path,
        'flash' => flash(),
        'theme_css' => theme_css_url($theme, !$themePreview),
        // No analytics inside the builder/design preview frames.
        'analytics' => $themePreview || str_starts_with($path, '/admin') ? null : analytics_config(),
        'year' => (new DateTimeImmutable('now', site_tz()))->format('Y'),
    ];
}

/** The footer's call-to-action strip and big wordmark (Header and footer screen). */
function footer_cta_settings(): array
{
    return [
        'footer_cta' => (bool) setting('footer.cta', true),
        'footer_word' => (bool) setting('footer.wordmark', true),
        'footer_cta_heading' => (string) setting('footer.cta_heading', 'Be part of what happens next.'),
        'footer_cta_text' => (string) setting('footer.cta_text', 'Join as a member, volunteer an afternoon or simply come along to the next event.'),
    ];
}

/**
 * Admin sidebar (B1): groups of links visible to the current user. Each
 * group has a key (for remembering its open state), a label and an icon
 * (shown alone when the sidebar is folded to icons). Links the user may
 * not open are left out; empty groups disappear.
 * @return list<array{key:string,label:string,icon:string,links:list<array{0:string,1:string,2:string}>}>
 */
function admin_nav(?array $user): array
{
    $groups = [
        ['site', 'Site', 'globe', [
            ['/admin', 'Overview', 'home', null],
            ['/admin/pages', 'Pages', 'file', 'pages_menus'],
            ['/admin/templates', 'Templates', 'layout-template', 'pages_menus'],
            ['/admin/menus', 'Menus', 'menu', 'pages_menus'],
            ['/admin/layout', 'Header and footer', 'panels', 'pages_menus'],
            ['/admin/media', 'Media library', 'image', 'gallery_media'],
        ]],
        ['content', 'Content', 'layers', [
            ['/admin/posts', 'Posts', 'pen', 'content'],
            ['/admin/categories', 'Categories', 'tag', 'content'],
            ['/admin/events', 'Events', 'calendar', 'events'],
            ['/admin/albums', 'Gallery', 'images', 'gallery_media'],
            ['/admin/downloads', 'Downloads', 'file-down', 'content'],
            ['/admin/leaders', 'Leaders', 'users', 'content'],
            ['/admin/leader-groups', 'Leader groups', 'network', 'content'],
            ['/admin/awards', 'Awards', 'award', 'content'],
            ['/admin/faqs', 'FAQ', 'help', 'content'],
            ['/admin/branches', 'Branches', 'pin', 'content'],
        ]],
        ['members', 'Members', 'id-card', [
            ['/admin/members', 'Members', 'users', 'members'],
            ['/admin/groups', 'Groups', 'building', 'members'],
            ['/admin/custom-fields', 'Custom fields', 'text-input', 'super_admin'],
            ['/admin/settings/membership', 'Membership settings', 'settings', 'super_admin'],
        ]],
        ['community', 'Community', 'heart-handshake', [
            ['/admin/jobs', 'Jobs', 'briefcase', 'jobs'],
            ['/admin/comments', 'Comments', 'message', 'content'],
        ]],
        ['communications', 'Communications', 'mail', [
            ['/admin/campaigns', 'Campaigns', 'send', 'communications'],
            ['/admin/notices', 'Notices', 'bell', 'communications'],
            ['/admin/inbox', 'Inbox', 'inbox', 'communications'],
            ['/admin/email-templates', 'Email templates', 'file', 'communications'],
            ['/admin/email-log', 'Delivery log', 'activity', 'communications'],
            ['/admin/email-suppressions', 'Suppression list', 'mail-x', 'communications'],
            ['/admin/settings/email', 'Email settings', 'settings', 'super_admin'],
        ]],
        ['radio', 'Radio', 'radio', [
            ['/admin/radio', 'Library', 'music', 'radio'],
            ['/admin/radio/playlists', 'Playlists', 'list-music', 'radio'],
            ['/admin/radio/schedule', 'Schedule', 'calendar-days', 'radio'],
        ]],
        ['system', 'System', 'settings', [
            ['/admin/design', 'Design', 'palette', 'super_admin'],
            ['/admin/settings', 'Site settings', 'settings', 'super_admin'],
            ['/admin/users', 'Users and roles', 'shield', 'super_admin'],
            ['/admin/audit', 'Audit log', 'history', 'super_admin'],
            ['/admin/backups', 'Backups', 'hard-drive', 'super_admin'],
            ['/admin/health', 'System health', 'activity', 'super_admin'],
        ]],
    ];
    $out = [];
    foreach ($groups as [$key, $label, $groupIcon, $links]) {
        $visible = [];
        foreach ($links as [$href, $text, $icon, $perm]) {
            if ($perm === null || user_can($user, $perm)) {
                $visible[] = [$href, $text, $icon];
            }
        }
        if ($visible !== []) {
            $out[] = ['key' => $key, 'label' => $label, 'icon' => $groupIcon, 'links' => $visible];
        }
    }
    return $out;
}

/** The most specific sidebar link for a path ('' when none matches), and its group key. */
function admin_nav_active(array $nav, string $path): array
{
    // Screens without their own link highlight the link they belong to.
    foreach (['/admin/impersonate' => '/admin/members'] as $prefix => $owner) {
        if (str_starts_with($path, $prefix . '/')) {
            $path = $owner;
        }
    }
    $href = '';
    $group = '';
    foreach ($nav as $g) {
        foreach ($g['links'] as [$h]) {
            $match = $h === '/admin' ? $path === '/admin' : ($path === $h || str_starts_with($path, $h . '/') || str_starts_with($path, $h . '.'));
            if ($match && strlen($h) > strlen($href)) {
                $href = $h;
                $group = $g['key'];
            }
        }
    }
    return [$href, $group];
}

function admin_chrome(): array
{
    $user = current_user();
    $unread = (int) q_val('SELECT COUNT(*) FROM contact_messages WHERE read_at IS NULL');
    $pendingComments = comments_reported_count();
    $pendingJobs = (int) q_val("SELECT COUNT(*) FROM jobs WHERE status = 'pending'");
    return [
        'site_name' => (string) setting('site.name', 'Gather'),
        'user' => $user,
        'nav' => $nav = admin_nav($user),
        'nav_active' => admin_nav_active($nav, request_path()),
        'path' => request_path(),
        'flash' => flash(),
        'notifications' => $unread + $pendingComments + $pendingJobs,
        'notification_detail' => ['unread' => $unread, 'comments' => $pendingComments, 'jobs' => $pendingJobs],
    ];
}

/** Footer widgets a column can show (§3.2). */
function footer_widgets(): array
{
    return [
        'about' => 'About text and social icons',
        'menu' => 'A footer menu',
        'contact' => 'Contact details',
        'latest_posts' => 'Latest news',
        'social' => 'Social links',
        'donate' => 'Donate',
        'join' => '“Join us” button',
    ];
}

/** Up to four configured footer columns (defaults until the admin saves some). */
function footer_columns(): array
{
    $saved = setting('footer.columns');
    if (!is_array($saved)) {
        return [
            ['widget' => 'about', 'title' => '', 'menu' => 'footer_1', 'text' => ''],
            ['widget' => 'menu', 'title' => '', 'menu' => 'footer_1', 'text' => ''],
            ['widget' => 'menu', 'title' => '', 'menu' => 'footer_2', 'text' => ''],
            ['widget' => 'contact', 'title' => '', 'menu' => 'footer_1', 'text' => ''],
        ];
    }
    $out = [];
    foreach (array_slice($saved, 0, 4) as $c) {
        if (is_array($c) && array_key_exists((string) ($c['widget'] ?? ''), footer_widgets())) {
            $out[] = ['widget' => (string) $c['widget'], 'title' => (string) ($c['title'] ?? ''), 'menu' => (string) ($c['menu'] ?? 'footer_1'), 'text' => (string) ($c['text'] ?? '')];
        }
    }
    return $out;
}

/** Footer columns with the data each widget prints; empty widgets are dropped. */
function footer_blocks(string $path, array $social): array
{
    $blocks = [];
    foreach (footer_columns() as $c) {
        $b = $c + ['items' => [], 'posts' => [], 'links' => []];
        switch ($c['widget']) {
            case 'menu':
                $b['items'] = menu_tree($c['menu'], $path);
                if ($b['items'] === []) {
                    continue 2;
                }
                $b['title'] = $c['title'] !== '' ? $c['title'] : (string) q_val('SELECT name FROM menus WHERE location = ?', [$c['menu']]);
                break;
            case 'latest_posts':
                $b['posts'] = posts_latest(3, current_user());
                if ($b['posts'] === []) {
                    continue 2;
                }
                $b['title'] = $c['title'] !== '' ? $c['title'] : 'Latest news';
                break;
            case 'social':
                if ($social === []) {
                    continue 2;
                }
                $b['title'] = $c['title'] !== '' ? $c['title'] : 'Follow us';
                break;
            case 'donate':
                $b['links'] = donate_links();
                $b['title'] = $c['title'] !== '' ? $c['title'] : 'Support us';
                $b['text'] = $c['text'] !== '' ? $c['text'] : 'Your donation keeps our community activities going.';
                break;
            case 'join':
                $b['title'] = $c['title'] !== '' ? $c['title'] : 'Become a member';
                $b['text'] = $c['text'] !== '' ? $c['text'] : 'Join us for events, news and a digital member card.';
                break;
            case 'contact':
                $b['title'] = $c['title'] !== '' ? $c['title'] : 'Contact';
                break;
            case 'about':
                $b['text'] = $c['text'] !== '' ? $c['text'] : (string) setting('site.footer_about', '');
                break;
        }
        $blocks[] = $b;
    }
    return $blocks;
}

/** "Listen live" header button: setting on and the station switched on and on air (§3.2, §6). */
function listen_live_visible(): bool
{
    return (bool) setting('header.listen_live', true) && radio_on_air();
}