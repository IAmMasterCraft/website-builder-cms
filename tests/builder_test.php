<?php
declare(strict_types=1);

// Phase 3: page builder sections, schema validation, revisions, optimistic
// lock, templates, design tokens and contrast, footer widgets, form guard.

use Core\ConflictException;

function builder_fixture_page(?int $userId = null, array $extra = []): int
{
    return fixture_content('pages', $extra + ['title' => 'Builder page ' . bin2hex(random_bytes(3)), 'status' => 'published', 'visibility' => 'public'], $userId);
}

test('every section type renders with its default settings', function (): void {
    act_as(null);
    $sections = array_map('builder_new_section', array_keys(builder_section_types()));
    same(29, count($sections), 'section types from the spec (21) plus the eight A6 design sections');
    foreach ($sections as $s) {
        [$clean, $errors] = builder_validate_settings($s['type'], $s['settings']);
        same([], $errors, 'defaults of ' . $s['type'] . ' validate');
    }
    $html = builder_render($sections, ['viewer' => null, 'page' => null, 'preview' => true]);
    foreach (array_keys(builder_section_types()) as $type) {
        contains('blk-' . str_replace('_', '-', $type), $html, "rendered $type");
    }
    contains('data-section-id=', $html, 'preview marks sections');
    contains('<h1 class="hero-title"', $html, 'first hero supplies the h1');
    $public = builder_render($sections, ['viewer' => null, 'page' => null, 'preview' => false]);
    not_contains('data-section-id', $public, 'public render has no editor markers');
    contains('Off air', $public, 'radio placeholder until phase 6');
});

test('hidden sections are skipped for visitors and dimmed in the preview', function (): void {
    $a = builder_new_section('cta');
    $a['settings']['heading'] = 'Visible band';
    $b = builder_new_section('cta');
    $b['settings']['heading'] = 'Secret band';
    $b['hidden'] = true;
    $public = builder_render([$a, $b], ['viewer' => null, 'preview' => false]);
    contains('Visible band', $public);
    not_contains('Secret band', $public);
    $preview = builder_render([$a, $b], ['viewer' => null, 'preview' => true]);
    contains('is-hidden-preview', $preview);
});

test('section schema validation rejects bad values', function (): void {
    [$s, $e] = builder_validate_settings('hero', [
        'heading' => '', 'button1_url' => 'javascript:alert(1)', 'layout' => 'sideways',
        'slides' => array_fill(0, 8, ['media_id' => 999999, 'caption' => 'x']), 'text' => str_repeat('a', 401),
    ]);
    ok(isset($e['heading']), 'required heading');
    ok(isset($e['button1_url']), 'javascript: url rejected');
    same('', $s['button1_url']);
    ok(isset($e['layout']), 'unknown select option');
    same('fullscreen', $s['layout'], 'falls back to the default');
    ok(isset($e['slides']), 'repeater max 6');
    same(6, count($s['slides']));
    ok(isset($e['slides.0.media_id']), 'missing media reported with its path');
    ok(isset($e['text']), 'max length');
    [$s, $e] = builder_validate_settings('latest_posts', ['count' => '99', 'category' => 'all', 'layout' => 'grid']);
    ok(isset($e['count']));
    same(12, $s['count'], 'clamped to max');
    [$s, $e] = builder_validate_settings('features', ['cards' => []]);
    ok(isset($e['cards']), 'repeater min');
    [$s, $e] = builder_validate_settings('rich_text', ['body' => '<p onclick="x()">Hi<script>alert(1)</script></p>', 'sidebar' => 'none']);
    same([], $e);
    not_contains('<script', $s['body']);
    not_contains('onclick', $s['body']);
    [$s, $e] = builder_validate_settings('text_image', ['button_url' => '/about', 'image_position' => 'left']);
    same([], $e);
    same('/about', $s['button_url'], 'relative links allowed');
    // show_if: the album choice only matters when the source is one album.
    [, $e] = builder_validate_settings('donate', ['use_site_details' => '1', 'links' => []]);
    same([], $e);
});

test('section lists: unknown types dropped, ids repaired, style validated', function (): void {
    $img = fixture_image_media();
    $private = fixture_image_media(null, 'private');
    [$list, $errors] = builder_validate_sections([
        ['id' => 'sabcdef1', 'type' => 'spacer', 'settings' => [], 'style' => ['background' => 'image', 'bg_media_id' => $img, 'spacing' => 'roomy', 'width' => 'full', 'anchor' => 'gap']],
        ['id' => 'sabcdef1', 'type' => 'spacer', 'settings' => [], 'style' => ['background' => 'image', 'bg_media_id' => $private]],
        ['id' => 'x', 'type' => 'evil', 'settings' => []],
        ['id' => 'bad id!', 'type' => 'cta', 'hidden' => '1', 'style' => ['spacing' => 'huge', 'anchor' => 'Not Valid']],
        'junk',
    ]);
    same(3, count($list), 'unknown type and junk dropped');
    same('sabcdef1', $list[0]['id']);
    ok($list[1]['id'] !== 'sabcdef1' && preg_match('/^s[a-f0-9]{10}$/', $list[1]['id']) === 1, 'duplicate id replaced');
    ok(preg_match('/^s[a-f0-9]{10}$/', $list[2]['id']) === 1, 'invalid id replaced');
    same($img, $list[0]['style']['bg_media_id']);
    same('full', $list[0]['style']['width']);
    ok(isset($errors[$list[1]['id']]['style.bg_media_id']), 'private media cannot be a public background');
    ok(isset($errors[$list[2]['id']]['style.spacing']));
    ok(isset($errors[$list[2]['id']]['style.anchor']));
    same(true, $list[2]['hidden']);
    [, $errors] = builder_validate_sections('nope');
    ok(isset($errors['_page']));
});

test('embeds resolve only from the allow-list', function (): void {
    same('https://www.youtube-nocookie.com/embed/abcdefghijk', embed_resolve('https://www.youtube.com/watch?v=abcdefghijk&t=3')['src']);
    same('https://www.youtube-nocookie.com/embed/abcdefghijk', embed_resolve('https://youtu.be/abcdefghijk')['src']);
    same('https://player.vimeo.com/video/123456789?dnt=1', embed_resolve('https://vimeo.com/123456789')['src']);
    contains('https://www.google.com/maps?q=', embed_resolve('https://www.google.com/maps/place/Quay+Street')['src']);
    contains('/maps/embed?pb=', embed_resolve('https://www.google.com/maps/embed?pb=!1m18!2d-0.1')['src']);
    contains('facebook.com/plugins/page.php', embed_resolve('https://www.facebook.com/harbourside')['src']);
    same('link', embed_resolve('https://x.com/harbourside')['kind'], 'X needs its script: link card');
    same(null, embed_resolve('https://evil.example/embed/abc'));
    same(null, embed_resolve('http://www.youtube.com/watch?v=abcdefghijk'), 'https only');
    same(null, embed_resolve('javascript:alert(1)'));
    same(null, embed_resolve('https://www.youtube.com/watch?v=abc"onload'));
});

test('autosave keeps one draft revision per editing session; publish makes it live', function (): void {
    $admin = fixture_admin();
    act_as($admin);
    $pageId = builder_fixture_page($admin);
    $page = q_one('SELECT * FROM pages WHERE id = ?', [$pageId]);
    $cta = builder_new_section('cta');
    $cta['settings']['heading'] = 'First draft';
    $r1 = builder_save_draft($pageId, [$cta], (int) $page['revision'], $admin);
    same((int) $page['revision'] + 1, $r1);
    $cta['settings']['heading'] = 'Second draft';
    $r2 = builder_save_draft($pageId, [$cta], $r1, $admin);
    same(1, (int) q_val('SELECT COUNT(*) FROM page_revisions WHERE page_id = ?', [$pageId]), 'same session updates one revision');
    $page = q_one('SELECT * FROM pages WHERE id = ?', [$pageId]);
    same(null, $page['sections'], 'drafts are not live');
    ok(builder_has_unpublished($page));
    same('Second draft', builder_draft_sections($page)[0]['settings']['heading']);
    $r3 = builder_publish($pageId, $r2, $admin);
    $page = q_one('SELECT * FROM pages WHERE id = ?', [$pageId]);
    same($r3, (int) $page['revision']);
    same('Second draft', builder_decode($page['sections'])[0]['settings']['heading']);
    same(null, $page['draft_revision_id']);
    ok(!builder_has_unpublished($page));
    contains('Second draft', (string) $page['body'], 'search text stored on publish');
    // After publishing, the next edit starts a new revision.
    $cta['settings']['heading'] = 'Third';
    builder_save_draft($pageId, [$cta], $r3, $admin);
    same(2, (int) q_val('SELECT COUNT(*) FROM page_revisions WHERE page_id = ?', [$pageId]));
});

test('restoring a revision creates a new draft with the old sections', function (): void {
    $admin = fixture_admin();
    act_as($admin);
    $pageId = builder_fixture_page($admin);
    $rev = (int) q_val('SELECT revision FROM pages WHERE id = ?', [$pageId]);
    $s = builder_new_section('rich_text');
    $s['settings']['body'] = '<p>Version A</p>';
    $rev = builder_publish($pageId, builder_save_draft($pageId, [$s], $rev, $admin), $admin);
    $oldId = (int) q_val('SELECT published_revision_id FROM pages WHERE id = ?', [$pageId]);
    $s['settings']['body'] = '<p>Version B</p>';
    $rev = builder_publish($pageId, builder_save_draft($pageId, [$s], $rev, $admin), $admin);
    $rev = builder_restore($pageId, $oldId, $rev, $admin);
    $page = q_one('SELECT * FROM pages WHERE id = ?', [$pageId]);
    contains('Version B', (string) $page['sections'], 'live page unchanged until publish');
    contains('Version A', builder_draft_sections($page)[0]['settings']['body']);
    contains('Restored from', (string) q_val('SELECT note FROM page_revisions WHERE id = ?', [$page['draft_revision_id']]));
    builder_publish($pageId, $rev, $admin);
    contains('Version A', (string) q_val('SELECT sections FROM pages WHERE id = ?', [$pageId]));
    throws(DomainException::class, fn () => builder_restore($pageId, 99999999, (int) q_val('SELECT revision FROM pages WHERE id = ?', [$pageId]), $admin));
});

test('only the last 20 revisions are kept (plus the live one)', function (): void {
    $admin = fixture_admin();
    act_as($admin);
    $pageId = builder_fixture_page($admin);
    $rev = (int) q_val('SELECT revision FROM pages WHERE id = ?', [$pageId]);
    $s = builder_new_section('spacer');
    $rev = builder_publish($pageId, $rev, $admin);
    $liveId = (int) q_val('SELECT published_revision_id FROM pages WHERE id = ?', [$pageId]);
    for ($i = 0; $i < 24; $i++) {
        $rev = builder_save_draft($pageId, [$s], $rev, $admin);
        q("UPDATE page_revisions SET note = 'x' WHERE id = (SELECT draft_revision_id FROM pages WHERE id = ?)", [$pageId]); // force a new revision each time
    }
    $count = (int) q_val('SELECT COUNT(*) FROM page_revisions WHERE page_id = ?', [$pageId]);
    same(21, $count, '20 newest + the live revision');
    ok(q_val('SELECT id FROM page_revisions WHERE id = ?', [$liveId]) !== null, 'live revision never pruned');
    same(20, count(builder_revisions(q_one('SELECT * FROM pages WHERE id = ?', [$pageId]))));
});

test('optimistic lock: a stale editor gets "edited by X at hh:mm"', function (): void {
    $alice = fixture_admin();
    $bob = fixture_admin();
    q("UPDATE users SET display_name = 'Bob Builder' WHERE id = ?", [$bob]);
    act_as($alice);
    $pageId = builder_fixture_page($alice);
    $base = (int) q_val('SELECT revision FROM pages WHERE id = ?', [$pageId]);
    builder_save_draft($pageId, [builder_new_section('cta')], $base, $bob);
    $e = throws(ConflictException::class, fn () => builder_save_draft($pageId, [builder_new_section('spacer')], $base, $alice));
    same('Bob Builder', $e->by);
    ok((bool) preg_match('/edited by Bob Builder at \d\d:\d\d/', $e->describe()), $e->describe());
    throws(ConflictException::class, fn () => builder_publish($pageId, $base, $alice));
    same('cta', builder_draft_sections(q_one('SELECT * FROM pages WHERE id = ?', [$pageId]))[0]['type'], 'Bob\'s draft kept');
});

test('starter page templates validate and new pages can start from them', function (): void {
    $starters = builder_starter_templates();
    same(['home', 'about', 'contact', 'leaders', 'gallery', 'events', 'membership'], array_keys($starters));
    foreach ($starters as $k => $t) {
        [$clean, $errors] = builder_validate_sections($t['sections']);
        same([], $errors, "starter $k");
        same(count($t['sections']), count($clean));
    }
    $admin = fixture_admin();
    act_as($admin);
    $id = fixture_content('pages', ['title' => 'From starter', 'status' => 'draft', 'visibility' => 'public', 'start_template' => 'starter:events'], $admin);
    $page = q_one('SELECT * FROM pages WHERE id = ?', [$id]);
    same('upcoming_events', builder_decode($page['sections'])[0]['type']);
    same('starter:events', $page['template']);
    $tid = builder_save_template('My band', 'section', [builder_new_section('cta')], $admin);
    $pid = builder_save_template('My layout', 'page', builder_decode($page['sections']), $admin);
    ok(array_key_exists('saved:' . $pid, builder_template_choices()));
    ok(!array_key_exists('saved:' . $tid, builder_template_choices()), 'section templates are not page choices');
    $fresh = builder_template_sections('saved:' . $pid);
    same(count(builder_decode($page['sections'])), count($fresh));
    ok($fresh[0]['id'] !== builder_decode($page['sections'])[0]['id'], 'fresh ids');
    throws(InvalidArgumentException::class, fn () => builder_save_template('', 'section', [builder_new_section('cta')], $admin));
    throws(InvalidArgumentException::class, fn () => builder_save_template('Empty', 'section', [], $admin));
});

test('builder page renders for visitors and the page form keeps its search text', function (): void {
    $admin = fixture_admin();
    act_as($admin);
    $id = fixture_content('pages', ['title' => 'Rendered page', 'status' => 'published', 'visibility' => 'public', 'start_template' => 'starter:about'], $admin);
    $type = content_type('pages');
    $row = content_find($type, $id);
    $before = (string) $row['body'];
    ok($before !== '');
    [$data] = content_validate($type, ['title' => 'Rendered page', 'slug' => 'rendered-page', 'status' => 'published', 'visibility' => 'public', 'body' => ''], $row);
    ok(!array_key_exists('body', $data), 'details form does not wipe builder search text');
    act_as(null);
    $_SERVER['REQUEST_URI'] = '/rendered-page';
    ob_start();
    Site\Pages::renderPage(q_one('SELECT * FROM pages WHERE id = ?', [$id]), false);
    $html = (string) ob_get_clean();
    contains('blk-text-image', $html);
    contains('<h1>Rendered page</h1>', $html, 'page hero supplies the h1 when there is no hero section');
});

test('WCAG contrast maths', function (): void {
    same(21.0, round(contrast_ratio('#ffffff', '#000000'), 2));
    same(1.0, round(contrast_ratio('#777777', '#777777'), 2));
    same(4.48, round(contrast_ratio('#777777', '#ffffff'), 2));
    same('#ffffff', best_ink('#12355b'));
    same('#111111', best_ink('#f2c94c'));
    ok(contrast_ratio(ensure_contrast('#f2c94c', '#ffffff'), '#ffffff') >= 4.5);
    same('#808080', color_mix('#000000', '#ffffff', 0.5));
    throws(InvalidArgumentException::class, fn () => hex_to_rgb('red'));
});

test('design tokens come from the theme and the admin overrides', function (): void {
    same(['civic', 'bold', 'harvest', 'heritage', 'monochrome'], array_keys(theme_list()));
    foreach (array_keys(theme_list()) as $key) {
        $d = theme_design_defaults($key);
        $report = design_contrast_report($d);
        same([], array_values(array_filter($report, static fn (array $r): bool => !$r['ok'])), "$key defaults pass AA");
        ok(is_file(BASE . "/themes/$key/tokens.css") && is_file(BASE . "/themes/$key/overrides.css"));
    }
    setting_set('site.theme', 'heritage');
    setting_set('design', ['accent' => '#f2c94c', 'radius' => 'sharp', 'button' => 'square', 'heading_font' => 'playfair-display', 'brand' => 'bogus']);
    $d = design_effective();
    same('#6b2f1b', $d['brand'], 'invalid override ignored');
    same('#f2c94c', $d['accent']);
    $t = design_tokens($d);
    same('#111111', $t['--c-accent-ink']);
    same('0px', $t['--button-radius']);
    contains('Playfair Display Variable', $t['--font-heading']);
    ok(contrast_ratio($t['--c-link'], '#fffbf5') >= 4.5, 'links darkened to stay readable');
    $failing = array_filter(design_contrast_report($d), static fn (array $r): bool => !$r['ok']);
    same(['Accent colour as link text on the page'], array_values(array_column($failing, 'label')));
    $css = theme_css();
    contains('@font-face{font-family:"Fraunces Variable"', $css);
    contains('--c-accent: #f2c94c;', $css);
    contains('/* theme: heritage */', $css);
    contains('&plain=1', theme_css_url('civic', false));
    [, $errors] = design_validate(['brand' => '#12', 'accent' => '#0b7577', 'heading_font' => 'comic', 'body_font' => 'inter', 'radius' => 'soft', 'button' => 'pill']);
    same(['brand', 'heading_font'], array_keys($errors));
    setting_set('site.theme', 'civic');
    setting_set('design', []);
});

test('birthday celebrants list only shows members who opted in, without the year', function (): void {
    $now = new DateTimeImmutable('2027-02-28 09:00', new DateTimeZone('Europe/London'));
    $in = fixture_member();
    $out = fixture_member();
    $leap = fixture_member();
    db_insert('members', ['user_id' => $in, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'birth_day' => 28, 'birth_month' => 2, 'birth_year' => 1990, 'prefs' => json_encode(['celebrants' => true])]);
    db_insert('members', ['user_id' => $out, 'first_name' => 'Hidden', 'last_name' => 'Person', 'birth_day' => 28, 'birth_month' => 2, 'prefs' => json_encode(['celebrants' => false])]);
    db_insert('members', ['user_id' => $leap, 'first_name' => 'Leo', 'last_name' => 'Leap', 'birth_day' => 29, 'birth_month' => 2, 'prefs' => json_encode(['celebrants' => true])]);
    $names = array_column(birthday_celebrants('today', $now), 'name');
    ok(in_array('Ada L.', $names, true));
    ok(in_array('Leo L.', $names, true), '29 Feb celebrated on 28 Feb in non-leap years');
    ok(!in_array('Hidden P.', $names, true));
    $html = Sections\Birthdays::render(Sections\Birthdays::defaults(), ['now' => $now]);
    contains('Ada L.', $html);
    not_contains('1990', $html);
    same(8, count(birthday_days('week', $now)), '7 days + 29 Feb');
});

test('footer widgets: defaults, saved columns and empty widgets skipped', function (): void {
    setting_set('footer.columns', null);
    same(['about', 'menu', 'menu', 'contact'], array_column(footer_columns(), 'widget'));
    setting_set('footer.columns', [['widget' => 'join', 'title' => '', 'menu' => 'footer_1', 'text' => ''], ['widget' => 'bogus'], ['widget' => 'menu', 'menu' => 'footer_4', 'title' => '', 'text' => '']]);
    same(['join', 'menu'], array_column(footer_columns(), 'widget'));
    $blocks = footer_blocks('/', []);
    same(['join'], array_column($blocks, 'widget'), 'empty menu skipped');
    same('Become a member', $blocks[0]['title']);
    setting_set('footer.columns', null);
});

test('public form guard: honeypot, timing and signature', function (): void {
    $now = 1_800_000_000;
    preg_match('/name="form_ts" value="([^"]+)"/', form_guard_fields('contact', $now - 10), $m);
    $token = html_entity_decode($m[1]);
    same(null, form_guard_problem('contact', ['form_ts' => $token], $now));
    same('honeypot', form_guard_problem('contact', ['form_ts' => $token, FORM_HONEYPOT => 'spam'], $now));
    same('too-fast', form_guard_problem('contact', ['form_ts' => $token], $now - 9));
    same('stale', form_guard_problem('contact', ['form_ts' => $token], $now + FORM_MAX_SECONDS));
    same('bad-time', form_guard_problem('join', ['form_ts' => $token], $now), 'token is bound to its form');
    same('missing-time', form_guard_problem('contact', [], $now));
    same('bad-time', form_guard_problem('contact', ['form_ts' => ($now - 100) . '.' . substr($token, -32)], $now));
});
