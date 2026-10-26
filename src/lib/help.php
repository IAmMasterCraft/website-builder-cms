<?php
declare(strict_types=1);

use Core\Markdown;

/*
 * Help centre (Addendum B3): the Markdown guides in docs/ rendered as HTML.
 *
 * Three output modes share one renderer and one manifest:
 *   admin  - /admin/help/...  (staff, every guide)
 *   public - /help            (the member guide only; public or members-only per setting)
 *   static - bin/docs-html    (standalone site in dist/docs-site/, relative links)
 * Docs stay outside public/; their images are served through a route
 * (help_image_file() checks the name and the resolved path).
 */

/** Bump when the renderer or the link rules change, so cached pages are rebuilt. */
const HELP_RENDER_VERSION = '2';
const HELP_SEARCH_MAX_RESULTS = 30;
/** Element ids the admin, site and static layouts use; doc headings never take them. */
const HELP_RESERVED_IDS = ['main', 'sidebar', 'admin-q', 'site-nav', 'q404', 'help-q', 'help-toc', 'help-contents', 'help-results-title', 'cookie-title'];

function help_dir(): string
{
    return BASE . '/docs';
}

/**
 * Every guide in reading order: slug => [file (relative to docs/), section].
 * The slug is the file path without ".md" (README = the folder's index).
 * A unit test checks that this list and the files in docs/ match.
 * @return array<string,array{file:string,section:string}>
 */
function help_manifest(): array
{
    $mg = static fn (string $name): array => ['file' => 'manager-guide/' . $name . '.md', 'section' => 'Website manager guide'];
    $list = [
        'index' => ['file' => 'README.md', 'section' => 'Start here'],
        'manager-guide' => ['file' => 'manager-guide/README.md', 'section' => 'Website manager guide'],
    ];
    foreach ([
        'how-do-i', 'first-login', 'help-centre', 'settings', 'design', 'header-footer-menus', 'page-builder', 'media-library',
        'posts', 'events', 'gallery', 'downloads', 'leaders', 'awards', 'faqs', 'branches-groups',
        'members', 'jobs', 'comments', 'notices', 'campaigns', 'email-templates', 'inbox', 'email-settings',
        'radio', 'users-roles', 'audit-log', 'backups', 'system-health', 'updating', 'troubleshooting',
    ] as $name) {
        $list['manager-guide/' . $name] = $mg($name);
    }
    $list['member-guide'] = ['file' => 'member-guide.md', 'section' => 'Member guide'];
    $list['email-deliverability'] = ['file' => 'email-deliverability.md', 'section' => 'Email'];
    foreach (['getting-the-code', 'requirements', 'install-cpanel', 'install-vps', 'install-aaPanel', 'install-plesk', 'local-development', 'docs-website'] as $name) {
        $list[strtolower($name)] = ['file' => $name . '.md', 'section' => 'Installing and hosting'];
    }
    return $list;
}

/** Slugs a mode may show. */
function help_mode_slugs(string $mode): array
{
    return $mode === 'public' ? ['member-guide'] : array_keys(help_manifest());
}

/** Title of a guide: its first "# " heading (cached per request). */
function help_title(string $slug): string
{
    static $titles = [];
    if (!isset($titles[$slug])) {
        $file = help_dir() . '/' . (help_manifest()[$slug]['file'] ?? '');
        $title = $slug;
        if (is_file($file) && ($fh = fopen($file, 'rb')) !== false) {
            while (($line = fgets($fh)) !== false) {
                if (preg_match('/^#\s+(.+?)\s*#*\s*$/', $line, $m)) {
                    // Plain text: drop emphasis markers ("Site settings *(super admin)*").
                    $title = trim((string) preg_replace('/[*_`]+/', '', $m[1]));
                    break;
                }
            }
            fclose($fh);
        }
        $titles[$slug] = $title;
    }
    return $titles[$slug];
}

/** Table of contents for a mode: section => list of [slug, title]. */
function help_toc(string $mode): array
{
    $toc = [];
    $allowed = help_mode_slugs($mode);
    foreach (help_manifest() as $slug => $doc) {
        if (in_array($slug, $allowed, true)) {
            $toc[$doc['section']][] = ['slug' => $slug, 'title' => help_title($slug)];
        }
    }
    return $toc;
}

/** Output path of a guide in the static site. */
function help_static_path(string $slug): string
{
    if ($slug === 'index') {
        return 'index.html';
    }
    $file = help_manifest()[$slug]['file'] ?? $slug . '.md';
    return str_ends_with($file, 'README.md') ? substr($file, 0, -9) . 'index.html' : substr($file, 0, -3) . '.html';
}

/** Relative path from one static file to another ("manager-guide/a.html" -> "../index.html"). */
function help_relative(string $from, string $to): string
{
    $fromDir = array_values(array_filter(explode('/', dirname($from)), static fn (string $p): bool => $p !== '.' && $p !== ''));
    $toParts = explode('/', $to);
    $toFile = array_pop($toParts);
    while ($fromDir !== [] && $toParts !== [] && $fromDir[0] === $toParts[0]) {
        array_shift($fromDir);
        array_shift($toParts);
    }
    return str_repeat('../', count($fromDir)) . implode('/', array_merge($toParts, [$toFile]));
}

/** URL of a guide in a mode ($from = the slug of the page the link is on, for static paths). */
function help_url(string $slug, string $mode, string $from = 'index'): string
{
    return match ($mode) {
        'public' => '/help',
        'static' => help_relative(help_static_path($from), help_static_path($slug)),
        default => $slug === 'index' ? '/admin/help' : '/admin/help/' . $slug,
    };
}

/** Allowed docs image name ("admin-members.webp"); no SVG (could carry script). */
function help_image_name_ok(string $name): bool
{
    return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{0,100}\.(webp|png|jpe?g|gif)$/', $name);
}

/** Absolute path of a docs image, or null when the name is not allowed or the file is missing. */
function help_image_file(string $name): ?string
{
    if (!help_image_name_ok($name)) {
        return null;
    }
    $dir = realpath(help_dir() . '/images');
    $path = realpath(help_dir() . '/images/' . basename($name));
    if ($dir === false || $path === false || !str_starts_with($path, $dir . DIRECTORY_SEPARATOR) || !is_file($path)) {
        return null;
    }
    return $path;
}

/**
 * Image names the public member guide (/help) may serve: only those
 * referenced by the guides of the public mode (admin screenshots stay staff
 * only). Cached per request and per file modification time.
 * @return list<string>
 */
function help_public_images(): array
{
    static $cache = [];
    $names = [];
    foreach (help_mode_slugs('public') as $slug) {
        $file = help_dir() . '/' . help_manifest()[$slug]['file'];
        $key = $file . '|' . (string) filemtime($file);
        if (!isset($cache[$key])) {
            preg_match_all('/!\[[^\]]*\]\(<?([^)\s>]+)/', (string) file_get_contents($file), $m);
            $cache[$key] = [];
            $baseDir = dirname(help_manifest()[$slug]['file']);
            foreach ($m[1] as $src) {
                $path = help_normalise_path($baseDir, rawurldecode($src));
                if ($path !== null && str_starts_with($path, 'images/') && help_image_name_ok(substr($path, 7))) {
                    $cache[$key][] = substr($path, 7);
                }
            }
        }
        $names = array_merge($names, $cache[$key]);
    }
    return array_values(array_unique($names));
}

/** Normalise "a/b/../c.md" (relative to docs/); null when it leaves docs/. */
function help_normalise_path(string $baseDir, string $rel): ?string
{
    $parts = [];
    foreach (explode('/', ($baseDir !== '' && $baseDir !== '.' ? $baseDir . '/' : '') . $rel) as $p) {
        if ($p === '' || $p === '.') {
            continue;
        }
        if ($p === '..') {
            if ($parts === []) {
                return null;
            }
            array_pop($parts);
            continue;
        }
        $parts[] = $p;
    }
    return implode('/', $parts);
}

/** Slug for a docs file path ("manager-guide/members.md"). */
function help_slug_for_file(string $file): ?string
{
    foreach (help_manifest() as $slug => $doc) {
        if (strcasecmp($doc['file'], $file) === 0) {
            return $slug;
        }
    }
    return null;
}

/**
 * Rewrite a link found in a guide. Links between guides go to the help
 * routes (or .html files); app paths stay; links to files outside docs/ and
 * guides the mode cannot show become plain text (null).
 */
function help_resolve_link(string $href, string $fromSlug, string $mode): ?string
{
    if (preg_match('#^(https?:|mailto:)#i', $href)) {
        return $href;
    }
    if (str_starts_with($href, '#')) {
        return $href;
    }
    if (str_starts_with($href, '/')) {
        return $mode === 'static' ? null : $href; // an app path such as /join
    }
    [$path, $fragment] = array_pad(explode('#', $href, 2), 2, null);
    $fragment = $fragment !== null && $fragment !== '' ? '#' . $fragment : '';
    $baseDir = dirname(help_manifest()[$fromSlug]['file'] ?? 'README.md');
    $file = help_normalise_path($baseDir, rawurldecode((string) $path));
    if ($file === null) {
        return null;
    }
    if (str_starts_with($file, 'images/') && help_image_file(substr($file, 7)) !== null) {
        return help_image_url(substr($file, 7), $mode, $fromSlug);
    }
    $slug = str_ends_with(strtolower($file), '.md') ? help_slug_for_file($file) : null;
    if ($slug === null || !in_array($slug, help_mode_slugs($mode), true)) {
        return null;
    }
    if ($mode === 'public' || ($slug === $fromSlug && $fragment !== '')) {
        return $fragment !== '' ? $fragment : help_url($slug, $mode, $fromSlug);
    }
    return help_url($slug, $mode, $fromSlug) . $fragment;
}

function help_image_url(string $name, string $mode, string $fromSlug): string
{
    return match ($mode) {
        'static' => help_relative(help_static_path($fromSlug), 'images/' . $name),
        'public' => '/help/images/' . $name,
        default => '/admin/help/images/' . $name,
    };
}

/** Image sources must be files in docs/images. */
function help_resolve_image(string $src, string $fromSlug, string $mode): ?string
{
    if (preg_match('#^[a-z][a-z0-9+.-]*:|^/#i', $src)) {
        return null;
    }
    $baseDir = dirname(help_manifest()[$fromSlug]['file'] ?? 'README.md');
    $file = help_normalise_path($baseDir, rawurldecode($src));
    if ($file === null || !str_starts_with($file, 'images/') || help_image_file(substr($file, 7)) === null) {
        return null;
    }
    return help_image_url(substr($file, 7), $mode, $fromSlug);
}

/** Render a guide (Markdown -> HTML) without the cache. */
function help_render(string $slug, string $mode): array
{
    $doc = help_manifest()[$slug] ?? null;
    if ($doc === null) {
        throw new InvalidArgumentException('Unknown guide ' . $slug);
    }
    $md = new Markdown(
        static fn (string $href): ?string => help_resolve_link($href, $slug, $mode),
        static fn (string $src): ?string => help_resolve_image($src, $slug, $mode),
        HELP_RESERVED_IDS
    );
    $html = $md->render((string) file_get_contents(help_dir() . '/' . $doc['file']));
    return ['title' => help_title($slug), 'html' => $html, 'headings' => $md->headings, 'sections' => help_sections($html, help_title($slug))];
}

/**
 * Split rendered HTML into searchable sections at h1-h3.
 * @return list<array{id:string,heading:string,text:string}>
 */
function help_sections(string $html, string $title): array
{
    $parts = preg_split('#(<h[1-3] id="[^"]*">.*?</h[1-3]>)#s', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
    $sections = [];
    $current = ['id' => '', 'heading' => $title, 'text' => ''];
    foreach ($parts as $part) {
        if (preg_match('#^<h([1-3]) id="([^"]*)">(.*?)</h\1>$#s', $part, $m)) {
            if (trim($current['text']) !== '' || $current['id'] !== '') {
                $sections[] = $current;
            }
            $heading = html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $current = ['id' => $m[1] === '1' ? '' : html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'heading' => $m[1] === '1' ? $title : $heading, 'text' => ''];
            continue;
        }
        $text = html_entity_decode(strip_tags(str_replace(['</p>', '</li>', '</td>', '</th>', '<br>'], ["\n", "\n", ' ', ' ', "\n"], $part)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $current['text'] .= ' ' . trim((string) preg_replace('/\s+/u', ' ', $text));
    }
    if (trim($current['text']) !== '' || $current['id'] !== '') {
        $sections[] = $current;
    }
    foreach ($sections as &$s) {
        $s['text'] = trim($s['text']);
    }
    return $sections;
}

/**
 * A rendered guide, cached in storage/cache/help/ (keyed by mode, file size,
 * modification time and HELP_RENDER_VERSION; stale copies are removed).
 */
function help_page(string $slug, string $mode): array
{
    $doc = help_manifest()[$slug] ?? null;
    if ($doc === null || !in_array($slug, help_mode_slugs($mode), true)) {
        throw new InvalidArgumentException('Unknown guide ' . $slug);
    }
    $file = help_dir() . '/' . $doc['file'];
    clearstatcache(true, $file);
    $stamp = sha1(HELP_RENDER_VERSION . '|' . (string) filemtime($file) . '|' . (string) filesize($file) . '|' . help_images_stamp());
    $dir = BASE . '/storage/cache/help';
    $prefix = $mode . '-' . substr(sha1($slug), 0, 16);
    $cache = $dir . '/' . $prefix . '-' . substr($stamp, 0, 16) . '.json';
    if (is_file($cache)) {
        $data = json_decode((string) file_get_contents($cache), true);
        if (is_array($data) && isset($data['html'], $data['title'], $data['headings'], $data['sections'])) {
            return $data;
        }
    }
    $data = help_render($slug, $mode);
    if ((is_dir($dir) || mkdir($dir, 0775, true)) && is_writable($dir)) {
        foreach (glob($dir . '/' . $prefix . '-*.json') ?: [] as $old) {
            unlink($old);
        }
        $tmp = $cache . '.' . bin2hex(random_bytes(4)) . '.part';
        if (file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) !== false) {
            rename($tmp, $cache);
        }
    }
    return $data;
}

/** Changes when images are added or removed (a missing image renders as text). */
function help_images_stamp(): string
{
    static $stamp = null;
    return $stamp ??= sha1(implode(',', array_map('basename', glob(help_dir() . '/images/*') ?: [])));
}

/** Previous and next guide in reading order within the mode. */
function help_neighbours(string $slug, string $mode): array
{
    $slugs = help_mode_slugs($mode);
    $i = array_search($slug, $slugs, true);
    if ($i === false) {
        return [null, null];
    }
    $prev = $i > 0 ? $slugs[$i - 1] : null;
    $next = $i < count($slugs) - 1 ? $slugs[$i + 1] : null;
    return [
        $prev !== null ? ['slug' => $prev, 'title' => help_title($prev)] : null,
        $next !== null ? ['slug' => $next, 'title' => help_title($next)] : null,
    ];
}

/** Search terms from a query: up to 8 words of 2+ characters, lower case. */
function help_search_terms(string $q): array
{
    $words = preg_split('/[^\p{L}\p{N}@._-]+/u', mb_strtolower(mb_substr($q, 0, 100))) ?: [];
    $words = array_values(array_unique(array_filter($words, static fn (string $w): bool => mb_strlen($w) >= 2)));
    return array_slice($words, 0, 8);
}

/**
 * Server-side search over the guides of a mode. Every term must appear in a
 * section (heading or text); title and heading matches rank higher.
 * Snippets are returned as plain-text parts [text, is_match] for the template to escape.
 * @return list<array{slug:string,title:string,id:string,heading:string,score:int,snippet:list<array{0:string,1:bool}>}>
 */
function help_search(string $q, string $mode): array
{
    $terms = help_search_terms($q);
    if ($terms === []) {
        return [];
    }
    $results = [];
    foreach (help_mode_slugs($mode) as $slug) {
        $page = help_page($slug, $mode);
        $title = mb_strtolower($page['title']);
        foreach ($page['sections'] as $s) {
            $heading = mb_strtolower($s['heading']);
            $text = mb_strtolower($s['text']);
            $score = 0;
            foreach ($terms as $t) {
                $inText = mb_substr_count($text, $t);
                $inHeading = mb_substr_count($heading, $t);
                if ($inText === 0 && $inHeading === 0) {
                    continue 2;
                }
                $score += min($inText, 10) + 4 * $inHeading + (str_contains($title, $t) ? 2 : 0);
            }
            $results[] = ['slug' => $slug, 'title' => $page['title'], 'id' => $s['id'], 'heading' => $s['heading'], 'score' => $score, 'snippet' => help_snippet($s['text'], $terms)];
        }
    }
    usort($results, static fn (array $a, array $b): int => [$b['score'], $a['title']] <=> [$a['score'], $b['title']]);
    return array_slice($results, 0, HELP_SEARCH_MAX_RESULTS);
}

/**
 * About 200 characters of text around the first match, split into parts so
 * the template can wrap the matches in <mark> (and escape everything).
 * @return list<array{0:string,1:bool}>
 */
function help_snippet(string $text, array $terms): array
{
    $lower = mb_strtolower($text);
    $pos = null;
    foreach ($terms as $t) {
        $p = mb_strpos($lower, $t);
        if ($p !== false && ($pos === null || $p < $pos)) {
            $pos = $p;
        }
    }
    $start = max(0, ($pos ?? 0) - 80);
    $excerpt = mb_substr($text, $start, 200);
    $prefix = $start > 0 ? '…' : '';
    $suffix = $start + 200 < mb_strlen($text) ? '…' : '';
    $pattern = '/(' . implode('|', array_map(static fn (string $t): string => preg_quote($t, '/'), $terms)) . ')/iu';
    $pieces = preg_split($pattern, $excerpt, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$excerpt];
    $parts = [];
    foreach ($pieces as $k => $piece) {
        if ($piece !== '') {
            $parts[] = [($k === 0 ? $prefix : '') . $piece, $k % 2 === 1];
        } elseif ($k === 0 && $prefix !== '') {
            $parts[] = [$prefix, false];
        }
    }
    if ($suffix !== '') {
        $parts[] = [$suffix, false];
    }
    return $parts;
}

/**
 * The guide page (and section) that explains an admin screen, for the
 * contextual "Help" link in each screen's header. Longest prefix wins.
 */
function help_slug_for_admin_path(string $path): ?string
{
    $map = [
        '/admin' => 'manager-guide/first-login',
        '/admin/search' => 'manager-guide/first-login',
        '/admin/profile' => 'manager-guide/first-login#your-own-profile',
        '/admin/pages' => 'manager-guide/page-builder',
        '/admin/templates' => 'manager-guide/page-builder',
        '/admin/menus' => 'manager-guide/header-footer-menus',
        '/admin/layout' => 'manager-guide/header-footer-menus',
        '/admin/media' => 'manager-guide/media-library',
        '/admin/posts' => 'manager-guide/posts',
        '/admin/categories' => 'manager-guide/posts',
        '/admin/events' => 'manager-guide/events',
        '/admin/albums' => 'manager-guide/gallery',
        '/admin/downloads' => 'manager-guide/downloads',
        '/admin/leaders' => 'manager-guide/leaders',
        '/admin/leader-groups' => 'manager-guide/leaders',
        '/admin/awards' => 'manager-guide/awards',
        '/admin/faqs' => 'manager-guide/faqs',
        '/admin/branches' => 'manager-guide/branches-groups',
        '/admin/groups' => 'manager-guide/branches-groups',
        '/admin/members' => 'manager-guide/members',
        '/admin/impersonate' => 'manager-guide/members#log-in-as-a-member',
        '/admin/custom-fields' => 'manager-guide/members#custom-fields',
        '/admin/settings/membership' => 'manager-guide/members#membership-settings',
        '/admin/jobs' => 'manager-guide/jobs',
        '/admin/comments' => 'manager-guide/comments',
        '/admin/notices' => 'manager-guide/notices',
        '/admin/campaigns' => 'manager-guide/campaigns',
        '/admin/email-templates' => 'manager-guide/email-templates',
        '/admin/inbox' => 'manager-guide/inbox',
        '/admin/email-log' => 'manager-guide/email-settings',
        '/admin/email-suppressions' => 'manager-guide/email-settings',
        '/admin/settings/email' => 'manager-guide/email-settings',
        '/admin/radio' => 'manager-guide/radio',
        '/admin/design' => 'manager-guide/design',
        '/admin/settings' => 'manager-guide/settings',
        '/admin/users' => 'manager-guide/users-roles',
        '/admin/audit' => 'manager-guide/audit-log',
        '/admin/backups' => 'manager-guide/backups',
        '/admin/health' => 'manager-guide/system-health',
    ];
    $best = null;
    $bestLen = -1;
    foreach ($map as $prefix => $target) {
        $match = $prefix === '/admin' ? $path === '/admin' : ($path === $prefix || str_starts_with($path, $prefix . '/') || str_starts_with($path, $prefix . '.'));
        if ($match && strlen($prefix) > $bestLen) {
            $best = $target;
            $bestLen = strlen($prefix);
        }
    }
    return $best;
}

/**
 * Put the contextual Help link into an admin screen's header, right after
 * its <h1> (screens without a .page-head, and error pages, get none).
 */
function help_link_insert(string $content, string $path): string
{
    $target = help_slug_for_admin_path($path);
    $head = strpos($content, 'class="page-head');
    if ($target === null || $head === false) {
        return $content;
    }
    $h1 = strpos($content, '</h1>', $head);
    if ($h1 === false) {
        return $content;
    }
    [$slug, $anchor] = array_pad(explode('#', $target, 2), 2, '');
    $title = help_title($slug);
    $link = '<a class="help-link" href="' . e(help_url($slug, 'admin') . ($anchor !== '' ? '#' . $anchor : '')) . '">' . icon('help') . '<span>Help</span><span class="sr-only">: ' . e($title) . '</span></a>';
    return substr($content, 0, $h1 + 5) . $link . substr($content, $h1 + 5);
}

/** Who may read /help: 'public' (default) or 'members'. */
function help_member_guide_access(): string
{
    return setting('help.member_guide', 'public') === 'members' ? 'members' : 'public';
}

/** Rendered guide without its first <h1> (pages that print the title in their own header). */
function help_without_h1(string $html): string
{
    return (string) preg_replace('#^\s*<h1 id="[^"]*">.*?</h1>\s*#s', '', $html, 1);
}

/**
 * Build the standalone documentation website (bin/docs-html): one HTML file
 * per guide with relative links, the screenshots, a stylesheet and
 * .nojekyll for GitHub Pages. $outDir must be a folder inside dist/; it is
 * emptied first. Needs no database or settings.
 * @return array{pages:int,images:int}
 */
function help_export_static(string $outDir): array
{
    $dist = BASE . '/dist';
    if (!is_dir($dist) && !mkdir($dist, 0775, true)) {
        throw new RuntimeException('Cannot create ' . $dist);
    }
    $distReal = (string) realpath($dist);
    $parent = realpath(dirname($outDir));
    if ($parent === false || !str_starts_with($parent . '/', $distReal . '/') || basename($outDir) === '' || str_starts_with(basename($outDir), '.')) {
        throw new InvalidArgumentException('The output folder must be inside dist/.');
    }
    $outDir = $parent . '/' . basename($outDir);
    if (is_link($outDir)) {
        throw new InvalidArgumentException('The output folder is a symbolic link; remove it first.');
    }
    if (is_dir($outDir)) {
        help_remove_tree($outDir);
    }
    mkdir($outDir . '/images', 0775, true);
    $toc = help_toc('static');
    $pages = 0;
    foreach (array_keys(help_manifest()) as $slug) {
        $page = help_render($slug, 'static');
        [$prev, $next] = help_neighbours($slug, 'static');
        $html = view('help/static_page', ['slug' => $slug, 'page' => $page, 'toc' => $toc, 'prev' => $prev, 'next' => $next]);
        $file = $outDir . '/' . help_static_path($slug);
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file, $html);
        $pages++;
    }
    $images = 0;
    foreach (glob(help_dir() . '/images/*') ?: [] as $img) {
        if (help_image_file(basename($img)) !== null) {
            copy($img, $outDir . '/images/' . basename($img));
            $images++;
        }
    }
    copy(BASE . '/templates/help/docs.css', $outDir . '/docs.css');
    file_put_contents($outDir . '/.nojekyll', '');
    return ['pages' => $pages, 'images' => $images];
}

/** Delete a folder created by help_export_static() (no symlinks followed). */
function help_remove_tree(string $dir): void
{
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($dir);
}

/** [the guide's <h1> markup, the rest] so a page can put navigation under the title. */
function help_split_h1(string $html): array
{
    if (preg_match('#^\s*(<h1 id="[^"]*">.*?</h1>)\s*#s', $html, $m)) {
        return [$m[1], substr($html, strlen($m[0]))];
    }
    return ['', $html];
}
