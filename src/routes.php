<?php
declare(strict_types=1);

use Core\Router;

/** All application routes (§3.4). Order matters: specific before catch-all. */
function app_routes(): Router
{
    $r = new Router();
    $slug = '{slug:[a-z0-9][a-z0-9-]*}';

    // Feeds and machine files
    $r->get('/feed.xml', [Site\Feeds::class, 'rss']);
    $r->get('/events.ics', [Site\Feeds::class, 'eventsIcs']);
    $r->get('/sitemap.xml', [Site\Feeds::class, 'sitemap']);
    $r->get('/robots.txt', [Site\Feeds::class, 'robots']);
    $r->get('/theme.css', [Site\Feeds::class, 'themeCss']);
    $r->get('/theme-preview/{key:[a-z0-9-]+}.png', [Site\Feeds::class, 'themePreview']);

    // Media (protected originals and private variants)
    $r->get('/media/{id:\d+}/w/{w:\d+}', [Site\MediaFiles::class, 'variant']);
    $r->get('/media/{id:\d+}/{name:[A-Za-z0-9._-]+}', [Site\MediaFiles::class, 'original']);

    // Auth
    $r->get('/login', [Site\Auth::class, 'loginForm']);
    $r->post('/login', [Site\Auth::class, 'login']);
    $r->post('/logout', [Site\Auth::class, 'logout']);
    $r->get('/logout', [Site\Auth::class, 'logoutConfirm']);
    $r->get('/forgot-password', [Site\Auth::class, 'forgotForm']);
    $r->post('/forgot-password', [Site\Auth::class, 'forgot']);
    $r->get('/reset/{token:[a-f0-9]{64}}', [Site\Auth::class, 'resetForm']);
    $r->post('/reset/{token:[a-f0-9]{64}}', [Site\Auth::class, 'reset']);

    // Membership placeholders until phase 4
    $r->get('/join', [Site\Placeholder::class, 'join']);
    $r->get('/join/group', [Site\Placeholder::class, 'joinGroup']);
    $r->get('/account', [Site\Placeholder::class, 'account']);

    $r->post('/contact', [Site\Contact::class, 'send']);

    // Public content
    $r->get('/', [Site\Home::class, 'show']);
    $r->get('/search', [Site\Search::class, 'show']);
    foreach (['news', 'blog', 'projects'] as $base) {
        $r->get('/' . $base, [Site\Posts::class, 'index_' . $base]);
        $r->get('/' . $base . '/' . $slug, [Site\Posts::class, 'show_' . $base]);
    }
    $r->get('/events', [Site\Events::class, 'index']);
    $r->get('/events/calendar', [Site\Events::class, 'calendar']);
    $r->get('/events/' . $slug . '.ics', [Site\Events::class, 'ics']);
    $r->get('/events/' . $slug, [Site\Events::class, 'show']);
    $r->post('/events/' . $slug . '/register', [Site\Events::class, 'register']);
    $r->get('/gallery', [Site\Gallery::class, 'index']);
    $r->get('/gallery/' . $slug, [Site\Gallery::class, 'album']);
    $r->get('/downloads', [Site\Downloads::class, 'index']);
    $r->get('/downloads/{id:\d+}/file', [Site\Downloads::class, 'file']);
    $r->get('/leaders', [Site\Leaders::class, 'index']);
    $r->get('/leaders/{group:[a-z0-9][a-z0-9-]*}', [Site\Leaders::class, 'group']);
    $r->get('/leaders/{group:[a-z0-9][a-z0-9-]*}/' . $slug, [Site\Leaders::class, 'show']);
    $r->get('/awards', [Site\Directory::class, 'awards']);
    $r->get('/faq', [Site\Directory::class, 'faq']);
    $r->get('/branches', [Site\Directory::class, 'branches']);
    $r->get('/groups', [Site\Directory::class, 'groups']);

    // Admin
    $r->get('/admin', [Admin\Dashboard::class, 'overview']);
    $r->get('/admin/search', [Admin\Dashboard::class, 'search']);
    $r->get('/admin/settings', [Admin\Settings::class, 'edit']);
    $r->post('/admin/settings', [Admin\Settings::class, 'update']);

    $r->get('/admin/media', [Admin\Media::class, 'index']);
    $r->get('/admin/media/picker', [Admin\Media::class, 'picker']);
    $r->post('/admin/media/upload', [Admin\Media::class, 'upload']);
    $r->get('/admin/media/{id:\d+}', [Admin\Media::class, 'edit']);
    $r->post('/admin/media/{id:\d+}', [Admin\Media::class, 'update']);
    $r->post('/admin/media/{id:\d+}/replace', [Admin\Media::class, 'replace']);
    $r->post('/admin/media/{id:\d+}/delete', [Admin\Media::class, 'delete']);

    $r->get('/admin/menus', [Admin\Menus::class, 'index']);
    $r->post('/admin/menus', [Admin\Menus::class, 'createMenu']);
    $r->get('/admin/menus/{id:\d+}', [Admin\Menus::class, 'show']);
    $r->post('/admin/menus/{id:\d+}', [Admin\Menus::class, 'updateMenu']);
    $r->post('/admin/menus/{id:\d+}/delete', [Admin\Menus::class, 'deleteMenu']);
    $r->post('/admin/menus/{id:\d+}/items', [Admin\Menus::class, 'addItem']);
    $r->get('/admin/menus/{id:\d+}/items/{item:\d+}', [Admin\Menus::class, 'editItem']);
    $r->post('/admin/menus/{id:\d+}/items/{item:\d+}', [Admin\Menus::class, 'updateItem']);
    $r->post('/admin/menus/{id:\d+}/items/{item:\d+}/move', [Admin\Menus::class, 'moveItem']);
    $r->post('/admin/menus/{id:\d+}/items/{item:\d+}/toggle', [Admin\Menus::class, 'toggleItem']);
    $r->post('/admin/menus/{id:\d+}/items/{item:\d+}/delete', [Admin\Menus::class, 'deleteItem']);

    $r->get('/admin/events/{id:\d+}/registrations', [Admin\Registrations::class, 'index']);
    $r->get('/admin/events/{id:\d+}/registrations.csv', [Admin\Registrations::class, 'csv']);
    $r->post('/admin/events/{id:\d+}/registrations/{reg:\d+}', [Admin\Registrations::class, 'setStatus']);

    $r->get('/admin/albums/{id:\d+}/photos', [Admin\Albums::class, 'photos']);
    $r->post('/admin/albums/{id:\d+}/photos', [Admin\Albums::class, 'upload']);
    $r->post('/admin/albums/{id:\d+}/photos/save', [Admin\Albums::class, 'save']);
    $r->post('/admin/albums/{id:\d+}/photos/{photo:\d+}/move', [Admin\Albums::class, 'move']);
    $r->post('/admin/albums/{id:\d+}/photos/{photo:\d+}/delete', [Admin\Albums::class, 'remove']);

    // Page builder and design (before the generic content routes)
    $r->get('/admin/pages/{id:\d+}/builder', [Admin\Builder::class, 'editor']);
    $r->post('/admin/pages/{id:\d+}/builder', [Admin\Builder::class, 'save']);
    $r->post('/admin/pages/{id:\d+}/publish', [Admin\Builder::class, 'publish']);
    $r->get('/admin/pages/{id:\d+}/preview', [Admin\Builder::class, 'preview']);
    $r->post('/admin/pages/{id:\d+}/revisions/{rev:\d+}/restore', [Admin\Builder::class, 'restore']);
    $r->post('/admin/pages/{id:\d+}/templates', [Admin\Builder::class, 'saveTemplate']);
    $r->get('/admin/templates', [Admin\Builder::class, 'templates']);
    $r->post('/admin/templates/{id:\d+}/delete', [Admin\Builder::class, 'deleteTemplate']);
    $r->get('/admin/design', [Admin\Design::class, 'edit']);
    $r->post('/admin/design', [Admin\Design::class, 'update']);
    $r->get('/admin/layout', [Admin\Layout::class, 'edit']);
    $r->post('/admin/layout', [Admin\Layout::class, 'update']);

    // Generic content CRUD (type key validated against the registry)
    $type = '{type:[a-z-]+}';
    $r->get('/admin/' . $type, [Admin\Content::class, 'index']);
    $r->get('/admin/' . $type . '/new', [Admin\Content::class, 'create']);
    $r->post('/admin/' . $type, [Admin\Content::class, 'store']);
    $r->get('/admin/' . $type . '/{id:\d+}/edit', [Admin\Content::class, 'edit']);
    $r->post('/admin/' . $type . '/{id:\d+}', [Admin\Content::class, 'update']);
    $r->post('/admin/' . $type . '/{id:\d+}/delete', [Admin\Content::class, 'delete']);

    // Pages last: /{page-slug}
    $r->get('/' . $slug, [Site\Pages::class, 'show']);
    return $r;
}

/** Routes while the app is not installed yet. */
function install_routes(): Router
{
    $r = new Router();
    $r->get('/install', [Install\Wizard::class, 'start']);
    $r->get('/theme-preview/{key:[a-z0-9-]+}.png', [Site\Feeds::class, 'themePreview']);
    $r->post('/install/database', [Install\Wizard::class, 'database']);
    $r->get('/install/admin', [Install\Wizard::class, 'adminForm']);
    $r->post('/install/admin', [Install\Wizard::class, 'admin']);
    $r->get('/install/theme', [Install\Wizard::class, 'themeForm']);
    $r->post('/install/theme', [Install\Wizard::class, 'theme']);
    $r->get('/install/content', [Install\Wizard::class, 'contentForm']);
    $r->post('/install/content', [Install\Wizard::class, 'content']);
    return $r;
}
