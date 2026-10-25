<?php
declare(strict_types=1);

use Core\Sanitizer;

/*
 * Content-type registry and generic model (§3.3).
 *
 * Each type is an array: table, labels, admin permission, fields (which drive
 * validation and the admin form), list columns, and optional hooks for the
 * parts that are not plain columns (post tags, event tickets, leader social links).
 */

const PUBLISH_STATUSES = ['draft' => 'Draft', 'published' => 'Published', 'scheduled' => 'Scheduled'];
const SIMPLE_STATUSES = ['published' => 'Published', 'draft' => 'Draft'];
const VISIBILITIES = ['public' => 'Everyone', 'members' => 'Members only'];

/** Slugs a page may not use because a built-in route owns them. */
const RESERVED_SLUGS = [
    'admin', 'account', 'api', 'news', 'blog', 'projects', 'events', 'gallery', 'downloads', 'leaders',
    'awards', 'faq', 'branches', 'groups', 'radio', 'jobs', 'join', 'login', 'logout', 'forgot-password',
    'reset', 'verify', 'search', 'install', 'media', 'uploads', 'assets', 'theme', 'feed', 'sitemap',
    'robots', 'feed.xml', 'events.ics', 'sitemap.xml', 'robots.txt', 'favicon.ico',
];

/** The common fields every "full" content type has. */
function common_fields(array $visibility = VISIBILITIES, string $bodyLabel = 'Body'): array
{
    return [
        ['name' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => true, 'max' => 200],
        ['name' => 'slug', 'type' => 'slug', 'label' => 'URL slug', 'from' => 'title', 'help' => 'Leave blank to create it from the title.'],
        ['name' => 'summary', 'type' => 'textarea', 'label' => 'Summary', 'max' => 600, 'help' => 'Shown on cards and in search results.'],
        ['name' => 'body', 'type' => 'richtext', 'label' => $bodyLabel],
        ['name' => 'cover_media_id', 'type' => 'media', 'label' => 'Cover image', 'side' => true],
        ['name' => 'status', 'type' => 'select', 'label' => 'Status', 'options' => PUBLISH_STATUSES, 'default' => 'draft', 'side' => true],
        ['name' => 'publish_at', 'type' => 'datetime', 'label' => 'Publish date', 'side' => true, 'help' => 'Future date + Scheduled publishes it automatically.'],
        ['name' => 'visibility', 'type' => 'select', 'label' => 'Visibility', 'options' => $visibility, 'default' => 'public', 'side' => true],
        ['name' => 'seo_title', 'type' => 'text', 'label' => 'SEO title', 'max' => 200, 'seo' => true],
        ['name' => 'seo_description', 'type' => 'textarea', 'label' => 'SEO description', 'max' => 300, 'seo' => true],
    ];
}

function status_field(array $options = SIMPLE_STATUSES, string $default = 'published'): array
{
    return ['name' => 'status', 'type' => 'select', 'label' => 'Status', 'options' => $options, 'default' => $default, 'side' => true];
}

function sort_field(): array
{
    return ['name' => 'sort', 'type' => 'number', 'label' => 'Order', 'default' => 0, 'min' => -9999, 'max' => 9999, 'side' => true, 'help' => 'Lower numbers come first.'];
}

/** @return array<string,array> keyed by admin URL key */
function content_types(): array
{
    static $types = null;
    if ($types !== null) {
        return $types;
    }
    $types = [
        'posts' => [
            'table' => 'posts', 'item' => 'post', 'label' => 'Post', 'plural' => 'Posts', 'permission' => 'content',
            'title' => 'title', 'order' => 'COALESCE(publish_at, created_at) DESC, id DESC', 'has_author' => true,
            'fields' => array_merge(
                array_slice(common_fields(), 0, 4),
                [
                    ['name' => 'category_id', 'type' => 'select', 'label' => 'Category', 'options' => 'category_options', 'required' => true, 'side' => true, 'int' => true],
                    ['name' => 'tags', 'type' => 'tags', 'label' => 'Tags', 'virtual' => true, 'side' => true, 'help' => 'Comma separated.'],
                ],
                array_slice(common_fields(), 4),
                [
                    ['name' => 'allow_likes', 'type' => 'checkbox', 'label' => 'Allow likes', 'default' => 1, 'side' => true],
                    ['name' => 'allow_comments', 'type' => 'checkbox', 'label' => 'Allow comments', 'default' => 1, 'side' => true],
                ]
            ),
            'columns' => ['category_id' => 'Category', 'status' => 'Status', 'publish_at' => 'Date'],
            'filters' => ['status' => PUBLISH_STATUSES, 'category_id' => 'category_options'],
            'after_save' => 'post_save_tags',
            'load' => 'post_load_extra',
        ],
        'events' => [
            'table' => 'events', 'item' => 'event', 'label' => 'Event', 'plural' => 'Events', 'permission' => 'events',
            'title' => 'title', 'order' => 'start_at DESC', 'has_author' => true,
            'fields' => array_merge(
                array_slice(common_fields(), 0, 4),
                [
                    ['name' => 'start_at', 'type' => 'datetime', 'label' => 'Starts', 'required' => true, 'tz_field' => 'timezone'],
                    ['name' => 'end_at', 'type' => 'datetime', 'label' => 'Ends', 'tz_field' => 'timezone'],
                    ['name' => 'timezone', 'type' => 'select', 'label' => 'Time zone', 'options' => 'timezone_options', 'default_fn' => 'site_timezone_name'],
                    ['name' => 'venue_name', 'type' => 'text', 'label' => 'Venue name', 'max' => 200],
                    ['name' => 'venue_address', 'type' => 'text', 'label' => 'Venue address', 'max' => 300],
                    ['name' => 'map_url', 'type' => 'url', 'label' => 'Map link', 'max' => 500],
                    ['name' => 'online_url', 'type' => 'url', 'label' => 'Online link', 'max' => 500],
                    ['name' => 'registration_open', 'type' => 'checkbox', 'label' => 'Registration open', 'default' => 0],
                    ['name' => 'guest_registration', 'type' => 'checkbox', 'label' => 'Guests (non-members) can register', 'default' => 1],
                    ['name' => 'capacity', 'type' => 'number', 'label' => 'Capacity', 'min' => 1, 'max' => 1000000, 'nullable' => true, 'help' => 'Leave blank for unlimited.'],
                    ['name' => 'registration_deadline', 'type' => 'datetime', 'label' => 'Registration deadline', 'tz_field' => 'timezone'],
                    ['name' => 'tickets', 'type' => 'tickets', 'label' => 'Ticket options', 'virtual' => true, 'help' => 'Price labels are shown only; there is no online payment.'],
                ],
                array_slice(common_fields(), 4)
            ),
            'columns' => ['start_at' => 'Starts', 'status' => 'Status', 'registrations' => 'Registrations'],
            'filters' => ['status' => PUBLISH_STATUSES, 'when' => ['upcoming' => 'Upcoming', 'past' => 'Past']],
            'validate' => 'event_validate',
            'after_save' => 'event_save_tickets',
            'load' => 'event_load_extra',
        ],
        'albums' => [
            'table' => 'albums', 'item' => 'album', 'label' => 'Album', 'plural' => 'Gallery albums', 'permission' => 'gallery_media',
            'title' => 'title', 'order' => 'COALESCE(album_date, DATE(created_at)) DESC, id DESC', 'has_author' => true,
            'fields' => array_merge(
                array_slice(common_fields(VISIBILITIES, 'Description'), 0, 4),
                [['name' => 'album_date', 'type' => 'date', 'label' => 'Album date', 'side' => true]],
                array_slice(common_fields(), 4)
            ),
            'columns' => ['album_date' => 'Date', 'photos' => 'Photos', 'status' => 'Status'],
            'filters' => ['status' => PUBLISH_STATUSES],
        ],
        'downloads' => [
            'table' => 'downloads', 'item' => 'download', 'label' => 'Download', 'plural' => 'Downloads', 'permission' => 'content',
            'title' => 'title', 'order' => 'COALESCE(publish_at, created_at) DESC, id DESC', 'has_author' => true,
            'fields' => array_merge(
                array_slice(common_fields(VISIBILITIES, 'Description'), 0, 4),
                [
                    ['name' => 'file_media_id', 'type' => 'file', 'label' => 'File', 'required' => true, 'kinds' => ['document', 'audio', 'video', 'image']],
                    ['name' => 'category', 'type' => 'text', 'label' => 'Category', 'max' => 80, 'side' => true, 'datalist' => 'download_categories'],
                ],
                array_slice(common_fields(), 4)
            ),
            'columns' => ['category' => 'Category', 'visibility' => 'Visibility', 'download_count' => 'Downloads', 'status' => 'Status'],
            'filters' => ['status' => PUBLISH_STATUSES, 'visibility' => VISIBILITIES],
        ],
        'leaders' => [
            'table' => 'leaders', 'item' => 'leader', 'label' => 'Leader', 'plural' => 'Leaders', 'permission' => 'content',
            'title' => 'name', 'order' => 'group_id, sort, name',
            'fields' => [
                ['name' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true, 'max' => 160],
                ['name' => 'slug', 'type' => 'slug', 'label' => 'URL slug', 'from' => 'name'],
                ['name' => 'role_title', 'type' => 'text', 'label' => 'Role title', 'max' => 160, 'help' => 'e.g. Chairperson, Treasurer'],
                ['name' => 'group_id', 'type' => 'select', 'label' => 'Group', 'options' => 'leader_group_options', 'required' => true, 'int' => true],
                ['name' => 'bio', 'type' => 'richtext', 'label' => 'Biography'],
                ['name' => 'email', 'type' => 'email', 'label' => 'Email'],
                ['name' => 'phone', 'type' => 'text', 'label' => 'Phone', 'max' => 40],
                ['name' => 'social', 'type' => 'social', 'label' => 'Social links', 'virtual' => true],
                ['name' => 'term_start', 'type' => 'date', 'label' => 'Term start'],
                ['name' => 'term_end', 'type' => 'date', 'label' => 'Term end', 'help' => 'Leaders whose term has ended move to the past leaders archive.'],
                ['name' => 'photo_media_id', 'type' => 'media', 'label' => 'Photo', 'side' => true],
                ['name' => 'show_bio', 'type' => 'checkbox', 'label' => 'Show biography', 'default' => 1, 'side' => true],
                ['name' => 'show_email', 'type' => 'checkbox', 'label' => 'Show email publicly', 'default' => 0, 'side' => true],
                ['name' => 'show_phone', 'type' => 'checkbox', 'label' => 'Show phone publicly', 'default' => 0, 'side' => true],
                status_field(),
                sort_field(),
            ],
            'columns' => ['role_title' => 'Role', 'group_id' => 'Group', 'term_end' => 'Term ends', 'status' => 'Status'],
            'filters' => ['group_id' => 'leader_group_options'],
            'validate' => 'leader_validate',
        ],
        'leader-groups' => [
            'table' => 'leader_groups', 'item' => 'leader_group', 'label' => 'Leader group', 'plural' => 'Leader groups', 'permission' => 'content',
            'title' => 'name', 'order' => 'sort, name',
            'fields' => [
                ['name' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true, 'max' => 120, 'help' => 'e.g. Executive committee, Elders, Trustees'],
                ['name' => 'slug', 'type' => 'slug', 'label' => 'URL slug', 'from' => 'name'],
                ['name' => 'description', 'type' => 'textarea', 'label' => 'Description', 'max' => 1000],
                sort_field(),
            ],
            'columns' => ['slug' => 'URL', 'sort' => 'Order'],
            'filters' => [],
        ],
        'awards' => [
            'table' => 'awards', 'item' => 'award', 'label' => 'Award', 'plural' => 'Awards', 'permission' => 'content',
            'title' => 'recipient', 'order' => 'year DESC, sort, id',
            'fields' => [
                ['name' => 'recipient', 'type' => 'text', 'label' => 'Recipient name', 'required' => true, 'max' => 160],
                ['name' => 'title', 'type' => 'text', 'label' => 'Award', 'required' => true, 'max' => 200, 'help' => 'e.g. Volunteer of the Year'],
                ['name' => 'year', 'type' => 'number', 'label' => 'Year', 'required' => true, 'min' => 1900, 'max' => 2200, 'default_fn' => 'current_year'],
                ['name' => 'citation', 'type' => 'richtext', 'label' => 'Citation'],
                ['name' => 'photo_media_id', 'type' => 'media', 'label' => 'Photo', 'side' => true],
                status_field(),
                sort_field(),
            ],
            'columns' => ['title' => 'Award', 'year' => 'Year', 'status' => 'Status'],
            'filters' => ['year' => 'award_year_options'],
        ],
        'faqs' => [
            'table' => 'faqs', 'item' => 'faq', 'label' => 'FAQ', 'plural' => 'FAQs', 'permission' => 'content',
            'title' => 'question', 'order' => 'category, sort, id',
            'fields' => [
                ['name' => 'question', 'type' => 'text', 'label' => 'Question', 'required' => true, 'max' => 300],
                ['name' => 'answer', 'type' => 'richtext', 'label' => 'Answer', 'required' => true],
                ['name' => 'category', 'type' => 'text', 'label' => 'Category', 'max' => 80, 'side' => true, 'datalist' => 'faq_categories'],
                ['name' => 'visibility', 'type' => 'select', 'label' => 'Visibility', 'options' => VISIBILITIES, 'default' => 'public', 'side' => true],
                status_field(),
                sort_field(),
            ],
            'columns' => ['category' => 'Category', 'status' => 'Status'],
            'filters' => ['category' => 'faq_category_options'],
        ],
        'branches' => [
            'table' => 'branches', 'item' => 'branch', 'label' => 'Branch', 'plural' => 'Branches', 'permission' => 'content',
            'title' => 'name', 'order' => 'sort, name',
            'fields' => [
                ['name' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true, 'max' => 160],
                ['name' => 'slug', 'type' => 'slug', 'label' => 'URL slug', 'from' => 'name'],
                ['name' => 'region', 'type' => 'text', 'label' => 'City / region', 'max' => 120],
                ['name' => 'contact_person', 'type' => 'text', 'label' => 'Contact person', 'max' => 160],
                ['name' => 'email', 'type' => 'email', 'label' => 'Email'],
                ['name' => 'phone', 'type' => 'text', 'label' => 'Phone', 'max' => 40],
                ['name' => 'meeting_venue', 'type' => 'text', 'label' => 'Meeting venue', 'max' => 300],
                ['name' => 'description', 'type' => 'richtext', 'label' => 'Description'],
                status_field(),
                sort_field(),
            ],
            'columns' => ['region' => 'Region', 'contact_person' => 'Contact', 'status' => 'Status'],
            'filters' => [],
        ],
        'groups' => [
            'table' => 'groups_orgs', 'item' => 'group', 'label' => 'Group', 'plural' => 'Groups', 'permission' => 'members',
            'title' => 'name', 'order' => 'sort, name',
            'fields' => [
                ['name' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true, 'max' => 200],
                ['name' => 'slug', 'type' => 'slug', 'label' => 'URL slug', 'from' => 'name'],
                ['name' => 'group_number', 'type' => 'text', 'label' => 'Group ID', 'max' => 40, 'help' => 'Assigned automatically when a group signs up; can be set by hand.'],
                ['name' => 'contact_person', 'type' => 'text', 'label' => 'Contact person', 'max' => 160],
                ['name' => 'email', 'type' => 'email', 'label' => 'Email'],
                ['name' => 'phone', 'type' => 'text', 'label' => 'Phone', 'max' => 40],
                ['name' => 'address', 'type' => 'text', 'label' => 'Address', 'max' => 300],
                ['name' => 'country', 'type' => 'text', 'label' => 'Country', 'max' => 80],
                ['name' => 'website', 'type' => 'url', 'label' => 'Website', 'max' => 500],
                ['name' => 'description', 'type' => 'richtext', 'label' => 'Description'],
                ['name' => 'logo_media_id', 'type' => 'media', 'label' => 'Logo', 'side' => true],
                status_field(['active' => 'Active (listed)', 'hidden' => 'Hidden', 'suspended' => 'Suspended'], 'active'),
                sort_field(),
            ],
            'columns' => ['group_number' => 'Group ID', 'contact_person' => 'Contact', 'status' => 'Status'],
            'filters' => ['status' => ['active' => 'Active', 'hidden' => 'Hidden', 'suspended' => 'Suspended']],
            'after_save' => 'group_after_save',
        ],
        'categories' => [
            'table' => 'categories', 'item' => 'category', 'label' => 'Category', 'plural' => 'Post categories', 'permission' => 'content',
            'title' => 'name', 'order' => 'base, sort, name',
            'fields' => [
                ['name' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true, 'max' => 80],
                ['name' => 'slug', 'type' => 'slug', 'label' => 'URL slug', 'from' => 'name'],
                ['name' => 'base', 'type' => 'select', 'label' => 'Shown in', 'options' => ['news' => 'News', 'blog' => 'Blog', 'projects' => 'Projects'], 'default' => 'news', 'help' => 'Posts in this category appear in this list and use its URL.'],
                sort_field(),
            ],
            'columns' => ['base' => 'Shown in', 'posts' => 'Posts'],
            'filters' => [],
            'before_delete' => 'category_before_delete',
            'validate' => 'category_validate',
        ],
        'pages' => [
            'table' => 'pages', 'item' => 'page', 'label' => 'Page', 'plural' => 'Pages', 'permission' => 'pages_menus',
            'title' => 'title', 'order' => 'title', 'has_author' => true,
            'fields' => array_merge(
                array_slice(common_fields(['public' => 'Everyone', 'members' => 'Members only', 'hidden' => 'Hidden (staff only)']), 0, 4),
                array_slice(common_fields(['public' => 'Everyone', 'members' => 'Members only', 'hidden' => 'Hidden (staff only)']), 4),
                [['name' => 'is_home', 'type' => 'checkbox', 'label' => 'Use as the home page', 'virtual' => true, 'side' => true]]
            ),
            'columns' => ['slug' => 'URL', 'visibility' => 'Visibility', 'status' => 'Status'],
            'filters' => ['status' => PUBLISH_STATUSES],
            'validate' => 'page_validate',
            'after_save' => 'page_after_save',
            'load' => 'page_load_extra',
        ],
    ];
    foreach ($types as $key => &$t) {
        $t['key'] = $key;
        $t += ['has_author' => false, 'filters' => [], 'columns' => []];
    }
    unset($t);
    return $types;
}

function content_type(string $key): array
{
    $types = content_types();
    if (!isset($types[$key])) {
        abort(404);
    }
    return $types[$key];
}

/** Find a type by its item key (post, event, ...). */
function content_type_by_item(string $item): ?array
{
    foreach (content_types() as $t) {
        if ($t['item'] === $item) {
            return $t;
        }
    }
    return null;
}

function content_field(array $type, string $name): ?array
{
    foreach ($type['fields'] as $f) {
        if ($f['name'] === $name) {
            return $f;
        }
    }
    return null;
}

/** Resolve a field's options (array or the name of an option function). */
function field_options(array $field): array
{
    $o = $field['options'] ?? [];
    if (is_string($o)) {
        return in_array($o, ['category_options', 'leader_group_options', 'timezone_options', 'award_year_options', 'faq_category_options'], true) ? $o() : [];
    }
    return $o;
}

function field_default(array $field): mixed
{
    if (isset($field['default_fn']) && in_array($field['default_fn'], ['site_timezone_name', 'current_year'], true)) {
        return ($field['default_fn'])();
    }
    return $field['default'] ?? '';
}

// ---- Option providers -------------------------------------------------------

function category_options(): array
{
    $out = [];
    foreach (q_all('SELECT id, name, base FROM categories ORDER BY base, sort, name') as $r) {
        $out[(string) $r['id']] = $r['name'] . ($r['name'] !== ucfirst($r['base']) ? ' (' . ucfirst($r['base']) . ')' : '');
    }
    return $out;
}

function leader_group_options(): array
{
    $out = [];
    foreach (q_all('SELECT id, name FROM leader_groups ORDER BY sort, name') as $r) {
        $out[(string) $r['id']] = $r['name'];
    }
    return $out;
}

function timezone_options(): array
{
    $ids = DateTimeZone::listIdentifiers();
    return array_combine($ids, $ids);
}

function site_timezone_name(): string
{
    return site_tz()->getName();
}

function current_year(): int
{
    return (int) (new DateTimeImmutable('now', site_tz()))->format('Y');
}

function award_year_options(): array
{
    $out = [];
    foreach (q_all('SELECT DISTINCT year FROM awards ORDER BY year DESC') as $r) {
        $out[(string) $r['year']] = (string) $r['year'];
    }
    return $out;
}

function faq_category_options(): array
{
    $out = [];
    foreach (q_all("SELECT DISTINCT category FROM faqs WHERE category <> '' ORDER BY category") as $r) {
        $out[$r['category']] = $r['category'];
    }
    return $out;
}

function download_categories(): array
{
    return array_column(q_all("SELECT DISTINCT category FROM downloads WHERE category <> '' ORDER BY category"), 'category');
}

function faq_categories(): array
{
    return array_keys(faq_category_options());
}

// ---- Publishing rules -------------------------------------------------------

/**
 * Normalise status + publish date:
 * - published with no date => now; published with a future date => scheduled
 * - scheduled with no date => error; scheduled with a past date => published
 * @return array{0:string,1:?string,2:?string} [status, publish_at, error]
 */
function normalise_publish(string $status, ?string $publishAt, ?string $now = null): array
{
    $now ??= utc_now();
    if ($status === 'published') {
        if ($publishAt === null) {
            return ['published', $now, null];
        }
        return [$publishAt > $now ? 'scheduled' : 'published', $publishAt, null];
    }
    if ($status === 'scheduled') {
        if ($publishAt === null) {
            return ['scheduled', null, 'Choose the date and time to publish.'];
        }
        return [$publishAt > $now ? 'scheduled' : 'published', $publishAt, null];
    }
    return ['draft', $publishAt, null];
}

/** SQL condition for "visible to the public now" (scheduled items whose time has passed count too). */
function published_sql(string $alias = ''): string
{
    $p = $alias !== '' ? $alias . '.' : '';
    return "({$p}status IN ('published','scheduled') AND ({$p}publish_at IS NULL OR {$p}publish_at <= UTC_TIMESTAMP()))";
}

/** Visibility condition for the current viewer. */
function visibility_sql(string $alias = '', ?array $viewer = null, bool $hasVisibility = true): string
{
    if (!$hasVisibility || viewer_is_member($viewer)) {
        return '1=1';
    }
    $p = $alias !== '' ? $alias . '.' : '';
    return "{$p}visibility = 'public'";
}

/** Flip scheduled items whose time has come to published (cron and lazy check, §12). */
function publish_due(): int
{
    $n = 0;
    foreach (['posts', 'events', 'albums', 'downloads', 'pages', 'leaders', 'awards', 'faqs', 'branches'] as $table) {
        $n += q("UPDATE " . db_ident($table) . " SET status = 'published' WHERE status = 'scheduled' AND publish_at <= UTC_TIMESTAMP()")->rowCount();
    }
    return $n;
}

/** Run publish_due() at most once a minute from page views, in case cron is down. */
function publish_due_lazily(): void
{
    $marker = BASE . '/storage/cache/publish-check';
    if (is_file($marker) && filemtime($marker) > time() - 60) {
        return;
    }
    touch($marker);
    publish_due();
}

// ---- Validation -------------------------------------------------------------

/**
 * Validate input for a content type.
 * @return array{0:array<string,mixed>,1:array<string,string>} [column data, errors]
 */
function content_validate(array $type, array $input, ?array $existing = null): array
{
    $data = [];
    $errors = [];
    $titleField = $type['title'];
    foreach ($type['fields'] as $f) {
        $name = $f['name'];
        if (!empty($f['virtual'])) {
            continue;
        }
        $raw = $input[$name] ?? null;
        $raw = is_string($raw) ? trim($raw) : (is_int($raw) ? (string) $raw : null);
        $label = $f['label'];
        $required = !empty($f['required']);
        switch ($f['type']) {
            case 'text':
            case 'textarea':
                $v = (string) $raw;
                if ($required && $v === '') {
                    $errors[$name] = "$label is required.";
                } elseif (isset($f['max']) && mb_strlen($v) > $f['max']) {
                    $errors[$name] = "$label must be at most {$f['max']} characters.";
                }
                $data[$name] = $v === '' && !$required && in_array($name, ['seo_title', 'seo_description', 'summary', 'email', 'phone', 'meeting_venue', 'address', 'country', 'group_number', 'venue_name', 'venue_address', 'description'], true) ? null : $v;
                break;
            case 'richtext':
                $v = Sanitizer::clean((string) $raw);
                if ($required && trim(strip_tags($v)) === '') {
                    $errors[$name] = "$label is required.";
                }
                $data[$name] = $v === '' ? null : $v;
                break;
            case 'slug':
                $v = slugify((string) $raw);
                if ($v === '') {
                    $v = slugify((string) ($input[$f['from'] ?? $titleField] ?? ''));
                }
                if ($v === '') {
                    $v = 'item';
                }
                $data[$name] = $v;
                break;
            case 'email':
                $v = strtolower((string) $raw);
                if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                    $errors[$name] = 'Enter a valid email address.';
                } elseif ($required && $v === '') {
                    $errors[$name] = "$label is required.";
                }
                $data[$name] = $v === '' ? null : $v;
                break;
            case 'url':
                $v = (string) $raw;
                if ($v !== '' && !preg_match('#^https?://[^\s"<>]+$#i', $v)) {
                    $errors[$name] = 'Enter a full web address starting with https://';
                } elseif (isset($f['max']) && mb_strlen($v) > $f['max']) {
                    $errors[$name] = "$label is too long.";
                }
                $data[$name] = $v === '' ? null : $v;
                break;
            case 'number':
                $v = (string) $raw;
                if ($v === '') {
                    if ($required) {
                        $errors[$name] = "$label is required.";
                    }
                    $data[$name] = !empty($f['nullable']) ? null : (int) field_default($f);
                    break;
                }
                if (!preg_match('/^-?\d{1,9}$/', $v)) {
                    $errors[$name] = "$label must be a whole number.";
                    break;
                }
                $n = (int) $v;
                if ((isset($f['min']) && $n < $f['min']) || (isset($f['max']) && $n > $f['max'])) {
                    $errors[$name] = "$label must be between {$f['min']} and {$f['max']}.";
                }
                $data[$name] = $n;
                break;
            case 'checkbox':
                $data[$name] = $raw === '1' ? 1 : 0;
                break;
            case 'select':
                $options = field_options($f);
                $v = (string) ($raw ?? '');
                if ($v === '' && !$required && isset($f['default'])) {
                    $v = (string) $f['default'];
                }
                if ($v === '' && isset($f['default_fn'])) {
                    $v = (string) field_default($f);
                }
                if (!array_key_exists($v, $options)) {
                    $errors[$name] = $v === '' ? "Choose a $label." : "Choose a valid $label.";
                    break;
                }
                $data[$name] = !empty($f['int']) ? (int) $v : $v;
                break;
            case 'date':
                $v = (string) $raw;
                if ($v === '') {
                    if ($required) {
                        $errors[$name] = "$label is required.";
                    }
                    $data[$name] = null;
                    break;
                }
                $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
                if ($d === false || $d->format('Y-m-d') !== $v) {
                    $errors[$name] = "$label must be a valid date.";
                    break;
                }
                $data[$name] = $v;
                break;
            case 'datetime':
                $v = (string) $raw;
                $tz = site_tz();
                if (isset($f['tz_field'])) {
                    $tzName = (string) ($input[$f['tz_field']] ?? '');
                    if (in_array($tzName, DateTimeZone::listIdentifiers(), true)) {
                        $tz = new DateTimeZone($tzName);
                    }
                }
                if ($v === '') {
                    if ($required) {
                        $errors[$name] = "$label is required.";
                    }
                    $data[$name] = null;
                    break;
                }
                $utcValue = local_to_utc($v, $tz);
                if ($utcValue === null) {
                    $gap = local_time_problem($v, $tz);
                    $errors[$name] = $gap !== null && str_starts_with($gap, 'There is no') ? "$label: $gap" : "$label must be a valid date and time.";
                    break;
                }
                $data[$name] = $utcValue;
                break;
            case 'media':
            case 'file':
                $id = (int) ($raw ?? 0);
                if ($id > 0) {
                    $m = media_find($id);
                    $kinds = $f['type'] === 'media' ? ['image'] : ($f['kinds'] ?? ['document']);
                    // Member photos and other private files can never be attached (they would be
                    // served through the item), and the editor must be able to open the file.
                    $editor = current_user();
                    if ($m === null || !in_array($m['kind'], $kinds, true) || !media_attachable($m) || ($editor !== null && !media_can_view($m, $editor))) {
                        $errors[$name] = "Choose a valid file for $label.";
                        break;
                    }
                    $data[$name] = $id;
                } else {
                    if ($required) {
                        $errors[$name] = "$label is required.";
                    }
                    $data[$name] = null;
                }
                break;
        }
    }

    // Publishing rules for types with status + publish_at.
    if (isset($data['status'], $type['fields']) && content_field($type, 'publish_at') !== null && isset(PUBLISH_STATUSES[$data['status']])) {
        [$status, $publishAt, $err] = normalise_publish((string) $data['status'], $data['publish_at'] ?? null);
        $data['status'] = $status;
        $data['publish_at'] = $publishAt;
        if ($err !== null) {
            $errors['publish_at'] = $err;
        }
    }

    // Unique slug (auto-suffixed).
    if (isset($data['slug'])) {
        $data['slug'] = unique_slug($type['table'], $data['slug'], isset($existing['id']) ? (int) $existing['id'] : null);
    }

    $hook = $type['validate'] ?? null;
    if (is_string($hook) && in_array($hook, ['event_validate', 'leader_validate', 'page_validate', 'category_validate'], true)) {
        $data = $hook($data, $input, $errors, $existing);
    }
    return [$data, $errors];
}

function event_validate(array $data, array $input, array &$errors, ?array $existing): array
{
    if (!empty($data['start_at']) && !empty($data['end_at']) && $data['end_at'] < $data['start_at']) {
        $errors['end_at'] = 'The end must be after the start.';
    }
    if (!empty($data['registration_deadline']) && !empty($data['start_at'])) {
        $limit = $data['end_at'] ?? $data['start_at'];
        if ($data['registration_deadline'] > $limit) {
            $errors['registration_deadline'] = 'The deadline must be before the event ends.';
        }
    }
    foreach (event_tickets_from_input($input) as $i => $t) {
        if (mb_strlen($t['name']) > 120 || mb_strlen($t['price_label']) > 60) {
            $errors['tickets'] = 'Ticket names are limited to 120 characters and prices to 60.';
        }
    }
    return $data;
}

function leader_validate(array $data, array $input, array &$errors, ?array $existing): array
{
    if (!empty($data['term_start']) && !empty($data['term_end']) && $data['term_end'] < $data['term_start']) {
        $errors['term_end'] = 'The term must end after it starts.';
    }
    $social = [];
    foreach (leader_social_networks() as $key => $label) {
        $v = trim((string) ($input['social_' . $key] ?? ''));
        if ($v === '') {
            continue;
        }
        if (!preg_match('#^https://[^\s"<>]+$#i', $v) || mb_strlen($v) > 300) {
            $errors['social'] = "$label must be a full https:// link.";
            continue;
        }
        $social[$key] = $v;
    }
    $data['social'] = $social ? json_encode($social, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : null;
    return $data;
}

function leader_social_networks(): array
{
    return ['website' => 'Website', 'linkedin' => 'LinkedIn', 'x' => 'X', 'facebook' => 'Facebook', 'instagram' => 'Instagram'];
}

function page_validate(array $data, array $input, array &$errors, ?array $existing): array
{
    if (isset($data['slug']) && in_array($data['slug'], RESERVED_SLUGS, true)) {
        $errors['slug'] = 'This URL is used by a built-in page. Choose another slug.';
    }
    if ($existing !== null && $existing['sections'] !== null) {
        // Builder pages keep their search text (generated on publish) in body.
        unset($data['body']);
    }
    return $data;
}

function category_validate(array $data, array $input, array &$errors, ?array $existing): array
{
    if ($existing !== null && (int) $existing['is_base'] === 1) {
        // Built-in categories keep their slug and list so their URLs never break.
        $data['slug'] = $existing['slug'];
        $data['base'] = $existing['base'];
    }
    return $data;
}

// ---- Persistence ------------------------------------------------------------

function content_find(array $type, int $id): ?array
{
    $row = q_one('SELECT * FROM ' . db_ident($type['table']) . ' WHERE id = ?', [$id]);
    if ($row !== null && isset($type['load']) && in_array($type['load'], ['post_load_extra', 'event_load_extra', 'page_load_extra'], true)) {
        $row = ($type['load'])($row);
    }
    return $row;
}

/** Media columns of a content type (cover, file, photo, logo). */
function content_media_columns(array $type): array
{
    return array_values(array_map(static fn (array $f): string => $f['name'], array_filter($type['fields'], static fn (array $f): bool => in_array($f['type'], ['media', 'file'], true))));
}

/** Media ids an item uses (its media columns, plus the photos of an album). */
function content_media_ids(array $type, int $id): array
{
    $cols = content_media_columns($type);
    $ids = [];
    if ($cols !== []) {
        $row = q_one('SELECT ' . implode(', ', array_map('db_ident', $cols)) . ' FROM ' . db_ident($type['table']) . ' WHERE id = ?', [$id]) ?? [];
        $ids = array_values(array_filter(array_map('intval', $row)));
    }
    if ($type['table'] === 'albums') {
        $ids = array_merge($ids, array_map('intval', array_column(q_all('SELECT media_id FROM album_photos WHERE album_id = ?', [$id]), 'media_id')));
    }
    return $ids;
}

/**
 * Insert or update; runs the type's after_save hook in the same transaction.
 * Afterwards the item's media (before and after the change) get the
 * visibility their uses need, so a members-only item never has public files.
 */
function content_save(array $type, array $data, array $input, ?int $id = null, ?int $userId = null): int
{
    $before = $id !== null ? content_media_ids($type, $id) : [];
    $id = content_save_row($type, $data, $input, $id, $userId);
    media_sync_visibility(array_merge($before, content_media_ids($type, $id)));
    return $id;
}

function content_save_row(array $type, array $data, array $input, ?int $id, ?int $userId): int
{
    return db_tx(static function () use ($type, $data, $input, $id, $userId): int {
        if ($id === null) {
            if ($type['has_author']) {
                $data['author_id'] = $userId;
            }
            if ($type['table'] === 'pages') {
                $data['updated_by'] = $userId;
            }
            $id = db_insert($type['table'], $data);
        } else {
            if ($type['table'] === 'pages') {
                $data['updated_by'] = $userId;
                q('UPDATE pages SET revision = revision + 1 WHERE id = ?', [$id]);
            }
            db_update($type['table'], $id, $data);
        }
        $hook = $type['after_save'] ?? null;
        if (is_string($hook) && in_array($hook, ['post_save_tags', 'event_save_tickets', 'page_after_save', 'group_after_save'], true)) {
            $hook($id, $input);
        }
        return $id;
    });
}

/** Delete an item and flag menu links to it (§3.2). */
function content_delete(array $type, int $id): void
{
    $hook = $type['before_delete'] ?? null;
    if (is_string($hook) && $hook === 'category_before_delete') {
        $hook($id);
    }
    db_tx(static function () use ($type, $id): void {
        q('DELETE FROM ' . db_ident($type['table']) . ' WHERE id = ?', [$id]);
        menu_flag_broken($type['item'] === 'page' ? 'page' : 'item', $type['item'], $id);
        if ($type['item'] === 'page' && (int) setting('home.page_id', 0) === $id) {
            setting_set('home.page_id', null);
        }
    });
}

function category_before_delete(int $id): void
{
    $c = q_one('SELECT is_base FROM categories WHERE id = ?', [$id]);
    if ($c !== null && (int) $c['is_base'] === 1) {
        throw new DomainException('Built-in categories cannot be deleted.');
    }
    if ((int) q_val('SELECT COUNT(*) FROM posts WHERE category_id = ?', [$id]) > 0) {
        throw new DomainException('Move the posts in this category first.');
    }
}

/**
 * Admin list with search, filters and paging.
 * @return array{rows:list<array>, total:int}
 */
function content_admin_list(array $type, array $filters, int $page = 1, int $per = 25): array
{
    $where = ['1=1'];
    $params = [];
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = db_ident($type['title']) . ' LIKE ?';
        $params[] = '%' . addcslashes($q, '%_\\') . '%';
    }
    foreach ($type['filters'] as $key => $options) {
        $v = (string) ($filters[$key] ?? '');
        if ($v === '') {
            continue;
        }
        if ($key === 'when') {
            $where[] = $v === 'past' ? 'start_at < UTC_TIMESTAMP()' : 'start_at >= UTC_TIMESTAMP()';
            continue;
        }
        $opts = is_string($options) ? field_options(['options' => $options]) : $options;
        if (array_key_exists($v, $opts)) {
            $where[] = db_ident($key) . ' = ?';
            $params[] = $v;
        }
    }
    $sqlWhere = implode(' AND ', $where);
    $total = (int) q_val('SELECT COUNT(*) FROM ' . db_ident($type['table']) . ' WHERE ' . $sqlWhere, $params);
    $offset = max(0, ($page - 1) * $per);
    $rows = q_all(
        'SELECT * FROM ' . db_ident($type['table']) . ' WHERE ' . $sqlWhere . ' ORDER BY ' . $type['order'] . ' LIMIT ' . (int) $per . ' OFFSET ' . (int) $offset,
        $params
    );
    return ['rows' => $rows, 'total' => $total];
}

// ---- Posts ------------------------------------------------------------------

/** @return list<string> unique tag names from "a, b, c" */
function parse_tags(string $input): array
{
    $out = [];
    foreach (explode(',', $input) as $t) {
        $t = trim(preg_replace('/\s+/u', ' ', $t) ?? '');
        if ($t !== '' && mb_strlen($t) <= 60 && slugify($t) !== '' && !isset($out[slugify($t)])) {
            $out[slugify($t)] = $t;
        }
    }
    return array_values(array_slice($out, 0, 20, true));
}

function post_save_tags(int $postId, array $input): void
{
    q('DELETE FROM post_tags WHERE post_id = ?', [$postId]);
    foreach (parse_tags((string) ($input['tags'] ?? '')) as $name) {
        $slug = slugify($name);
        $tagId = q_val('SELECT id FROM tags WHERE slug = ?', [$slug]);
        if ($tagId === null) {
            $tagId = db_insert('tags', ['name' => $name, 'slug' => $slug]);
        }
        q('INSERT IGNORE INTO post_tags (post_id, tag_id) VALUES (?, ?)', [$postId, (int) $tagId]);
    }
    q('DELETE t FROM tags t LEFT JOIN post_tags pt ON pt.tag_id = t.id WHERE pt.tag_id IS NULL');
}

function post_load_extra(array $row): array
{
    $row['tag_rows'] = q_all('SELECT t.name, t.slug FROM tags t JOIN post_tags pt ON pt.tag_id = t.id WHERE pt.post_id = ? ORDER BY t.name', [$row['id']]);
    $row['tags'] = implode(', ', array_column($row['tag_rows'], 'name'));
    $cat = q_one('SELECT name, slug, base FROM categories WHERE id = ?', [$row['category_id']]);
    $row['category_name'] = $cat['name'] ?? '';
    $row['category_slug'] = $cat['slug'] ?? '';
    $row['base'] = $cat['base'] ?? 'news';
    return $row;
}

/**
 * Date range (UTC) for the post archive filters (F5): today, this month,
 * this year, older (before this year). Boundaries follow the site timezone.
 * @return array{from:?string, to:?string}|null null for an unknown filter
 */
function archive_range(string $filter, DateTimeImmutable $now, DateTimeZone $tz): ?array
{
    $local = $now->setTimezone($tz);
    $toUtc = static fn (DateTimeImmutable $d): string => $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $startOfDay = $local->setTime(0, 0);
    $startOfMonth = $local->modify('first day of this month')->setTime(0, 0);
    $startOfYear = $local->setDate((int) $local->format('Y'), 1, 1)->setTime(0, 0);
    return match ($filter) {
        'today' => ['from' => $toUtc($startOfDay), 'to' => $toUtc($startOfDay->modify('+1 day'))],
        'month' => ['from' => $toUtc($startOfMonth), 'to' => $toUtc($startOfMonth->modify('+1 month'))],
        'year' => ['from' => $toUtc($startOfYear), 'to' => $toUtc($startOfYear->modify('+1 year'))],
        'older' => ['from' => null, 'to' => $toUtc($startOfYear)],
        default => null,
    };
}

const ARCHIVE_FILTERS = ['today' => 'Today', 'month' => 'This month', 'year' => 'This year', 'older' => 'Older'];

/**
 * Public post list for one base (news/blog/projects) with filters.
 * @param array{when?:string, category?:string, tag?:string} $filters
 * @return array{rows:list<array>, total:int}
 */
function posts_public(string $base, array $filters, int $limit, int $offset, ?array $viewer = null, ?DateTimeImmutable $now = null): array
{
    $where = ['c.base = ?', published_sql('p'), visibility_sql('p', $viewer)];
    $params = [$base];
    $range = archive_range((string) ($filters['when'] ?? ''), $now ?? new DateTimeImmutable('now', utc()), site_tz());
    if ($range !== null) {
        if ($range['from'] !== null) {
            $where[] = 'p.publish_at >= ?';
            $params[] = $range['from'];
        }
        if ($range['to'] !== null) {
            $where[] = 'p.publish_at < ?';
            $params[] = $range['to'];
        }
    }
    if (($filters['category'] ?? '') !== '') {
        $where[] = 'c.slug = ?';
        $params[] = $filters['category'];
    }
    $join = '';
    if (($filters['tag'] ?? '') !== '') {
        $join = ' JOIN post_tags pt ON pt.post_id = p.id JOIN tags t ON t.id = pt.tag_id AND t.slug = ?';
        array_unshift($params, $filters['tag']);
    }
    $sqlWhere = implode(' AND ', $where);
    $from = ' FROM posts p JOIN categories c ON c.id = p.category_id' . $join . ' WHERE ' . $sqlWhere;
    $total = (int) q_val('SELECT COUNT(*)' . $from, $params);
    $rows = q_all(
        'SELECT p.*, c.name AS category_name, c.slug AS category_slug, c.base' . $from
        . ' ORDER BY p.publish_at DESC, p.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
        $params
    );
    return ['rows' => $rows, 'total' => $total];
}

/** Latest published posts across all bases. */
function posts_latest(int $limit, ?array $viewer = null): array
{
    return q_all(
        'SELECT p.*, c.name AS category_name, c.slug AS category_slug, c.base FROM posts p JOIN categories c ON c.id = p.category_id
         WHERE ' . published_sql('p') . ' AND ' . visibility_sql('p', $viewer) . ' ORDER BY p.publish_at DESC, p.id DESC LIMIT ' . (int) $limit
    );
}

function post_url(array $post): string
{
    return '/' . ($post['base'] ?? 'news') . '/' . $post['slug'];
}

// ---- Events -----------------------------------------------------------------

/** @return list<array{name:string, price_label:string}> */
function event_tickets_from_input(array $input): array
{
    $names = is_array($input['ticket_name'] ?? null) ? $input['ticket_name'] : [];
    $prices = is_array($input['ticket_price'] ?? null) ? $input['ticket_price'] : [];
    $ids = is_array($input['ticket_id'] ?? null) ? $input['ticket_id'] : [];
    $out = [];
    foreach ($names as $i => $name) {
        $name = is_string($name) ? trim($name) : '';
        if ($name === '') {
            continue;
        }
        $out[] = [
            'id' => isset($ids[$i]) && is_string($ids[$i]) && ctype_digit($ids[$i]) ? (int) $ids[$i] : 0,
            'name' => $name,
            'price_label' => isset($prices[$i]) && is_string($prices[$i]) ? trim($prices[$i]) : '',
        ];
    }
    return array_slice($out, 0, 20);
}

/** Keep ticket ids stable (registrations reference them); delete removed ones. */
function event_save_tickets(int $eventId, array $input): void
{
    $keep = [];
    foreach (event_tickets_from_input($input) as $sort => $t) {
        $row = ['name' => mb_substr($t['name'], 0, 120), 'price_label' => mb_substr($t['price_label'], 0, 60), 'sort' => $sort];
        if ($t['id'] > 0 && q_val('SELECT id FROM event_tickets WHERE id = ? AND event_id = ?', [$t['id'], $eventId]) !== null) {
            db_update('event_tickets', $t['id'], $row);
            $keep[] = $t['id'];
        } else {
            $keep[] = db_insert('event_tickets', $row + ['event_id' => $eventId]);
        }
    }
    $params = array_merge([$eventId], $keep);
    q('DELETE FROM event_tickets WHERE event_id = ?' . ($keep ? ' AND id NOT IN (' . db_placeholders(count($keep)) . ')' : ''), $params);
}

function event_load_extra(array $row): array
{
    $row['ticket_rows'] = q_all('SELECT * FROM event_tickets WHERE event_id = ? ORDER BY sort, id', [$row['id']]);
    $row['registered_count'] = event_registered_count((int) $row['id']);
    return $row;
}

function event_registered_count(int $eventId): int
{
    return (int) q_val("SELECT COALESCE(SUM(quantity), 0) FROM event_registrations WHERE event_id = ? AND status <> 'cancelled'", [$eventId]);
}

function event_url(array $event): string
{
    return '/events/' . $event['slug'];
}

/**
 * Event list filter (F6): today / upcoming / past, in site time.
 * @return array{0:string,1:list<string>} [SQL condition, params]
 */
function event_when_sql(string $when, DateTimeImmutable $now, DateTimeZone $tz, string $alias = 'e'): array
{
    $p = $alias . '.';
    $nowUtc = $now->setTimezone(utc())->format('Y-m-d H:i:s');
    if ($when === 'today') {
        $r = archive_range('today', $now, $tz);
        return ["({$p}start_at < ? AND COALESCE({$p}end_at, {$p}start_at) >= ?)", [$r['to'], $r['from']]];
    }
    if ($when === 'past') {
        return ["COALESCE({$p}end_at, {$p}start_at) < ?", [$nowUtc]];
    }
    return ["COALESCE({$p}end_at, {$p}start_at) >= ?", [$nowUtc]];
}

/**
 * Can someone register now? Returns null when yes, or the reason why not.
 */
function event_registration_block(array $event, ?array $viewer, int $quantity = 1, ?string $now = null): ?string
{
    $now ??= utc_now();
    if ((int) $event['registration_open'] !== 1) {
        return 'Registration is not open for this event.';
    }
    if (event_end_at($event) < $now) {
        return 'This event has already taken place.';
    }
    if (!empty($event['registration_deadline']) && $event['registration_deadline'] < $now) {
        return 'Registration has closed.';
    }
    if ((int) $event['guest_registration'] !== 1 && !viewer_is_member($viewer)) {
        return 'Please log in as a member to register for this event.';
    }
    if ($event['capacity'] !== null) {
        $left = (int) $event['capacity'] - event_registered_count((int) $event['id']);
        if ($left <= 0) {
            return 'This event is full.';
        }
        if ($quantity > $left) {
            return 'Only ' . pluralise($left, 'place') . ' left.';
        }
    }
    return null;
}

function event_end_at(array $event): string
{
    return (string) ($event['end_at'] ?: $event['start_at']);
}

/**
 * Register for an event. Returns [registration id, errors].
 * @return array{0:?int,1:array<string,string>}
 */
function event_register(array $event, array $input, ?array $viewer): array
{
    $errors = [];
    $name = trim((string) ($input['name'] ?? ''));
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $phone = trim((string) ($input['phone'] ?? ''));
    $qty = (string) ($input['quantity'] ?? '1');
    $ticketId = (string) ($input['ticket_id'] ?? '');
    if ($name === '' || mb_strlen($name) > 120) {
        $errors['name'] = 'Enter your name.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if (mb_strlen($phone) > 40) {
        $errors['phone'] = 'Phone number is too long.';
    }
    if (!preg_match('/^\d{1,2}$/', $qty) || (int) $qty < 1 || (int) $qty > 10) {
        $errors['quantity'] = 'Choose between 1 and 10 places.';
    }
    $tickets = q_all('SELECT id FROM event_tickets WHERE event_id = ?', [$event['id']]);
    $ticket = null;
    if ($tickets !== []) {
        if (!in_array((int) $ticketId, array_map('intval', array_column($tickets, 'id')), true)) {
            $errors['ticket_id'] = 'Choose a ticket option.';
        } else {
            $ticket = (int) $ticketId;
        }
    }
    if ($errors) {
        return [null, $errors];
    }
    return db_tx(static function () use ($event, $viewer, $name, $email, $phone, $qty, $ticket): array {
        // Lock the event row so two people cannot take the last place at once.
        q('SELECT id FROM events WHERE id = ? FOR UPDATE', [$event['id']]);
        $block = event_registration_block($event, $viewer, (int) $qty);
        if ($block !== null) {
            return [null, ['form' => $block]];
        }
        $id = db_insert('event_registrations', [
            'event_id' => $event['id'],
            'ticket_id' => $ticket,
            'user_id' => $viewer['id'] ?? null,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'quantity' => (int) $qty,
        ]);
        return [$id, []];
    });
}

// ---- Groups -----------------------------------------------------------------

/** A suspended group cannot log in; reactivating restores its account (§4.2). */
function group_after_save(int $id, array $input): void
{
    $g = q_one('SELECT user_id, status, verified_at FROM groups_orgs WHERE id = ?', [$id]);
    if ($g === null || $g['user_id'] === null) {
        return;
    }
    if ($g['status'] === 'suspended') {
        q("UPDATE users SET status = 'suspended' WHERE id = ? AND role = 'group'", [$g['user_id']]);
        user_end_sessions((int) $g['user_id']);
    } else {
        q("UPDATE users SET status = ? WHERE id = ? AND role = 'group' AND status = 'suspended'", [$g['verified_at'] ? 'active' : 'unverified', $g['user_id']]);
    }
}

// ---- Pages ------------------------------------------------------------------

function page_after_save(int $id, array $input): void
{
    // New page from a starter or saved template: give it sections straight away.
    $choice = is_string($input['start_template'] ?? null) ? $input['start_template'] : '';
    if ($choice !== '' && q_val('SELECT sections FROM pages WHERE id = ?', [$id]) === null && (int) q_val('SELECT COUNT(*) FROM page_revisions WHERE page_id = ?', [$id]) === 0) {
        $sections = builder_template_sections($choice);
        if ($sections !== null) {
            builder_initialise($id, $sections, current_user()['id'] ?? null);
            q('UPDATE pages SET template = ? WHERE id = ?', [mb_substr($choice, 0, 40), $id]);
        }
    }
    if (($input['is_home'] ?? '') === '1') {
        setting_set('home.page_id', $id);
    } elseif ((int) setting('home.page_id', 0) === $id) {
        setting_set('home.page_id', null);
    }
}

function page_load_extra(array $row): array
{
    $row['is_home'] = (int) setting('home.page_id', 0) === (int) $row['id'] ? '1' : '';
    return $row;
}

/** Public URL for any content item row. */
function content_public_url(string $item, array $row): string
{
    return match ($item) {
        'post' => isset($row['base']) ? post_url($row) : post_url(post_load_extra($row)),
        'event' => event_url($row),
        'album' => '/gallery/' . $row['slug'],
        'download' => '/downloads#download-' . $row['id'],
        'leader' => '/leaders/' . (string) q_val('SELECT slug FROM leader_groups WHERE id = ?', [$row['group_id']]) . '/' . $row['slug'],
        'leader_group' => '/leaders/' . $row['slug'],
        'award' => '/awards?year=' . $row['year'] . '#award-' . $row['id'],
        'faq' => '/faq#faq-' . $row['id'],
        'branch' => '/branches#' . $row['slug'],
        'group' => '/groups#' . $row['slug'],
        'category' => '/' . $row['base'] . ($row['is_base'] ? '' : '?category=' . rawurlencode((string) $row['slug'])),
        'page' => (int) setting('home.page_id', 0) === (int) $row['id'] ? '/' : '/' . $row['slug'],
        default => '/',
    };
}

/**
 * Display values for the admin list columns, computed up front so the
 * template only prints. Each cell: [text, kind (text|badge|link|date), href].
 * @return list<array<string, array{0:string,1:string,2:?string}>>
 */
function admin_list_cells(array $type, array $rows): array
{
    $lookups = [];
    $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
    if (isset($type['columns']['category_id'])) {
        $lookups['category_id'] = category_options();
    }
    if (isset($type['columns']['group_id'])) {
        $lookups['group_id'] = leader_group_options();
    }
    $counts = [];
    if ($ids && isset($type['columns']['registrations'])) {
        foreach (q_all("SELECT event_id, SUM(quantity) n FROM event_registrations WHERE status <> 'cancelled' AND event_id IN (" . db_placeholders(count($ids)) . ') GROUP BY event_id', $ids) as $r) {
            $counts['registrations'][(int) $r['event_id']] = (int) $r['n'];
        }
    }
    if ($ids && isset($type['columns']['photos'])) {
        foreach (q_all('SELECT album_id, COUNT(*) n FROM album_photos WHERE album_id IN (' . db_placeholders(count($ids)) . ') GROUP BY album_id', $ids) as $r) {
            $counts['photos'][(int) $r['album_id']] = (int) $r['n'];
        }
    }
    if ($ids && isset($type['columns']['posts'])) {
        foreach (q_all('SELECT category_id, COUNT(*) n FROM posts WHERE category_id IN (' . db_placeholders(count($ids)) . ') GROUP BY category_id', $ids) as $r) {
            $counts['posts'][(int) $r['category_id']] = (int) $r['n'];
        }
    }
    $out = [];
    foreach ($rows as $r) {
        $cells = [];
        $id = (int) $r['id'];
        foreach ($type['columns'] as $col => $label) {
            $cells[$col] = match ($col) {
                'status' => [(string) $r['status'], 'badge', null],
                'visibility' => [(string) $r['visibility'], 'badge', null],
                'publish_at', 'start_at' => [$r[$col] ? fmt_date((string) $r[$col], 'j M Y, H:i') : '—', 'date', null],
                'album_date', 'term_end' => [$r[$col] ? (new DateTimeImmutable((string) $r[$col]))->format('j M Y') : '—', 'date', null],
                'category_id', 'group_id' => [(string) ($lookups[$col][(string) $r[$col]] ?? '—'), 'text', null],
                'registrations' => [(string) ($counts['registrations'][$id] ?? 0), 'link', '/admin/events/' . $id . '/registrations'],
                'photos' => [(string) ($counts['photos'][$id] ?? 0), 'link', '/admin/albums/' . $id . '/photos'],
                'posts' => [(string) ($counts['posts'][$id] ?? 0), 'text', null],
                'slug' => ['/' . ($type['key'] === 'leader-groups' ? 'leaders/' : '') . $r['slug'], 'text', null],
                'base' => [ucfirst((string) $r['base']), 'text', null],
                default => [(string) ($r[$col] ?? ''), 'text', null],
            };
        }
        $out[] = $cells;
    }
    return $out;
}
