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

    // Membership (§4)
    $r->get('/join', [Site\Join::class, 'form']);
    $r->post('/join', [Site\Join::class, 'submit']);
    $r->get('/join/group', [Site\Join::class, 'groupForm']);
    $r->post('/join/group', [Site\Join::class, 'groupSubmit']);
    $r->get('/join/verify/{token:[a-f0-9]{64}}', [Site\Join::class, 'verifyForm']);
    $r->post('/join/verify/{token:[a-f0-9]{64}}', [Site\Join::class, 'verify']);
    $r->get('/verify/{number:[A-Za-z0-9._%-]{1,60}}/{code:[a-z0-9]{6,40}}', [Site\Verify::class, 'show']);
    $r->get('/verify/{number:[A-Za-z0-9._%-]{1,60}}/{code:[a-z0-9]{6,40}}/photo', [Site\Verify::class, 'photo']);
    $r->get('/account', [Account\Account::class, 'home']);
    $r->post('/account/verify/resend', [Site\Join::class, 'resend']);
    $r->get('/account/profile', [Account\Account::class, 'profile']);
    $r->post('/account/profile', [Account\Account::class, 'profileSave']);
    $r->post('/account/photo', [Account\Account::class, 'photo']);
    $r->get('/account/password', [Account\Account::class, 'password']);
    $r->post('/account/password', [Account\Account::class, 'passwordSave']);
    $r->post('/account/email', [Account\Account::class, 'emailRequest']);
    $r->post('/account/email/cancel', [Account\Account::class, 'emailCancel']);
    $r->get('/account/email/confirm/{token:[a-f0-9]{64}}', [Account\Account::class, 'emailConfirmForm']);
    $r->post('/account/email/confirm/{token:[a-f0-9]{64}}', [Account\Account::class, 'emailConfirm']);
    $r->get('/account/card', [Account\Account::class, 'card']);
    $r->get('/account/card/print', [Account\Account::class, 'cardPrint']);
    $r->get('/account/events', [Account\Account::class, 'events']);
    $r->post('/account/events/{id:\d+}/cancel', [Account\Account::class, 'cancelEvent']);
    $r->get('/account/downloads', [Account\Account::class, 'downloads']);
    $r->get('/account/notices', [Account\Account::class, 'notices']);
    $r->get('/account/preferences', [Account\Account::class, 'preferences']);
    $r->post('/account/preferences', [Account\Account::class, 'preferencesSave']);
    $r->post('/account/newsletter/opt-in', [Account\Account::class, 'newsletterOptIn']);
    $r->post('/account/newsletter/dismiss', [Account\Account::class, 'newsletterDismiss']);
    $r->get('/account/privacy', [Account\Account::class, 'privacy']);
    $r->get('/account/privacy/export', [Account\Account::class, 'export']);
    $r->post('/account/privacy/delete', [Account\Account::class, 'deletionRequest']);

    $r->post('/contact', [Site\Contact::class, 'send']);

    // Communications (§5), jobs (§4.6), likes and comments (§4.7)
    $unsub = '{token:[A-Za-z0-9_-]{4,300}\.[a-f0-9]{32}}';
    $r->get('/unsubscribe/' . $unsub, [Site\Unsubscribe::class, 'show']);
    $r->post('/unsubscribe/' . $unsub, [Site\Unsubscribe::class, 'confirm']);
    $r->post('/account/notices/read', [Account\Account::class, 'noticesRead']);
    $r->get('/jobs', [Site\Jobs::class, 'index']);
    $r->get('/jobs/{id:\d+}', [Site\Jobs::class, 'show']);
    $r->post('/jobs/{id:\d+}/report', [Site\Jobs::class, 'report']);
    $r->get('/account/jobs', [Account\Jobs::class, 'mine']);
    $r->get('/account/jobs/new', [Account\Jobs::class, 'create']);
    $r->post('/account/jobs', [Account\Jobs::class, 'store']);
    $r->get('/account/jobs/{id:\d+}/edit', [Account\Jobs::class, 'edit']);
    $r->post('/account/jobs/{id:\d+}', [Account\Jobs::class, 'update']);
    $r->post('/account/jobs/{id:\d+}/delete', [Account\Jobs::class, 'delete']);
    $r->post('/like', [Site\Engagement::class, 'like']);
    $r->post('/comments', [Site\Engagement::class, 'comment']);
    $r->post('/comments/{id:\d+}/edit', [Site\Engagement::class, 'edit']);
    $r->post('/comments/{id:\d+}/delete', [Site\Engagement::class, 'delete']);
    $r->post('/comments/{id:\d+}/report', [Site\Engagement::class, 'report']);

    // Radio (§6)
    $r->get('/radio', [Site\Radio::class, 'page']);
    $r->get('/api/radio/now-playing', [Site\Radio::class, 'nowPlaying']);

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

    $r->get('/admin/members', [Admin\Members::class, 'index']);
    $r->get('/admin/members.csv', [Admin\Members::class, 'csv']);
    $r->get('/admin/members/import', [Admin\Members::class, 'importForm']);
    $r->post('/admin/members/import', [Admin\Members::class, 'import']);
    $r->get('/admin/members/cards', [Admin\Members::class, 'cards']);
    $r->get('/admin/members/{id:\d+}', [Admin\Members::class, 'edit']);
    $r->post('/admin/members/{id:\d+}', [Admin\Members::class, 'update']);
    $r->post('/admin/members/{id:\d+}/status', [Admin\Members::class, 'status']);
    $r->post('/admin/members/{id:\d+}/delete', [Admin\Members::class, 'delete']);
    $r->post('/admin/members/{id:\d+}/reset', [Admin\Members::class, 'resetLink']);
    $r->post('/admin/members/{id:\d+}/card-code', [Admin\Members::class, 'regenerateCode']);
    $r->post('/admin/members/{id:\d+}/notes', [Admin\Members::class, 'addNote']);
    $r->get('/admin/members/{id:\d+}/card', [Admin\Members::class, 'card']);
    $r->get('/admin/custom-fields', [Admin\CustomFields::class, 'index']);
    $r->get('/admin/custom-fields/new', [Admin\CustomFields::class, 'create']);
    $r->post('/admin/custom-fields', [Admin\CustomFields::class, 'store']);
    $r->get('/admin/custom-fields/{id:\d+}', [Admin\CustomFields::class, 'edit']);
    $r->post('/admin/custom-fields/{id:\d+}', [Admin\CustomFields::class, 'update']);
    $r->post('/admin/custom-fields/{id:\d+}/delete', [Admin\CustomFields::class, 'delete']);
    $r->get('/admin/settings/membership', [Admin\MembershipSettings::class, 'edit']);
    $r->post('/admin/settings/membership', [Admin\MembershipSettings::class, 'update']);

    // Communications
    $r->get('/admin/settings/email', [Admin\EmailSettings::class, 'edit']);
    $r->post('/admin/settings/email', [Admin\EmailSettings::class, 'update']);
    $r->post('/admin/settings/email/test', [Admin\EmailSettings::class, 'test']);
    $r->get('/admin/campaigns', [Admin\Campaigns::class, 'index']);
    $r->post('/admin/campaigns', [Admin\Campaigns::class, 'store']);
    $r->get('/admin/campaigns/{id:\d+}', [Admin\Campaigns::class, 'edit']);
    $r->post('/admin/campaigns/{id:\d+}', [Admin\Campaigns::class, 'save']);
    $r->get('/admin/campaigns/{id:\d+}/preview', [Admin\Campaigns::class, 'preview']);
    $r->post('/admin/campaigns/{id:\d+}/test', [Admin\Campaigns::class, 'test']);
    $r->post('/admin/campaigns/{id:\d+}/send', [Admin\Campaigns::class, 'send']);
    $r->post('/admin/campaigns/{id:\d+}/cancel', [Admin\Campaigns::class, 'cancel']);
    $r->post('/admin/campaigns/{id:\d+}/duplicate', [Admin\Campaigns::class, 'duplicate']);
    $r->post('/admin/campaigns/{id:\d+}/delete', [Admin\Campaigns::class, 'delete']);
    $r->get('/admin/campaigns/{id:\d+}/log', [Admin\Campaigns::class, 'log']);
    $r->post('/admin/campaigns/{id:\d+}/retry', [Admin\Campaigns::class, 'retry']);
    $r->get('/admin/email-templates', [Admin\EmailTemplates::class, 'index']);
    $r->get('/admin/email-templates/{key:[a-z_]+}', [Admin\EmailTemplates::class, 'edit']);
    $r->post('/admin/email-templates/{key:[a-z_]+}', [Admin\EmailTemplates::class, 'update']);
    $r->get('/admin/email-templates/{key:[a-z_]+}/preview', [Admin\EmailTemplates::class, 'preview']);
    $r->post('/admin/email-templates/{key:[a-z_]+}/reset', [Admin\EmailTemplates::class, 'reset']);
    $r->post('/admin/email-templates/{key:[a-z_]+}/test', [Admin\EmailTemplates::class, 'test']);
    $r->get('/admin/email-log', [Admin\EmailLog::class, 'index']);
    $r->get('/admin/notices', [Admin\Notices::class, 'index']);
    $r->get('/admin/notices/new', [Admin\Notices::class, 'create']);
    $r->post('/admin/notices', [Admin\Notices::class, 'store']);
    $r->get('/admin/notices/{id:\d+}', [Admin\Notices::class, 'edit']);
    $r->post('/admin/notices/{id:\d+}', [Admin\Notices::class, 'update']);
    $r->post('/admin/notices/{id:\d+}/delete', [Admin\Notices::class, 'delete']);
    $r->get('/admin/inbox', [Admin\Inbox::class, 'index']);
    $r->get('/admin/inbox/{id:\d+}', [Admin\Inbox::class, 'show']);
    $r->post('/admin/inbox/{id:\d+}/read', [Admin\Inbox::class, 'read']);
    $r->post('/admin/inbox/{id:\d+}/reply', [Admin\Inbox::class, 'reply']);
    $r->post('/admin/inbox/{id:\d+}/delete', [Admin\Inbox::class, 'delete']);

    // Jobs moderation and comment reports
    $r->get('/admin/jobs', [Admin\Jobs::class, 'index']);
    $r->get('/admin/jobs/new', [Admin\Jobs::class, 'create']);
    $r->post('/admin/jobs', [Admin\Jobs::class, 'store']);
    $r->post('/admin/jobs/settings', [Admin\Jobs::class, 'settings']);
    $r->post('/admin/jobs/{id:\d+}/approve', [Admin\Jobs::class, 'approve']);
    $r->post('/admin/jobs/{id:\d+}/reject', [Admin\Jobs::class, 'reject']);
    $r->post('/admin/jobs/{id:\d+}/resolve', [Admin\Jobs::class, 'resolve']);
    $r->post('/admin/jobs/{id:\d+}/delete', [Admin\Jobs::class, 'delete']);
    $r->get('/admin/comments', [Admin\Comments::class, 'index']);
    $r->post('/admin/comments/{id:\d+}', [Admin\Comments::class, 'moderate']);

    // Radio
    $r->get('/admin/radio', [Admin\Radio::class, 'library']);
    $r->post('/admin/radio', [Admin\Radio::class, 'upload']);
    $r->post('/admin/radio/station', [Admin\Radio::class, 'station']);
    $r->post('/admin/radio/categories', [Admin\Radio::class, 'addCategory']);
    $r->post('/admin/radio/categories/{id:\d+}/delete', [Admin\Radio::class, 'deleteCategory']);
    $r->get('/admin/radio/media/{id:\d+}', [Admin\Radio::class, 'editMedia']);
    $r->post('/admin/radio/media/{id:\d+}', [Admin\Radio::class, 'updateMedia']);
    $r->post('/admin/radio/media/{id:\d+}/delete', [Admin\Radio::class, 'deleteMedia']);
    $r->get('/admin/radio/playlists', [Admin\Radio::class, 'playlists']);
    $r->post('/admin/radio/playlists', [Admin\Radio::class, 'createPlaylist']);
    $r->get('/admin/radio/playlists/{id:\d+}', [Admin\Radio::class, 'playlist']);
    $r->post('/admin/radio/playlists/{id:\d+}', [Admin\Radio::class, 'updatePlaylist']);
    $r->post('/admin/radio/playlists/{id:\d+}/delete', [Admin\Radio::class, 'deletePlaylist']);
    $r->get('/admin/radio/schedule', [Admin\Radio::class, 'schedule']);
    $r->post('/admin/radio/schedule', [Admin\Radio::class, 'createSlot']);
    $r->get('/admin/radio/schedule/{id:\d+}', [Admin\Radio::class, 'editSlot']);
    $r->post('/admin/radio/schedule/{id:\d+}', [Admin\Radio::class, 'updateSlot']);
    $r->post('/admin/radio/schedule/{id:\d+}/delete', [Admin\Radio::class, 'deleteSlot']);

    // Super admin: users & roles, audit log, backups, system health (§7)
    $r->get('/admin/users', [Admin\Users::class, 'index']);
    $r->get('/admin/users/new', [Admin\Users::class, 'create']);
    $r->post('/admin/users', [Admin\Users::class, 'store']);
    $r->get('/admin/users/{id:\d+}', [Admin\Users::class, 'edit']);
    $r->post('/admin/users/{id:\d+}', [Admin\Users::class, 'update']);
    $r->post('/admin/users/{id:\d+}/status', [Admin\Users::class, 'status']);
    $r->post('/admin/users/{id:\d+}/reset', [Admin\Users::class, 'resetLink']);
    $r->get('/admin/audit', [Admin\Audit::class, 'index']);
    $r->get('/admin/audit.csv', [Admin\Audit::class, 'csv']);
    $backup = '{name:gather-\d{8}-\d{6}-(?:manual-)?(?:db\.sql\.gz|db\.sql|uploads\.zip)}';
    $r->get('/admin/backups', [Admin\Backups::class, 'index']);
    $r->post('/admin/backups/database', [Admin\Backups::class, 'downloadDb']);
    $r->post('/admin/backups/uploads', [Admin\Backups::class, 'downloadUploads']);
    $r->post('/admin/backups/settings', [Admin\Backups::class, 'settings']);
    $r->post('/admin/backups/run', [Admin\Backups::class, 'runNow']);
    $r->get('/admin/backups/files/' . $backup, [Admin\Backups::class, 'file']);
    $r->post('/admin/backups/files/' . $backup . '/delete', [Admin\Backups::class, 'delete']);
    $r->get('/admin/health', [Admin\Health::class, 'index']);

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
