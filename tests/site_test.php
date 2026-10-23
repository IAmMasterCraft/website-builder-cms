<?php
declare(strict_types=1);

test('search finds published public content; members also see members-only', function (): void {
    $cat = (string) q_val("SELECT id FROM categories WHERE slug = 'news'");
    fixture_content('posts', ['title' => 'Lantern parade announced', 'category_id' => $cat, 'status' => 'published', 'body' => '<p>Bring a lantern</p>']);
    fixture_content('posts', ['title' => 'Lantern workshop for members', 'category_id' => $cat, 'status' => 'published', 'visibility' => 'members']);
    fixture_content('posts', ['title' => 'Lantern draft', 'category_id' => $cat, 'status' => 'draft']);
    fixture_content('events', ['title' => 'Lantern making evening', 'start_at' => local_in('+3 days'), 'timezone' => 'Europe/London', 'status' => 'published']);
    fixture_content('pages', ['title' => 'Hidden lantern page', 'status' => 'published', 'visibility' => 'hidden']);
    $visitor = array_column(site_search('lantern'), 'title');
    ok(in_array('Lantern parade announced', $visitor, true));
    ok(in_array('Lantern making evening', $visitor, true));
    ok(!in_array('Lantern workshop for members', $visitor, true));
    ok(!in_array('Lantern draft', $visitor, true));
    ok(!in_array('Hidden lantern page', $visitor, true));
    $member = array_column(site_search('lantern', ['id' => 1, 'role' => 'member', 'status' => 'active']), 'title');
    ok(in_array('Lantern workshop for members', $member, true));
    same([], site_search(''));
    ok(count(site_search('la')) >= 1, 'short terms fall back to LIKE');
});

test('RSS feed lists public published posts as valid XML', function (): void {
    $cat = (string) q_val("SELECT id FROM categories WHERE slug = 'news'");
    fixture_content('posts', ['title' => 'Feed item & more', 'category_id' => $cat, 'status' => 'published']);
    $posts = q_all("SELECT p.*, c.name AS category_name, c.base FROM posts p JOIN categories c ON c.id = p.category_id WHERE " . published_sql('p') . " AND p.visibility = 'public' ORDER BY p.publish_at DESC LIMIT 20");
    $xml = rss_feed($posts, 'Site', 'Tagline');
    $doc = simplexml_load_string($xml);
    ok($doc !== false, 'parses as XML');
    contains('Feed item &amp; more', $xml);
    not_contains('Lantern workshop for members', $xml);
});

test('sitemap includes public pages and content, excludes members-only and hidden', function (): void {
    fixture_content('pages', ['title' => 'Members area page', 'status' => 'published', 'visibility' => 'members']);
    fixture_content('pages', ['title' => 'Public info page', 'status' => 'published', 'visibility' => 'public']);
    $locs = array_column(sitemap_urls(), 'loc');
    ok(in_array('/public-info-page', $locs, true));
    ok(!in_array('/members-area-page', $locs, true));
    ok(in_array('/events/calendar', $locs, true));
    ok(simplexml_load_string(sitemap_xml(sitemap_urls())) !== false);
});

test('overview counts are real numbers from the database', function (): void {
    $before = Admin\Dashboard::counts();
    fixture_member('active');
    fixture_member('unverified');
    $after = Admin\Dashboard::counts();
    same($before['members_active'] + 1, $after['members_active']);
    same($before['members_unverified'] + 1, $after['members_unverified']);
    same($before['members_new_week'] + 2, $after['members_new_week']);
    db_insert('contact_messages', ['name' => 'N', 'email' => 'n@example.test', 'body' => 'Hi']);
    same($before['messages_unread'] + 1, Admin\Dashboard::counts()['messages_unread']);
});

test('birthdays today include 29 February on 28 February in non-leap years', function (): void {
    $leapling = fixture_member();
    db_insert('members', ['user_id' => $leapling, 'first_name' => 'Leap', 'last_name' => 'Ling', 'birth_day' => 29, 'birth_month' => 2]);
    $names = fn (string $utc): array => array_column(Admin\Dashboard::birthdaysToday(new DateTimeImmutable($utc, new DateTimeZone('UTC'))), 'first_name');
    ok(in_array('Leap', $names('2027-02-28 12:00:00'), true), 'non-leap year: celebrated on 28 Feb');
    ok(!in_array('Leap', $names('2028-02-28 12:00:00'), true), 'leap year: not on 28 Feb');
    ok(in_array('Leap', $names('2028-02-29 12:00:00'), true), 'leap year: on 29 Feb');
});

test('theme list reads theme.json and serves Civic CSS tokens', function (): void {
    ok(isset(theme_list()['civic']));
    same('civic', active_theme());
    contains('--c-brand:', theme_css());
    contains('/theme.css?t=civic', theme_css_url());
});

test('demo content seeds every content type and menus', function (): void {
    foreach (['posts', 'events', 'albums', 'album_photos', 'downloads', 'leaders', 'awards', 'faqs', 'branches', 'groups_orgs', 'pages', 'menus', 'menu_items'] as $t) {
        q('DELETE FROM ' . db_ident($t));
    }
    q('DELETE FROM leader_groups');
    demo_seed(fixture_admin());
    foreach (['posts' => 12, 'events' => 6, 'albums' => 3, 'album_photos' => 24, 'downloads' => 4, 'leaders' => 7, 'awards' => 4, 'faqs' => 6, 'branches' => 3, 'groups_orgs' => 3, 'pages' => 5] as $t => $n) {
        same($n, (int) q_val('SELECT COUNT(*) FROM ' . db_ident($t)), $t);
    }
    ok(count(menu_tree('main')) >= 5);
    settings_all([]);
    same('Harbourside Community Association', setting('site.name'));
});
