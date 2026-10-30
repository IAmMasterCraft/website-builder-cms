<?php
declare(strict_types=1);

// Addendum B3: safe Markdown renderer, help centre, static docs site.
// Addendum B1: admin sidebar groups (nav model).

use Core\Markdown;

function md(string $src, ?callable $link = null, ?callable $image = null): string
{
    return (new Markdown($link ?? static fn (string $u): string => $u, $image))->render($src);
}

test('B3 markdown: no raw HTML passthrough, everything is escaped', function (): void {
    $html = md("<script>alert(1)</script>\n\nHello <b onmouseover=\"x()\">there</b> & \"friends\"\n\n<img src=x onerror=alert(1)>");
    not_contains('<script', $html);
    not_contains('<b ', $html);
    not_contains('<img', $html);
    contains('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    contains('&amp; &quot;friends&quot;', $html);
    $code = md("```html\n<script>bad()</script>\n```");
    contains('<pre tabindex="0"><code class="language-html">&lt;script&gt;bad()&lt;/script&gt;</code></pre>', $code);
    contains('<code>&lt;site&gt;</code>', md('Use `<site>` here'));
    contains('&lt;domain&gt;', md('Type <domain> here'));
});

test('B3 markdown: only http(s), mailto, relative and #anchor links survive', function (): void {
    foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,x', 'vbscript:x', '//evil.example/x', '/\\evil', 'java	script:x', 'file:///etc/passwd'] as $bad) {
        $html = md('[click](' . $bad . ')');
        not_contains('<a ', $html, $bad);
        contains('click', $html);
    }
    contains('<a href="https://example.org/a?b=1&amp;c=2" rel="noopener noreferrer">x</a>', md('[x](https://example.org/a?b=1&c=2)'));
    contains('<a href="mailto:help@example.org">mail</a>', md('[mail](mailto:help@example.org)'));
    contains('<a href="other.md#part">o</a>', md('[o](other.md#part)'));
    contains('<a href="#top-part">t</a>', md('[t](#top-part)'));
    contains('<a href="http://127.0.0.1:8080/install" rel="noopener noreferrer">http://127.0.0.1:8080/install</a>', md('Open <http://127.0.0.1:8080/install> now'));
    // A resolver that refuses a link renders the text only.
    $html = md('[gone](missing.md)', static fn (string $u): ?string => null);
    contains('<span class="md-link-text">gone</span>', $html);
    // A resolver cannot smuggle in a bad scheme either.
    not_contains('href="javascript', md('[x](a.md)', static fn (string $u): string => 'javascript:alert(1)'));
    not_contains('"><', md('[x](a"onclick="b)'));
});

test('B3 markdown: images only through the resolver', function (): void {
    same('<p><span class="md-image-missing">[Shot]</span></p>', md('![Shot](images/a.webp)'), 'no resolver: no image');
    $html = md('![A "quoted" shot](images/a.webp)', null, static fn (string $s): ?string => $s === 'images/a.webp' ? '/admin/help/images/a.webp' : null);
    contains('<img src="/admin/help/images/a.webp" alt="A &quot;quoted&quot; shot" loading="lazy" decoding="async">', $html);
    not_contains('<img', md('![x](javascript:alert(1))', null, static fn (string $s): string => $s));
    not_contains('<img', md('![x](https://tracker.example/p.gif)', null, static fn (string $s): ?string => null));
});

test('B3 markdown: headings get unique GitHub-style ids', function (): void {
    $r = new Markdown(null, null, ['main']);
    $html = $r->render("# How do I…?\n\n## A member's page\n\n## Main\n\n## Notes\n\n## Notes\n\n### Site settings *(super admin)*");
    contains('<h1 id="how-do-i">How do I…?</h1>', $html);
    contains('<h2 id="a-members-page">A member&#039;s page</h2>', $html);
    contains('<h2 id="main-1">Main</h2>', $html, 'ids used by the page layout are skipped');
    contains('<h2 id="notes">', $html);
    contains('<h2 id="notes-1">', $html);
    contains('<h3 id="site-settings-super-admin">Site settings <em>(super admin)</em></h3>', $html);
    same(['how-do-i', 'a-members-page', 'main-1', 'notes', 'notes-1', 'site-settings-super-admin'], array_column($r->headings, 'id'));
});

test('B3 markdown: lists, tables, blockquotes, notes, emphasis', function (): void {
    $html = md("1. **Database**\n   - Token: from step 8.\n   - Host `localhost`, see\n     the next line.\n2. Admin\n\n   More about admin.\n3. Done");
    contains("<ol>\n<li><p><strong>Database</strong></p>\n<ul>\n<li>Token: from step 8.</li>", $html, 'nested tight list inside a loose one');
    contains('<li>Host <code>localhost</code>, see', $html);
    contains('<p>More about admin.</p>', $html, 'loose list keeps paragraphs');
    same("<ul>\n<li>one</li>\n<li>two\n<ul>\n<li>two a</li>\n</ul></li>\n</ul>", md("- one\n- two\n  - two a"), 'tight list');
    $html = md("5. five\n6. six");
    contains('<ol start="5">', $html);
    $t = md("| Left | Mid | Right |\n|:---|:---:|---:|\n| a \\| b | `x|y` | **c** |");
    contains('<th scope="col">Left</th><th class="align-center" scope="col">Mid</th><th class="align-right" scope="col">Right</th>', $t);
    contains('<td>a | b</td><td class="align-center"><code>x|y</code></td><td class="align-right"><strong>c</strong></td>', $t);
    contains('<blockquote><p>Quoted <em>text</em></p></blockquote>', md('> Quoted *text*'));
    contains('<aside class="md-note md-note-warning"><p class="md-note-title">Warning</p><p>Careful.</p></aside>', md("> [!WARNING]\n> Careful."));
    same('<p>snake_case_name and 2*3*4</p>', md('snake_case_name and 2*3*4'));
    contains('<em>it</em> and <strong>bold</strong> and <strong>also</strong>', md('_it_ and **bold** and __also__'));
    contains('<hr>', md("a\n\n---\n\nb"));
    contains("line one<br>\nline two", md("line one  \nline two"));
});

test('B3: the help manifest and the files in docs/ match', function (): void {
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE . '/docs', FilesystemIterator::SKIP_DOTS)) as $f) {
        if (str_ends_with((string) $f, '.md')) {
            $files[] = substr((string) $f, strlen(BASE . '/docs/'));
        }
    }
    sort($files);
    $manifest = array_column(help_manifest(), 'file');
    sort($manifest);
    same($files, $manifest, 'every guide is in help_manifest() and every manifest entry exists');
    same(['member-guide'], help_mode_slugs('public'));
});

test('B3: every link between guides and every image in docs/ resolves', function (): void {
    $broken = [];
    foreach (help_manifest() as $slug => $doc) {
        $src = (string) file_get_contents(BASE . '/docs/' . $doc['file']);
        $page = help_render($slug, 'admin');
        $ids = array_column($page['headings'], 'id');
        preg_match_all('/(!?)\[[^\]]*\]\(([^)\s]+)/', $src, $m, PREG_SET_ORDER);
        foreach ($m as [, $bang, $target]) {
            if (preg_match('#^(https?:|mailto:)#', $target) || help_normalise_path(dirname($doc['file']), explode('#', $target)[0]) === null) {
                continue; // files outside docs/ (nginx.conf.example, BUILD-NOTES.md) are shown as text on purpose
            }
            if ($bang === '!') {
                if (help_resolve_image($target, $slug, 'admin') === null) {
                    $broken[] = "$slug: image $target";
                }
                continue;
            }
            if (str_starts_with($target, '#')) {
                if (!in_array(substr($target, 1), $ids, true)) {
                    $broken[] = "$slug: anchor $target";
                }
                continue;
            }
            $url = help_resolve_link($target, $slug, 'admin');
            if ($url === null && !str_ends_with($target, 'images/')) {
                $broken[] = "$slug: link $target";
                continue;
            }
            if ($url !== null && str_contains($target, '#')) {
                [$file, $anchor] = explode('#', $target, 2);
                $toSlug = help_slug_for_file((string) help_normalise_path(dirname($doc['file']), $file));
                $toIds = array_column(help_render((string) $toSlug, 'admin')['headings'], 'id');
                if (!in_array($anchor, $toIds, true)) {
                    $broken[] = "$slug: anchor $target";
                }
            }
        }
        not_contains('md-image-missing', $page['html'], "$slug has a missing image");
    }
    same([], $broken);
});

test('B3: links are rewritten per mode (admin routes, public /help, static relative files)', function (): void {
    same('/admin/help/manager-guide/members#custom-fields', help_resolve_link('members.md#custom-fields', 'manager-guide/first-login', 'admin'));
    same('/admin/help', help_resolve_link('../README.md', 'manager-guide/members', 'admin'));
    same('/admin/help/member-guide#joining', help_resolve_link('../member-guide.md#joining', 'manager-guide/members', 'admin'));
    same(null, help_resolve_link('../../config.php', 'manager-guide/members', 'admin'), 'outside docs/');
    same(null, help_resolve_link('../nginx.conf.example', 'index', 'admin'));
    same(null, help_resolve_link('manager-guide/members.md', 'member-guide', 'public'), 'the public page links only to itself');
    same('#joining', help_resolve_link('member-guide.md#joining', 'member-guide', 'public'));
    same('manager-guide/members.html#x', help_resolve_link('manager-guide/members.md#x', 'index', 'static'));
    same('../member-guide.html', help_resolve_link('../member-guide.md', 'manager-guide/members', 'static'));
    same('index.html', help_resolve_link('README.md', 'manager-guide/members', 'static'));
    same('../images/admin-members.webp', help_resolve_image('../images/admin-members.webp', 'manager-guide/members', 'static'));
    same('/help/images/member-card.webp', help_resolve_image('images/member-card.webp', 'member-guide', 'public'));
    same(null, help_resolve_image('/etc/passwd', 'member-guide', 'admin'));
    same(null, help_resolve_image('https://example.org/x.png', 'member-guide', 'admin'));
    same('manager-guide/index.html', help_static_path('manager-guide'));
    same('install-aaPanel.html', help_static_path('install-aapanel'));
});

test('B3: doc images are served only by allow-listed name from docs/images', function (): void {
    ok(help_image_file('admin-members.webp') !== null);
    foreach (['../config.php', '..%2Fconfig.php', 'x.svg', 'Admin-Members.webp', '.htaccess', 'admin-members.webp/../../x', "a\0.png", 'nope.webp'] as $bad) {
        same(null, help_image_file($bad), $bad);
    }
});

test('B3: rendered guides are cached in storage/cache and rebuilt when the file changes', function (): void {
    $dir = BASE . '/storage/cache/help';
    foreach (glob($dir . '/admin-*.json') ?: [] as $f) {
        unlink($f);
    }
    $page = help_page('manager-guide/members', 'admin');
    contains('Members', $page['title']);
    $files = glob($dir . '/admin-*.json') ?: [];
    same(1, count($files));
    // A tampered cache file with a matching name is used (it is our own file), so prove reuse:
    $data = json_decode((string) file_get_contents($files[0]), true);
    $data['title'] = 'From cache';
    file_put_contents($files[0], json_encode($data));
    same('From cache', help_page('manager-guide/members', 'admin')['title']);
    touch(BASE . '/docs/manager-guide/members.md', time() + 5);
    clearstatcache();
    same('Members, ID cards, custom fields and import/export', help_page('manager-guide/members', 'admin')['title'], 'new mtime: rebuilt');
    same(1, count(glob($dir . '/admin-*.json') ?: []), 'stale copy removed');
    touch(BASE . '/docs/manager-guide/members.md');
});

test('B3: search finds sections containing every word, ranked, with escaped snippet parts', function (): void {
    $r = help_search('member card', 'admin');
    ok($r !== [], 'results');
    ok(count($r) <= HELP_SEARCH_MAX_RESULTS);
    foreach ($r as $hit) {
        $text = mb_strtolower(implode('', array_column($hit['snippet'], 0)) . ' ' . $hit['heading']);
        ok(isset(help_manifest()[$hit['slug']]));
    }
    ok($r[0]['score'] >= $r[count($r) - 1]['score'], 'sorted by score');
    $marks = array_filter(array_merge(...array_column($r, 'snippet')), static fn (array $p): bool => $p[1]);
    ok($marks !== [], 'matches are marked');
    same([], help_search('zzqxv nothing', 'admin'));
    same([], help_search('a', 'admin'), 'one-letter words are ignored');
    $pub = help_search('card', 'public');
    ok($pub !== [] && array_unique(array_column($pub, 'slug')) === ['member-guide'], 'public search covers the member guide only');
    $imp = help_search('log in as this member', 'admin');
    ok(in_array('manager-guide/members', array_column($imp, 'slug'), true), 'the members guide explains logging in as a member');
});

test('B3: every admin screen has a contextual help page and the anchors exist', function (): void {
    require_once BASE . '/src/routes.php';
    $missing = [];
    foreach (app_routes()->routes() as $route) {
        if ($route['method'] !== 'GET' || !str_starts_with($route['pattern'], '/admin') || str_starts_with($route['pattern'], '/admin/help')) {
            continue;
        }
        $path = (string) preg_replace(['/\{type:[^}]+\}/', '/\{[a-z_]+(?::(?:[^{}]|\{\d+(?:,\d*)?\})+)?\}/i'], ['posts', '1'], $route['pattern']);
        $target = help_slug_for_admin_path($path);
        if ($target === null) {
            $missing[] = $path;
            continue;
        }
        [$slug, $anchor] = array_pad(explode('#', $target, 2), 2, '');
        if (!isset(help_manifest()[$slug])) {
            $missing[] = "$path -> $slug (no such guide)";
        } elseif ($anchor !== '' && !in_array($anchor, array_column(help_render($slug, 'admin')['headings'], 'id'), true)) {
            $missing[] = "$path -> $target (no such section)";
        }
    }
    foreach (array_keys(content_types()) as $type) {
        if (help_slug_for_admin_path('/admin/' . $type) === null) {
            $missing[] = '/admin/' . $type;
        }
    }
    same([], $missing);
    $html = help_link_insert('<div class="page-head"><div><h1>Members</h1><p>x</p></div></div>', '/admin/members');
    contains('<h1>Members</h1><a class="help-link" href="/admin/help/manager-guide/members">', $html);
    same('<p>no header</p>', help_link_insert('<p>no header</p>', '/admin/members'));
});

test('B3: bin/docs-html builds a static site with relative links, images and .nojekyll', function (): void {
    $stats = help_export_static(BASE . '/dist/docs-site-test');
    $out = BASE . '/dist/docs-site-test';
    try {
        same(count(help_manifest()), $stats['pages']);
        ok($stats['images'] > 50);
        ok(is_file($out . '/index.html') && is_file($out . '/manager-guide/index.html') && is_file($out . '/manager-guide/members.html'));
        ok(is_file($out . '/.nojekyll') && is_file($out . '/docs.css') && is_file($out . '/images/admin-members.webp'));
        $html = (string) file_get_contents($out . '/manager-guide/members.html');
        contains('href="../docs.css"', $html);
        contains('src="../images/admin-members.webp"', $html);
        contains('href="../member-guide.html', $html);
        not_contains('/admin/help', $html);
        // Every relative link and image in the site points at a file that exists.
        $bad = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS)) as $f) {
            if (!str_ends_with((string) $f, '.html')) {
                continue;
            }
            preg_match_all('/(?:href|src)="([^"#:]+)(?:#[^"]*)?"/', (string) file_get_contents((string) $f), $m);
            foreach ($m[1] as $rel) {
                if (!is_file(dirname((string) $f) . '/' . $rel)) {
                    $bad[] = basename((string) $f) . ' -> ' . $rel;
                }
            }
        }
        same([], array_slice(array_unique($bad), 0, 10));
        throws(InvalidArgumentException::class, fn () => help_export_static(BASE . '/storage/docs'));
        throws(InvalidArgumentException::class, fn () => help_export_static(BASE . '/dist/../src'));
    } finally {
        help_remove_tree($out);
    }
});

test('B1: sidebar groups follow permissions; empty groups disappear; active parent is the most specific link', function (): void {
    act_as(fixture_admin());
    $all = admin_nav(current_user());
    same(['site', 'content', 'members', 'community', 'messages', 'communications', 'radio', 'system'], array_column($all, 'key'));
    foreach ($all as $g) {
        ok($g['label'] !== '' && $g['icon'] !== '' && $g['links'] !== []);
    }
    act_as(fixture_admin('admin', ['radio']));
    $radio = admin_nav(current_user());
    same(['site', 'messages', 'radio'], array_column($radio, 'key'), 'only Overview (site), own messages (C3) and Radio');
    same([['/admin/messages', 'My messages', 'message']], $radio[1]['links'], 'C3: every admin has their own messages; Team inbox and reports need the permission');
    same([['/admin', 'Overview', 'home']], $radio[0]['links']);
    same(['/admin/radio/playlists', 'radio'], admin_nav_active($radio, '/admin/radio/playlists/4'));
    same(['/admin/radio', 'radio'], admin_nav_active($radio, '/admin/radio/media/2'));
    same(['/admin', 'site'], admin_nav_active($radio, '/admin'));
    same(['', ''], admin_nav_active($radio, '/admin/help'));
    same(['/admin/settings/membership', 'members'], admin_nav_active($all, '/admin/settings/membership'));
    same(['/admin/settings', 'system'], admin_nav_active($all, '/admin/settings'));
    same(['/admin/members', 'members'], admin_nav_active($all, '/admin/impersonate/12'), 'the start screen belongs to Members');
    same(['/admin/members', 'members'], admin_nav_active($all, '/admin/members.csv'));
    act_as(fixture_admin('admin', ['essential_notices', 'impersonate']));
    same(['site', 'messages'], array_column(admin_nav(current_user()), 'key'));
    same(['/admin/messages/team', 'messages'], admin_nav_active($all, '/admin/messages/team/3'));
    same(['/admin/settings/messages', 'messages'], admin_nav_active($all, '/admin/settings/messages'));
});

test('B3 review: /help serves only the screenshots the member guide uses', function (): void {
    $pub = help_public_images();
    ok(in_array('member-card.webp', $pub, true) && in_array('public-join.webp', $pub, true));
    foreach ($pub as $name) {
        ok(!str_starts_with($name, 'admin-'), "no admin screenshot: $name");
        ok(help_image_file($name) !== null, "exists: $name");
    }
    ok(!in_array('admin-members.webp', $pub, true) && !in_array('admin-health-paths.webp', $pub, true));
});

test('B3 review: bin/docs-html refuses an output folder that is a symbolic link', function (): void {
    $target = sys_get_temp_dir() . '/gather-symlink-target-' . bin2hex(random_bytes(3));
    mkdir($target);
    file_put_contents($target . '/keep.txt', 'must survive');
    $link = BASE . '/dist/docs-site-link-test';
    if (!is_dir(BASE . '/dist')) {
        mkdir(BASE . '/dist');
    }
    symlink($target, $link);
    try {
        throws(InvalidArgumentException::class, fn () => help_export_static($link));
        ok(is_file($target . '/keep.txt'), 'the link target was not emptied');
    } finally {
        unlink($link);
        unlink($target . '/keep.txt');
        rmdir($target);
    }
});
