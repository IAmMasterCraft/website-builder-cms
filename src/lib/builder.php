<?php
declare(strict_types=1);

use Core\ConflictException;
use Core\Sanitizer;

/*
 * Page builder (§3.1): section registry, schema-driven validation, rendering,
 * draft/publish revisions with an optimistic lock (§12), and templates.
 *
 * A page's sections are a JSON list of
 *   {id, type, hidden, settings{...}, style{background, bg_media_id, spacing, width, anchor}}.
 * pages.sections = what visitors see; pages.draft_revision_id = the revision
 * being edited (autosaved); page_revisions keeps the last 20 per page.
 */

const BUILDER_MAX_SECTIONS = 60;
const BUILDER_MAX_REVISIONS = 20;
const BUILDER_SESSION_MINUTES = 30;
const BUILDER_BACKGROUNDS = ['none' => 'None', 'soft' => 'Soft tint', 'brand' => 'Brand colour', 'dark' => 'Dark', 'image' => 'Image'];
const BUILDER_SPACING = ['compact' => 'Compact', 'normal' => 'Normal', 'roomy' => 'Roomy'];
const BUILDER_WIDTHS = ['contained' => 'Contained', 'full' => 'Full width'];

/** @return array<string, class-string<\Sections\Section>> in picker order */
function builder_section_types(): array
{
    $classes = [
        Sections\Hero::class, Sections\RichText::class, Sections\TextImage::class, Sections\Features::class,
        Sections\Stats::class, Sections\Cta::class, Sections\Spacer::class,
        Sections\SplitMetrics::class, Sections\AboutBand::class, Sections\Process::class, Sections\Points::class,
        Sections\Showcase::class, Sections\Testimonials::class, Sections\Tiers::class, Sections\Logos::class,
        Sections\LatestPosts::class, Sections\UpcomingEvents::class, Sections\EventCalendar::class, Sections\Gallery::class,
        Sections\Faq::class, Sections\Downloads::class,
        Sections\Leaders::class, Sections\Awards::class, Sections\Directory::class, Sections\Birthdays::class, Sections\RadioPlayer::class,
        Sections\Donate::class, Sections\Contact::class, Sections\Embed::class,
    ];
    $out = [];
    foreach ($classes as $c) {
        $out[$c::KEY] = $c;
    }
    return $out;
}

function builder_section_class(string $type): ?string
{
    return builder_section_types()[$type] ?? null;
}

// ---- Option providers ---------------------------------------------------------

function builder_option_providers(): array
{
    return ['icon_options', 'icon_options_none', 'post_source_options', 'album_options', 'leader_group_any_options', 'award_year_any_options', 'faq_category_any_options', 'download_category_any_options'];
}

function builder_options(array $field): array
{
    $o = $field['options'] ?? [];
    if (is_string($o)) {
        return in_array($o, builder_option_providers(), true) ? $o() : [];
    }
    return $o;
}

function icon_options(): array
{
    return [
        'heart' => 'Heart', 'hand-heart' => 'Caring hand', 'heart-handshake' => 'Support', 'handshake' => 'Handshake', 'users' => 'People',
        'user' => 'Person', 'house-heart' => 'Home and family', 'home' => 'Home', 'calendar' => 'Calendar', 'calendar-check' => 'Booked',
        'ticket' => 'Ticket', 'pin' => 'Map pin', 'map' => 'Map', 'globe' => 'Globe', 'building' => 'Building', 'landmark' => 'Civic building',
        'award' => 'Award', 'trophy' => 'Trophy', 'crown' => 'Crown', 'star' => 'Star', 'sparkles' => 'Sparkles', 'badge-check' => 'Verified',
        'shield-check' => 'Safe and trusted', 'id-card' => 'Member card', 'graduation-cap' => 'Learning', 'book-open' => 'Reading', 'baby' => 'Children',
        'sprout' => 'Growing', 'leaf' => 'Leaf', 'flower' => 'Flower', 'tree' => 'Tree', 'utensils' => 'Food', 'coffee' => 'Coffee', 'gift' => 'Gift',
        'music' => 'Music', 'mic' => 'Microphone', 'radio' => 'Radio', 'camera' => 'Camera', 'image' => 'Picture', 'paintbrush' => 'Arts',
        'palette' => 'Palette', 'bike' => 'Sport', 'stethoscope' => 'Health', 'hammer' => 'Repairs', 'briefcase' => 'Work', 'megaphone' => 'Campaign',
        'zap' => 'Energy', 'help' => 'Question', 'flag' => 'Flag', 'mail' => 'Envelope', 'phone' => 'Phone', 'download' => 'Download',
        'check' => 'Tick', 'bell' => 'Bell', 'layers' => 'Layers', 'link' => 'Link', 'smile' => 'Smile', 'wifi' => 'Online',
    ];
}

/** Icons for optional fields: "none" first. */
function icon_options_none(): array
{
    return ['' => 'No icon'] + icon_options();
}

function post_source_options(): array
{
    $out = ['all' => 'All posts', 'news' => 'News', 'blog' => 'Blog', 'projects' => 'Projects'];
    foreach (q_all('SELECT id, name, base FROM categories WHERE is_base = 0 ORDER BY base, sort, name') as $c) {
        $out['cat:' . $c['id']] = 'Category: ' . $c['name'];
    }
    return $out;
}

function album_options(): array
{
    $out = ['' => 'Choose an album'];
    foreach (q_all('SELECT id, title FROM albums ORDER BY COALESCE(album_date, DATE(created_at)) DESC, id DESC LIMIT 200') as $a) {
        $out[(string) $a['id']] = $a['title'];
    }
    return $out;
}

function leader_group_any_options(): array
{
    return ['' => 'All groups'] + leader_group_options();
}

function award_year_any_options(): array
{
    return ['latest' => 'Latest year', 'all' => 'All years'] + award_year_options();
}

function faq_category_any_options(): array
{
    return ['' => 'All categories'] + faq_category_options();
}

function download_category_any_options(): array
{
    $cats = download_categories();
    return ['' => 'All categories'] + (array_combine($cats, $cats) ?: []);
}

// ---- Validation ---------------------------------------------------------------

function builder_new_id(): string
{
    return 's' . bin2hex(random_bytes(5));
}

/** A new section of $type with default settings and style. */
function builder_new_section(string $type): array
{
    $class = builder_section_class($type);
    if ($class === null) {
        throw new InvalidArgumentException('Unknown section type ' . $type);
    }
    return ['id' => builder_new_id(), 'type' => $type, 'hidden' => false, 'settings' => $class::defaults(), 'style' => $class::defaultStyle()];
}

function builder_truthy(mixed $v): bool
{
    return $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on';
}

/** Is the field shown, given its show_if condition and the sibling values? */
function builder_field_active(array $field, array $values): bool
{
    if (!isset($field['show_if'])) {
        return true;
    }
    [$other, $allowed] = $field['show_if'];
    $v = $values[$other] ?? null;
    foreach ($allowed as $a) {
        if (is_bool($a) ? builder_truthy($v) === $a : (string) $v === (string) $a) {
            return true;
        }
    }
    return false;
}

/**
 * Clean one value against its field definition. Errors are added under $path.
 * @param array<string,string> $errors
 */
function builder_clean_field(array $f, mixed $raw, array &$errors, string $path, bool $active = true): mixed
{
    $label = $f['label'];
    $required = !empty($f['required']) && $active;
    switch ($f['type']) {
        case 'checkbox':
            return builder_truthy($raw);
        case 'number':
            $v = is_int($raw) ? $raw : (is_string($raw) && preg_match('/^-?\d{1,6}$/', trim($raw)) ? (int) trim($raw) : null);
            if ($v === null) {
                if ($raw !== null && $raw !== '') {
                    $errors[$path] = "$label must be a whole number.";
                }
                return $f['default'] ?? ($f['min'] ?? 0);
            }
            if ((isset($f['min']) && $v < $f['min']) || (isset($f['max']) && $v > $f['max'])) {
                $errors[$path] = "$label must be between {$f['min']} and {$f['max']}.";
                return max($f['min'] ?? $v, min($f['max'] ?? $v, $v));
            }
            return $v;
        case 'select':
            $v = is_scalar($raw) ? (string) $raw : '';
            $options = builder_options($f);
            if (!array_key_exists($v, $options)) {
                if ($v !== '' || $required) {
                    $errors[$path] = "Choose an option for “{$label}”.";
                }
                return (string) ($f['default'] ?? (string) array_key_first($options));
            }
            return $v;
        case 'media':
            $kind = ($f['kind'] ?? 'image') === 'video' ? 'video' : 'image';
            $noun = $kind === 'video' ? 'video' : 'image';
            $id = is_int($raw) ? $raw : (is_string($raw) && ctype_digit($raw) ? (int) $raw : 0);
            if ($id > 0) {
                $m = media_find($id);
                if ($m === null || $m['kind'] !== $kind || $m['visibility'] !== 'public') {
                    $errors[$path] = "Choose a public $noun for “{$label}”.";
                    return null;
                }
                return $id;
            }
            if ($required) {
                $errors[$path] = "Choose an $noun for “{$label}”.";
            }
            return null;
        case 'richtext':
            $v = Sanitizer::clean(is_string($raw) ? $raw : '');
            if ($required && trim(strip_tags($v)) === '') {
                $errors[$path] = "$label is required.";
            }
            if (strlen($v) > 100000) {
                $errors[$path] = "$label is too long.";
            }
            return $v;
        case 'url':
            $v = is_string($raw) ? trim($raw) : '';
            if ($v !== '' && safe_url($v) === '') {
                $errors[$path] = 'Use a full link starting with https:// or a page address such as /about.';
                return '';
            }
            if (mb_strlen($v) > 500) {
                $errors[$path] = "$label is too long.";
                return '';
            }
            if ($required && $v === '') {
                $errors[$path] = "$label is required.";
            }
            return $v;
        case 'repeater':
            $items = is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
            if (isset($f['max']) && count($items) > $f['max']) {
                $errors[$path] = "“{$label}” can have at most {$f['max']} items.";
                $items = array_slice($items, 0, $f['max']);
            }
            if ($active && isset($f['min']) && count($items) < $f['min']) {
                $errors[$path] = "“{$label}” needs at least {$f['min']} item" . ($f['min'] === 1 ? '' : 's') . '.';
            }
            $out = [];
            foreach ($items as $i => $item) {
                $row = [];
                foreach ($f['fields'] as $sub) {
                    $row[$sub['key']] = builder_clean_field($sub, $item[$sub['key']] ?? null, $errors, "$path.$i.{$sub['key']}", $active && builder_field_active($sub, $item));
                }
                $out[] = $row;
            }
            return $out;
        default: // text, textarea
            $v = is_scalar($raw) ? trim((string) $raw) : '';
            $max = (int) ($f['max'] ?? 1000);
            if (mb_strlen($v) > $max) {
                $errors[$path] = "$label must be at most $max characters.";
                $v = mb_substr($v, 0, $max);
            }
            if ($required && $v === '') {
                $errors[$path] = "$label is required.";
            }
            return $v;
    }
}

/**
 * Validate settings for a section type against its schema.
 * @return array{0:array,1:array<string,string>} [clean settings, errors by field path]
 */
function builder_validate_settings(string $type, array $raw): array
{
    $class = builder_section_class($type);
    if ($class === null) {
        return [[], ['type' => 'Unknown section type.']];
    }
    $errors = [];
    $out = [];
    $schema = $class::schema();
    foreach ($schema as $f) {
        $value = array_key_exists($f['key'], $raw) ? $raw[$f['key']] : ($f['default'] ?? null);
        $out[$f['key']] = builder_clean_field($f, $value, $errors, $f['key'], builder_field_active($f, $raw + $class::defaults()));
    }
    return [$out, $errors];
}

/** @return array{0:array,1:array<string,string>} */
function builder_validate_style(array $raw, string $type): array
{
    $class = builder_section_class($type);
    $d = $class !== null ? $class::defaultStyle() : \Sections\Section::defaultStyle();
    $errors = [];
    $bg = (string) ($raw['background'] ?? $d['background']);
    if (!array_key_exists($bg, BUILDER_BACKGROUNDS)) {
        $errors['style.background'] = 'Choose a background.';
        $bg = $d['background'];
    }
    $bgMedia = null;
    if ($bg === 'image') {
        $bgMedia = builder_clean_field(['type' => 'media', 'label' => 'Background image', 'required' => true], $raw['bg_media_id'] ?? null, $errors, 'style.bg_media_id');
    }
    $spacing = (string) ($raw['spacing'] ?? $d['spacing']);
    if (!array_key_exists($spacing, BUILDER_SPACING)) {
        $errors['style.spacing'] = 'Choose the spacing.';
        $spacing = $d['spacing'];
    }
    $width = (string) ($raw['width'] ?? $d['width']);
    if (!array_key_exists($width, BUILDER_WIDTHS)) {
        $errors['style.width'] = 'Choose the width.';
        $width = $d['width'];
    }
    $anchor = is_string($raw['anchor'] ?? null) ? trim($raw['anchor']) : '';
    if ($anchor !== '' && !preg_match('/^[a-z][a-z0-9-]{0,39}$/', $anchor)) {
        $errors['style.anchor'] = 'Use lowercase letters, numbers and dashes, starting with a letter (e.g. contact-us).';
        $anchor = '';
    }
    return [['background' => $bg, 'bg_media_id' => $bgMedia, 'spacing' => $spacing, 'width' => $width, 'anchor' => $anchor], $errors];
}

/**
 * Validate a full list of sections (as sent by the editor).
 * Unknown types are dropped; missing or duplicate ids are replaced.
 * @return array{0:list<array>,1:array<string,array<string,string>>} [sections, errors by section id]
 */
function builder_validate_sections(mixed $raw): array
{
    $out = [];
    $errors = [];
    if (!is_array($raw)) {
        return [[], ['_page' => ['sections' => 'The page sections could not be read.']]];
    }
    $seen = [];
    foreach (array_values($raw) as $i => $s) {
        if (!is_array($s) || !is_string($s['type'] ?? null) || builder_section_class($s['type']) === null) {
            continue;
        }
        if (count($out) >= BUILDER_MAX_SECTIONS) {
            $errors['_page']['sections'] = 'A page can have at most ' . BUILDER_MAX_SECTIONS . ' sections.';
            break;
        }
        $id = is_string($s['id'] ?? null) && preg_match('/^s[a-z0-9]{6,16}$/', $s['id']) && !isset($seen[$s['id']]) ? $s['id'] : builder_new_id();
        $seen[$id] = true;
        [$settings, $e1] = builder_validate_settings($s['type'], is_array($s['settings'] ?? null) ? $s['settings'] : []);
        [$style, $e2] = builder_validate_style(is_array($s['style'] ?? null) ? $s['style'] : [], $s['type']);
        if ($e1 + $e2) {
            $errors[$id] = $e1 + $e2;
        }
        $out[] = ['id' => $id, 'type' => $s['type'], 'hidden' => builder_truthy($s['hidden'] ?? false), 'settings' => $settings, 'style' => $style];
    }
    return [$out, $errors];
}

function builder_decode(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }
    $v = json_decode($json, true);
    return is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
}

function builder_encode(array $sections): string
{
    return json_encode(array_values($sections), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// ---- Embeds and helpers -------------------------------------------------------

/**
 * Turn a pasted link into an allow-listed embed (§3.1, §11).
 * @return array{kind:'iframe',src:string,provider:string}|array{kind:'link',url:string,provider:string}|null
 */
function embed_resolve(string $url): ?array
{
    $url = trim($url);
    if ($url === '' || !preg_match('#^https://#i', $url) || preg_match('/[\s"<>]/', $url)) {
        return null;
    }
    $parts = parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = (string) ($parts['path'] ?? '');
    parse_str((string) ($parts['query'] ?? ''), $q);
    $yt = static fn (string $id): ?array => preg_match('/^[A-Za-z0-9_-]{11}$/', $id) ? ['kind' => 'iframe', 'src' => 'https://www.youtube-nocookie.com/embed/' . $id, 'provider' => 'YouTube'] : null;
    if (in_array($host, ['www.youtube.com', 'youtube.com', 'm.youtube.com', 'www.youtube-nocookie.com'], true)) {
        if ($path === '/watch' && is_string($q['v'] ?? null)) {
            return $yt($q['v']);
        }
        if (preg_match('#^/(embed|shorts|live)/([A-Za-z0-9_-]{11})#', $path, $m)) {
            return $yt($m[2]);
        }
        return null;
    }
    if ($host === 'youtu.be' && preg_match('#^/([A-Za-z0-9_-]{11})#', $path, $m)) {
        return $yt($m[1]);
    }
    if (in_array($host, ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true) && preg_match('#/(\d{5,12})(?:/|$)#', $path, $m)) {
        return ['kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/' . $m[1] . '?dnt=1', 'provider' => 'Vimeo'];
    }
    if (in_array($host, ['www.google.com', 'maps.google.com', 'google.com'], true) && str_starts_with($path, '/maps')) {
        if ($path === '/maps/embed' && is_string($q['pb'] ?? null) && preg_match('/^[A-Za-z0-9!._~:%-]+$/', $q['pb'])) {
            return ['kind' => 'iframe', 'src' => 'https://www.google.com/maps/embed?pb=' . $q['pb'], 'provider' => 'Google Maps'];
        }
        $place = is_string($q['q'] ?? null) ? $q['q'] : (preg_match('#^/maps/(?:place|search)/([^/@]+)#', $path, $m) ? urldecode(str_replace('+', ' ', $m[1])) : '');
        return $place !== '' ? ['kind' => 'iframe', 'src' => 'https://www.google.com/maps?q=' . rawurlencode($place) . '&output=embed', 'provider' => 'Google Maps'] : null;
    }
    if (in_array($host, ['www.facebook.com', 'facebook.com', 'm.facebook.com'], true) && preg_match('#^/([A-Za-z0-9.\-]{2,80})/?$#', $path, $m)) {
        $page = 'https://www.facebook.com/' . $m[1];
        return ['kind' => 'iframe', 'src' => 'https://www.facebook.com/plugins/page.php?href=' . rawurlencode($page) . '&tabs=timeline&width=500&height=700&small_header=true&hide_cover=false', 'provider' => 'Facebook'];
    }
    if (in_array($host, ['x.com', 'www.x.com', 'twitter.com', 'www.twitter.com'], true) && preg_match('#^/([A-Za-z0-9_]{1,15})/?$#', $path, $m)) {
        // X timelines need X's own script, which the site's strict CSP does not
        // allow; show a link card instead.
        return ['kind' => 'link', 'url' => 'https://x.com/' . $m[1], 'provider' => 'X', 'handle' => '@' . $m[1]];
    }
    return null;
}

/** Donation links from Site settings (label + https url). */
function donate_links(): array
{
    $out = [];
    foreach ((array) setting('donate.links', []) as $l) {
        if (is_array($l) && trim((string) ($l['label'] ?? '')) !== '' && preg_match('#^https://#i', (string) ($l['url'] ?? ''))) {
            $out[] = ['label' => (string) $l['label'], 'url' => (string) $l['url']];
        }
    }
    return $out;
}

function db_table_exists(string $table): bool
{
    static $cache = [];
    return $cache[$table] ??= q_val('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]) > 0;
}

// ---- Rendering ----------------------------------------------------------------

/**
 * Render visible sections. $ctx: viewer, page, preview (adds data-section-id
 * and shows hidden sections dimmed), now, has_title_hero.
 */
function builder_render(array $sections, array $ctx): string
{
    $html = '';
    $preview = !empty($ctx['preview']);
    $firstVisible = true;
    foreach ($sections as $s) {
        $class = builder_section_class((string) ($s['type'] ?? ''));
        if ($class === null || (!empty($s['hidden']) && !$preview)) {
            continue;
        }
        $settings = (is_array($s['settings'] ?? null) ? $s['settings'] : []) + $class::defaults();
        $style = (is_array($s['style'] ?? null) ? $s['style'] : []) + $class::defaultStyle();
        $sctx = $ctx + ['heading_tag' => 'h2'];
        if ($firstVisible && $s['type'] === 'hero' && empty($s['hidden'])) {
            $sctx['heading_tag'] = 'h1';
        }
        try {
            $inner = $class::render($settings, $sctx);
        } catch (Throwable $e) {
            error_log('Section ' . $s['type'] . ' failed: ' . $e->getMessage());
            $inner = $preview ? '<div class="container"><p class="notice warn">This section could not be shown: ' . e($e->getMessage()) . '</p></div>' : '';
        }
        if (trim($inner) === '') {
            if (!$preview) {
                continue;
            }
            $inner = '<div class="container"><p class="blk-empty">' . e($class::LABEL) . ': nothing to show here yet (hidden from visitors).</p></div>';
        }
        $html .= view('sections/_wrap', ['section' => $s, 'settings' => $settings, 'style' => $style, 'inner' => $inner, 'preview' => $preview]);
        if (empty($s['hidden'])) {
            $firstVisible = false;
        }
    }
    return $html;
}

/** Does the first visible section supply the page's h1 (a hero)? */
function builder_has_title_hero(array $sections): bool
{
    foreach ($sections as $s) {
        if (!empty($s['hidden'])) {
            continue;
        }
        return ($s['type'] ?? '') === 'hero';
    }
    return false;
}

/** Plain-text summary of sections for site search (stored in pages.body as simple HTML). */
function builder_search_html(array $sections): string
{
    $parts = [];
    $walk = static function (mixed $v) use (&$walk, &$parts): void {
        if (is_array($v)) {
            foreach ($v as $k => $x) {
                if (!in_array($k, ['url', 'button1_url', 'button2_url', 'link_url', 'button_url', 'media_id', 'icon', 'metric', 'layout', 'source'], true)) {
                    $walk($x);
                }
            }
        } elseif (is_string($v) && trim($v) !== '' && !preg_match('#^(/|https?:|\d+$|[a-z_:0-9]+$)#', trim($v))) {
            $parts[] = excerpt($v, 2000);
        }
    };
    foreach ($sections as $s) {
        if (empty($s['hidden'])) {
            $walk($s['settings'] ?? []);
        }
    }
    return implode('', array_map(static fn (string $p): string => '<p>' . e($p) . '</p>', array_unique($parts)));
}

// ---- Drafts, publishing, revisions ---------------------------------------------

/** The sections being edited: the draft revision, else the live sections, else the old body as a rich-text section. */
function builder_draft_sections(array $page): array
{
    if (!empty($page['draft_revision_id'])) {
        $json = q_val('SELECT sections FROM page_revisions WHERE id = ? AND page_id = ?', [$page['draft_revision_id'], $page['id']]);
        if ($json !== null) {
            return builder_decode((string) $json);
        }
    }
    if ($page['sections'] !== null) {
        return builder_decode((string) $page['sections']);
    }
    $section = builder_new_section('rich_text');
    $section['settings']['body'] = Sanitizer::clean((string) $page['body']);
    return trim(strip_tags((string) $page['body'])) !== '' ? [$section] : [];
}

/** Has the draft changed since the last publish? */
function builder_has_unpublished(array $page): bool
{
    return !empty($page['draft_revision_id']) && (int) $page['draft_revision_id'] !== (int) $page['published_revision_id'];
}

/** Lock the page row and check the editor's base revision. */
function builder_lock(int $pageId, int $baseRevision): array
{
    $page = q_one('SELECT * FROM pages WHERE id = ? FOR UPDATE', [$pageId]);
    if ($page === null) {
        throw new DomainException('Page not found.');
    }
    if ((int) $page['revision'] !== $baseRevision) {
        $by = $page['updated_by'] ? (string) q_val('SELECT display_name FROM users WHERE id = ?', [$page['updated_by']]) : 'another admin';
        throw new ConflictException($by, (string) $page['updated_at'], (int) $page['revision']);
    }
    return $page;
}

/**
 * Autosave: store $sections (already validated) as the page's draft revision.
 * Keeps updating the same revision while one person keeps editing; starts a
 * new one after a publish, a restore, another editor, or a 30-minute pause.
 * @return int the page's new revision number
 */
function builder_save_draft(int $pageId, array $sections, int $baseRevision, ?int $userId): int
{
    return db_tx(static function () use ($pageId, $sections, $baseRevision, $userId): int {
        $page = builder_lock($pageId, $baseRevision);
        $json = builder_encode($sections);
        $next = (int) $page['revision'] + 1;
        $draft = $page['draft_revision_id'] ? q_one('SELECT * FROM page_revisions WHERE id = ?', [$page['draft_revision_id']]) : null;
        $reuse = $draft !== null
            && (int) $draft['id'] !== (int) $page['published_revision_id']
            && (int) ($draft['author_id'] ?? 0) === (int) $userId
            && $draft['note'] === ''
            && strtotime($draft['updated_at'] . ' UTC') > time() - BUILDER_SESSION_MINUTES * 60;
        if ($reuse) {
            q('UPDATE page_revisions SET sections = ?, revision = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$json, $next, $draft['id']]);
            $draftId = (int) $draft['id'];
        } else {
            $draftId = db_insert('page_revisions', ['page_id' => $pageId, 'revision' => $next, 'sections' => $json, 'author_id' => $userId]);
            // One audit entry per editing session (autosaves in between only update this draft).
            audit('draft', 'page', $pageId, 'started a new draft (revision ' . $next . ')');
        }
        q('UPDATE pages SET revision = ?, draft_revision_id = ?, updated_by = ? WHERE id = ?', [$next, $draftId, $userId, $pageId]);
        builder_prune($pageId);
        return $next;
    });
}

/**
 * Publish: the draft becomes what visitors see. Draft pages become published.
 * @return int new page revision
 */
function builder_publish(int $pageId, int $baseRevision, ?int $userId): int
{
    return db_tx(static function () use ($pageId, $baseRevision, $userId): int {
        $page = builder_lock($pageId, $baseRevision);
        $sections = builder_draft_sections($page);
        $json = builder_encode($sections);
        $next = (int) $page['revision'] + 1;
        $revId = builder_has_unpublished($page) ? (int) $page['draft_revision_id'] : 0;
        if ($revId === 0) {
            $revId = db_insert('page_revisions', ['page_id' => $pageId, 'revision' => $next, 'sections' => $json, 'author_id' => $userId]);
        }
        q('UPDATE page_revisions SET published_at = UTC_TIMESTAMP(), revision = ? WHERE id = ?', [$next, $revId]);
        [$status, $publishAt] = $page['status'] === 'draft' ? normalise_publish('published', $page['publish_at']) : [$page['status'], $page['publish_at'] ?? utc_now()];
        q(
            'UPDATE pages SET sections = ?, body = ?, published_revision_id = ?, draft_revision_id = NULL, revision = ?, status = ?, publish_at = ?, updated_by = ? WHERE id = ?',
            [$json, builder_search_html($sections), $revId, $next, $status, $publishAt, $userId, $pageId]
        );
        builder_prune($pageId);
        return $next;
    });
}

/** Restore an old revision as a new draft (publish to make it live). */
function builder_restore(int $pageId, int $revisionId, int $baseRevision, ?int $userId): int
{
    return db_tx(static function () use ($pageId, $revisionId, $baseRevision, $userId): int {
        $page = builder_lock($pageId, $baseRevision);
        $old = q_one('SELECT * FROM page_revisions WHERE id = ? AND page_id = ?', [$revisionId, $pageId]);
        if ($old === null) {
            throw new DomainException('That revision no longer exists.');
        }
        $next = (int) $page['revision'] + 1;
        $id = db_insert('page_revisions', [
            'page_id' => $pageId, 'revision' => $next, 'sections' => $old['sections'], 'author_id' => $userId,
            'note' => 'Restored from ' . fmt_date((string) $old['created_at'], 'j M Y, H:i'),
        ]);
        q('UPDATE pages SET revision = ?, draft_revision_id = ?, updated_by = ? WHERE id = ?', [$next, $id, $userId, $pageId]);
        builder_prune($pageId);
        return $next;
    });
}

/** Keep the newest 20 revisions; never delete the live or the draft one. */
function builder_prune(int $pageId): int
{
    $page = q_one('SELECT draft_revision_id, published_revision_id FROM pages WHERE id = ?', [$pageId]);
    $keep = array_column(q_all('SELECT id FROM page_revisions WHERE page_id = ? ORDER BY id DESC LIMIT ' . BUILDER_MAX_REVISIONS, [$pageId]), 'id');
    $keep[] = (int) ($page['draft_revision_id'] ?? 0);
    $keep[] = (int) ($page['published_revision_id'] ?? 0);
    $keep = array_values(array_unique(array_map('intval', $keep)));
    return q('DELETE FROM page_revisions WHERE page_id = ? AND id NOT IN (' . db_placeholders(count($keep)) . ')', array_merge([$pageId], $keep))->rowCount();
}

/** @return list<array> newest first, with author name and flags */
function builder_revisions(array $page): array
{
    $rows = q_all(
        'SELECT r.id, r.revision, r.created_at, r.updated_at, r.published_at, r.note, r.author_id, u.display_name AS author
           FROM page_revisions r LEFT JOIN users u ON u.id = r.author_id WHERE r.page_id = ? ORDER BY r.id DESC LIMIT ' . BUILDER_MAX_REVISIONS,
        [$page['id']]
    );
    foreach ($rows as &$r) {
        $r['is_live'] = (int) $r['id'] === (int) $page['published_revision_id'];
        $r['is_draft'] = (int) $r['id'] === (int) $page['draft_revision_id'];
    }
    unset($r);
    return $rows;
}

/** Give a page its first sections (new page from a template): live straight away. */
function builder_initialise(int $pageId, array $sections, ?int $userId): void
{
    $json = builder_encode($sections);
    $revId = db_insert('page_revisions', ['page_id' => $pageId, 'revision' => 1, 'sections' => $json, 'author_id' => $userId, 'note' => 'Created from a template']);
    q('UPDATE page_revisions SET published_at = UTC_TIMESTAMP() WHERE id = ?', [$revId]);
    q('UPDATE pages SET sections = ?, body = ?, published_revision_id = ?, draft_revision_id = NULL, revision = revision + 1 WHERE id = ?', [$json, builder_search_html($sections), $revId, $pageId]);
}

// ---- Templates ----------------------------------------------------------------

/** Build a section from a type with settings overrides (for starter templates). */
function builder_make(string $type, array $settings = [], array $style = []): array
{
    $s = builder_new_section($type);
    $s['settings'] = array_merge($s['settings'], $settings);
    $s['style'] = array_merge($s['style'], $style);
    return $s;
}

/**
 * Starter page templates shipped with Gather (§3.1).
 * @return array<string, array{name:string, description:string, sections:list<array>}>
 */
function builder_starter_templates(): array
{
    return [
        'home' => ['name' => 'Home', 'description' => 'Full-screen hero, key numbers, story, programmes, events, testimonials, news and a join band.', 'sections' => [
            builder_make('hero'),
            builder_make('stats', ['layout' => 'band'], ['background' => 'dark', 'spacing' => 'compact']),
            builder_make('split_metrics', [], ['background' => 'soft']),
            builder_make('features', ['eyebrow' => 'What we do', 'head_layout' => 'center']),
            builder_make('upcoming_events', ['eyebrow' => 'Get together', 'head_layout' => 'split'], ['background' => 'soft']),
            builder_make('testimonials'),
            builder_make('latest_posts', ['eyebrow' => 'Latest', 'layout' => 'magazine', 'count' => 4]),
            builder_make('gallery', ['eyebrow' => 'Moments', 'layout' => 'mosaic', 'count' => 6], ['background' => 'soft']),
            builder_make('cta'),
        ]],
        'about' => ['name' => 'About', 'description' => 'Your story, values, milestones, numbers and leaders.', 'sections' => [
            builder_make('about_band'),
            builder_make('text_image', ['eyebrow' => 'About us', 'heading' => 'Who we are']),
            builder_make('points', ['eyebrow' => 'What we stand for', 'heading' => 'Our values', 'style' => 'numbers'], ['background' => 'soft']),
            builder_make('process', ['eyebrow' => 'Milestones', 'heading' => 'Our story so far']),
            builder_make('leaders', ['heading' => 'Meet the team', 'head_layout' => 'split'], ['background' => 'soft']),
            builder_make('logos', ['eyebrow' => 'Our partners', 'style' => 'grid']),
            builder_make('cta', ['heading' => 'Want to help?', 'text' => 'We are always looking for volunteers and new members.', 'button1_label' => 'Get involved', 'tone' => 'dark']),
        ]],
        'contact' => ['name' => 'Contact', 'description' => 'Contact form, map and details, plus common questions.', 'sections' => [
            builder_make('contact'),
            builder_make('faq', ['heading' => 'Before you write', 'count' => 4], ['background' => 'soft']),
        ]],
        'leaders' => ['name' => 'Leaders', 'description' => 'Introduction and every leadership group.', 'sections' => [
            builder_make('rich_text', ['heading' => 'Our leadership', 'body' => '<p>Our association is run by elected volunteers. Meet the people who serve on our committees.</p>']),
            builder_make('leaders', ['heading' => 'Current leaders', 'count' => 24, 'link_label' => '']),
            builder_make('awards', ['heading' => 'Recognising our volunteers'], ['background' => 'soft']),
        ]],
        'gallery' => ['name' => 'Gallery', 'description' => 'Masonry of recent photos and a video.', 'sections' => [
            builder_make('rich_text', ['heading' => 'Photos and videos', 'body' => '<p>Moments from our events and projects. Browse every album in the gallery.</p>']),
            builder_make('gallery', ['heading' => 'Latest photos', 'count' => 24, 'layout' => 'masonry']),
            builder_make('embed', ['heading' => 'Watch', 'title' => 'Video from our community']),
        ]],
        'events' => ['name' => 'Events', 'description' => 'Upcoming events, this month’s calendar and a call to join.', 'sections' => [
            builder_make('upcoming_events', ['heading' => 'Coming up', 'count' => 6, 'layout' => 'grid']),
            builder_make('event_calendar', [], ['background' => 'soft']),
            builder_make('cta', ['heading' => 'Never miss an event', 'text' => 'Members hear about new events first.', 'button1_label' => 'Become a member']),
        ]],
        'membership' => ['name' => 'Membership', 'description' => 'Tiers, benefits, how to join, questions and a sign-up band.', 'sections' => [
            builder_make('hero', ['eyebrow' => 'Membership', 'heading' => 'Join our community', 'text' => 'Members get a digital ID card, early event news and a voice in how we run things.', 'button1_label' => 'Join now', 'button1_url' => '/join', 'button2_label' => 'Register a group', 'button2_url' => '/join/group', 'height' => 'tall']),
            builder_make('tiers', [], ['background' => 'soft']),
            builder_make('points', ['style' => 'checks']),
            builder_make('process', ['eyebrow' => 'How it works', 'heading' => 'Four steps to your member card', 'layout' => 'horizontal'], ['background' => 'soft']),
            builder_make('faq', ['heading' => 'Membership questions']),
            builder_make('cta', ['heading' => 'Ready to join?', 'text' => 'It takes about five minutes. Have a photo ready for your card.', 'button1_label' => 'Start now']),
        ]],
    ];
}

/** A template's sections with fresh ids. */
function builder_fresh_ids(array $sections): array
{
    return array_map(static fn (array $s): array => ['id' => builder_new_id()] + $s, array_map(static function (array $s): array {
        unset($s['id']);
        return $s;
    }, $sections));
}

/** Sections for a "start from" choice: starter:<key> or saved:<id>. */
function builder_template_sections(string $choice): ?array
{
    if (preg_match('/^starter:([a-z]+)$/', $choice, $m)) {
        $t = builder_starter_templates()[$m[1]] ?? null;
        return $t !== null ? builder_fresh_ids($t['sections']) : null;
    }
    if (preg_match('/^saved:(\d+)$/', $choice, $m)) {
        $json = q_val("SELECT sections FROM section_templates WHERE id = ? AND kind = 'page'", [(int) $m[1]]);
        return $json !== null ? builder_fresh_ids(builder_decode((string) $json)) : null;
    }
    return null;
}

function builder_template_choices(): array
{
    $out = ['' => 'Blank page'];
    foreach (builder_starter_templates() as $k => $t) {
        $out['starter:' . $k] = 'Starter: ' . $t['name'];
    }
    foreach (q_all("SELECT id, name FROM section_templates WHERE kind = 'page' ORDER BY name") as $t) {
        $out['saved:' . $t['id']] = 'Saved: ' . $t['name'];
    }
    return $out;
}

/** Save sections as a reusable template. */
function builder_save_template(string $name, string $kind, array $sections, ?int $userId): int
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 120) {
        throw new InvalidArgumentException('Give the template a name (up to 120 characters).');
    }
    if (!in_array($kind, ['section', 'page'], true) || $sections === []) {
        throw new InvalidArgumentException('Nothing to save.');
    }
    [$clean] = builder_validate_sections($sections);
    if ($clean === []) {
        throw new InvalidArgumentException('Nothing to save.');
    }
    return db_insert('section_templates', ['name' => $name, 'kind' => $kind, 'sections' => builder_encode($clean), 'created_by' => $userId]);
}

/**
 * The home page hero's first photo, reused as the picture beside the log-in
 * and sign-up forms so they match the site. Null when there is none.
 */
function site_feature_image(): ?array
{
    static $done = false;
    static $media = null;
    if ($done) {
        return $media;
    }
    $done = true;
    $homeId = (int) setting('home.page_id', 0);
    $json = $homeId > 0 ? q_val("SELECT sections FROM pages WHERE id = ? AND status = 'published'", [$homeId]) : null;
    foreach (builder_decode($json !== null ? (string) $json : null) as $s) {
        if (($s['type'] ?? '') === 'hero' && empty($s['hidden'])) {
            $id = (int) ($s['settings']['slides'][0]['media_id'] ?? 0);
            $m = media_find($id ?: null);
            $media = $m !== null && $m['kind'] === 'image' && $m['visibility'] === 'public' ? $m : null;
            break;
        }
    }
    return $media;
}
