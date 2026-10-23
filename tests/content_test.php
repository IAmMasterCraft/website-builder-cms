<?php
declare(strict_types=1);

function news_cat(): string
{
    return (string) q_val("SELECT id FROM categories WHERE slug = 'news'");
}

/** Minimal valid input for every content type. */
function minimal_inputs(): array
{
    $group = (string) fixture_content('leader-groups', ['name' => 'Committee ' . bin2hex(random_bytes(2))]);
    return [
        'posts' => ['title' => 'A post', 'category_id' => news_cat(), 'status' => 'published', 'tags' => 'one, Two, one'],
        'events' => ['title' => 'An event', 'start_at' => local_in('+3 days'), 'end_at' => local_in('+3 days', '12:00'), 'timezone' => 'Europe/London', 'status' => 'published', 'ticket_name' => ['Adult', ''], 'ticket_price' => ['£5', '']],
        'albums' => ['title' => 'An album', 'album_date' => '2026-05-01', 'status' => 'published'],
        'downloads' => ['title' => 'A download', 'file_media_id' => (string) fixture_pdf_media(), 'category' => 'Forms', 'status' => 'published'],
        'leaders' => ['name' => 'Ada Leader', 'role_title' => 'Chair', 'group_id' => $group, 'status' => 'published', 'social_linkedin' => 'https://www.linkedin.com/in/x'],
        'leader-groups' => ['name' => 'Elders'],
        'awards' => ['recipient' => 'Bola', 'title' => 'Volunteer of the Year', 'year' => '2025', 'status' => 'published'],
        'faqs' => ['question' => 'Who can join?', 'answer' => '<p>Anyone.</p>', 'category' => 'Membership', 'status' => 'published'],
        'branches' => ['name' => 'North branch', 'region' => 'North', 'email' => 'north@example.test', 'status' => 'published'],
        'groups' => ['name' => 'Youth club', 'group_number' => 'GRP-' . random_int(1000, 9999), 'status' => 'active', 'website' => 'https://youth.example'],
        'categories' => ['name' => 'Press releases', 'base' => 'news'],
        'pages' => ['title' => 'About the team', 'body' => '<p>Hello</p>', 'status' => 'published', 'visibility' => 'public'],
    ];
}

test('every content type validates, saves, loads, updates and deletes', function (): void {
    $user = fixture_admin();
    foreach (minimal_inputs() as $key => $input) {
        $type = content_type($key);
        [$data, $errors] = content_validate($type, $input);
        same([], $errors, "$key minimal input");
        $id = content_save($type, $data, $input, null, $user);
        $row = content_find($type, $id);
        ok($row !== null, "$key saved");
        same((string) $input[$type['title']], (string) $row[$type['title']], "$key title");
        $input[$type['title']] .= ' (edited)';
        [$data2, $errors2] = content_validate($type, $input, $row);
        same([], $errors2, "$key update");
        content_save($type, $data2, $input, $id, $user);
        contains('(edited)', (string) content_find($type, $id)[$type['title']], "$key updated");
        if ($key !== 'leader-groups') {
            content_delete($type, $id);
            same(null, content_find($type, $id), "$key deleted");
        }
    }
});

test('required fields and formats are validated per type', function (): void {
    [, $e] = content_validate(content_type('posts'), ['title' => '', 'category_id' => '999999']);
    ok(isset($e['title'], $e['category_id']));
    [, $e] = content_validate(content_type('events'), ['title' => 'E', 'start_at' => '', 'timezone' => 'Europe/London']);
    ok(isset($e['start_at']));
    [, $e] = content_validate(content_type('events'), ['title' => 'E', 'start_at' => local_in('+2 days'), 'end_at' => local_in('+1 day'), 'timezone' => 'Europe/London']);
    ok(isset($e['end_at']), 'end before start rejected');
    [, $e] = content_validate(content_type('downloads'), ['title' => 'D']);
    ok(isset($e['file_media_id']), 'download needs a file');
    [, $e] = content_validate(content_type('downloads'), ['title' => 'D', 'file_media_id' => '99999999']);
    ok(isset($e['file_media_id']), 'unknown media rejected');
    [, $e] = content_validate(content_type('branches'), ['name' => 'B', 'email' => 'nope']);
    ok(isset($e['email']));
    [, $e] = content_validate(content_type('groups'), ['name' => 'G', 'website' => 'javascript:alert(1)']);
    ok(isset($e['website']));
    [, $e] = content_validate(content_type('awards'), ['recipient' => 'R', 'title' => 'T', 'year' => '1800']);
    ok(isset($e['year']));
    [, $e] = content_validate(content_type('leaders'), ['name' => 'L', 'group_id' => '', 'social_x' => 'http://insecure']);
    ok(isset($e['group_id'], $e['social']));
    [, $e] = content_validate(content_type('faqs'), ['question' => 'Q', 'answer' => '<p> </p>']);
    ok(isset($e['answer']));
    [, $e] = content_validate(content_type('albums'), ['title' => 'A', 'album_date' => '2026-13-40']);
    ok(isset($e['album_date']));
});

test('rich text is sanitised when saved (acceptance 12)', function (): void {
    $id = fixture_content('posts', ['title' => 'XSS', 'category_id' => news_cat(), 'body' => '<p>ok<script>alert(1)</script><img src=x onerror=alert(1)></p>']);
    $body = (string) q_val('SELECT body FROM posts WHERE id = ?', [$id]);
    not_contains('<script', $body);
    not_contains('onerror', $body);
    contains('ok', $body);
});

test('slugs are generated, normalised and made unique', function (): void {
    $a = fixture_content('posts', ['title' => 'Same Title!', 'category_id' => news_cat()]);
    $b = fixture_content('posts', ['title' => 'Same Title!', 'category_id' => news_cat()]);
    same('same-title', q_val('SELECT slug FROM posts WHERE id = ?', [$a]));
    same('same-title-2', q_val('SELECT slug FROM posts WHERE id = ?', [$b]));
    $c = fixture_content('posts', ['title' => 'x', 'slug' => 'Custom Slug', 'category_id' => news_cat()]);
    same('custom-slug', q_val('SELECT slug FROM posts WHERE id = ?', [$c]));
});

test('pages cannot use reserved slugs of built-in routes', function (): void {
    [, $e] = content_validate(content_type('pages'), ['title' => 'News', 'status' => 'draft', 'visibility' => 'public']);
    ok(isset($e['slug']));
    [, $e] = content_validate(content_type('pages'), ['title' => 'x', 'slug' => 'admin', 'status' => 'draft', 'visibility' => 'public']);
    ok(isset($e['slug']));
});

test('publishing rules: published/scheduled/draft normalisation', function (): void {
    $now = '2026-10-23 12:00:00';
    same(['published', $now, null], normalise_publish('published', null, $now));
    same(['scheduled', '2026-11-01 09:00:00', null], normalise_publish('published', '2026-11-01 09:00:00', $now));
    same(['published', '2026-10-01 09:00:00', null], normalise_publish('scheduled', '2026-10-01 09:00:00', $now));
    same('Choose the date and time to publish.', normalise_publish('scheduled', null, $now)[2]);
    same(['draft', null, null], normalise_publish('draft', null, $now));
});

test('scheduled content stays hidden until its time, then publish_due publishes it', function (): void {
    $id = fixture_content('posts', ['title' => 'Future news', 'category_id' => news_cat(), 'status' => 'scheduled', 'publish_at' => local_in('+2 days')]);
    same('scheduled', q_val('SELECT status FROM posts WHERE id = ?', [$id]));
    same(null, q_val('SELECT id FROM posts WHERE id = ? AND ' . published_sql(), [$id]), 'not visible yet');
    q('UPDATE posts SET publish_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = ?', [$id]);
    same($id, (int) q_val('SELECT id FROM posts WHERE id = ? AND ' . published_sql(), [$id]), 'visible once due (lazy)');
    ok(publish_due() >= 1);
    same('published', q_val('SELECT status FROM posts WHERE id = ?', [$id]));
});

test('post tags are stored, deduplicated and cleaned up', function (): void {
    $id = fixture_content('posts', ['title' => 'Tagged', 'category_id' => news_cat(), 'tags' => 'Garden, garden , Food']);
    $post = content_find(content_type('posts'), $id);
    same(['food', 'garden'], array_column($post['tag_rows'], 'slug'));
    same(['Garden', 'Food'], parse_tags('Garden, garden , Food'));
    content_save(content_type('posts'), [], ['tags' => 'Food'], $id);
    same(['food'], array_column(content_find(content_type('posts'), $id)['tag_rows'], 'slug'));
});

test('event tickets keep their ids when edited and removed ones are deleted', function (): void {
    $type = content_type('events');
    $id = fixture_content('events', ['title' => 'Tickets', 'start_at' => local_in('+5 days'), 'timezone' => 'Europe/London', 'ticket_name' => ['Adult', 'Child'], 'ticket_price' => ['£5', '£2']]);
    $rows = content_find($type, $id)['ticket_rows'];
    same(['Adult', 'Child'], array_column($rows, 'name'));
    event_save_tickets($id, ['ticket_id' => [(string) $rows[0]['id']], 'ticket_name' => ['Adult (updated)'], 'ticket_price' => ['£6']]);
    $after = content_find($type, $id)['ticket_rows'];
    same(1, count($after));
    same((int) $rows[0]['id'], (int) $after[0]['id']);
    same('£6', $after[0]['price_label']);
});

test('event times are stored in UTC using the event timezone', function (): void {
    $id = fixture_content('events', ['title' => 'NY', 'start_at' => '2027-07-04T18:00', 'timezone' => 'America/New_York']);
    same('2027-07-04 22:00:00', q_val('SELECT start_at FROM events WHERE id = ?', [$id]));
});

test('built-in categories cannot be deleted or have their slug changed', function (): void {
    $type = content_type('categories');
    $news = content_find($type, (int) news_cat());
    throws(DomainException::class, fn () => content_delete($type, (int) $news['id']));
    [$data] = content_validate($type, ['name' => 'Updates', 'slug' => 'updates', 'base' => 'blog'], $news);
    same('news', $data['slug']);
    same('news', $data['base']);
});

test('a category with posts cannot be deleted', function (): void {
    $type = content_type('categories');
    $cat = fixture_content('categories', ['name' => 'Temp cat', 'base' => 'blog']);
    fixture_content('posts', ['title' => 'In temp', 'category_id' => (string) $cat]);
    throws(DomainException::class, fn () => content_delete($type, $cat));
});

test('home page flag is stored as a setting and cleared on delete', function (): void {
    $id = fixture_content('pages', ['title' => 'Welcome home', 'status' => 'published', 'visibility' => 'public', 'is_home' => '1']);
    same($id, (int) setting('home.page_id'));
    same('/', content_public_url('page', ['id' => $id, 'slug' => 'welcome-home']));
    content_delete(content_type('pages'), $id);
    same(null, setting('home.page_id'));
});

test('admin list supports search, filters and paging', function (): void {
    $type = content_type('faqs');
    for ($i = 0; $i < 3; $i++) {
        fixture_content('faqs', ['question' => "Parking question $i", 'answer' => '<p>a</p>', 'category' => 'Venue']);
    }
    same(3, content_admin_list($type, ['q' => 'Parking'])['total']);
    same(3, content_admin_list($type, ['category' => 'Venue'])['total']);
    same(1, count(content_admin_list($type, ['q' => 'Parking'], 2, 2)['rows']));
    same(0, content_admin_list($type, ['q' => '%'])['total'], 'LIKE wildcards escaped');
});

test('admin list cells format status, dates and counts', function (): void {
    $type = content_type('events');
    $id = fixture_content('events', ['title' => 'Cells', 'start_at' => '2027-01-02T10:00', 'timezone' => 'Europe/London', 'status' => 'draft']);
    $cells = admin_list_cells($type, [content_find($type, $id)]);
    same(['draft', 'badge', null], $cells[0]['status']);
    same('2 Jan 2027, 10:00', $cells[0]['start_at'][0]);
    same('/admin/events/' . $id . '/registrations', $cells[0]['registrations'][2]);
});
