<?php
declare(strict_types=1);

test('archive ranges follow the site timezone (F5 filters)', function (): void {
    $tz = new DateTimeZone('Europe/London');
    $now = new DateTimeImmutable('2026-07-15 23:30:00', new DateTimeZone('UTC')); // 00:30 on 16 July in London
    same(['from' => '2026-07-15 23:00:00', 'to' => '2026-07-16 23:00:00'], archive_range('today', $now, $tz));
    same(['from' => '2026-06-30 23:00:00', 'to' => '2026-07-31 23:00:00'], archive_range('month', $now, $tz));
    same(['from' => '2026-01-01 00:00:00', 'to' => '2027-01-01 00:00:00'], archive_range('year', $now, $tz));
    same(['from' => null, 'to' => '2026-01-01 00:00:00'], archive_range('older', $now, $tz));
    same(null, archive_range('bogus', $now, $tz));
});

test('post list filters by archive range, category, tag and visibility', function (): void {
    $cat = (string) q_val("SELECT id FROM categories WHERE slug = 'blog'");
    $sub = (string) fixture_content('categories', ['name' => 'Recipes', 'base' => 'blog']);
    $now = new DateTimeImmutable('2026-06-15 12:00:00', new DateTimeZone('UTC'));
    $mk = function (string $title, string $utc, string $category, string $vis = 'public', string $tags = '') {
        $id = fixture_content('posts', ['title' => $title, 'category_id' => $category, 'status' => 'published', 'visibility' => $vis, 'tags' => $tags]);
        q('UPDATE posts SET publish_at = ? WHERE id = ?', [$utc, $id]);
    };
    q("DELETE p FROM posts p JOIN categories c ON c.id = p.category_id WHERE c.base = 'blog'");
    $mk('Today post', '2026-06-15 08:00:00', $cat, 'public', 'soup');
    $mk('Month post', '2026-06-02 08:00:00', $sub, 'public', 'soup, bread');
    $mk('Year post', '2026-02-02 08:00:00', $cat);
    $mk('Old post', '2025-11-02 08:00:00', $cat);
    $mk('Members post', '2026-06-14 08:00:00', $cat, 'members');
    $count = fn (array $f, ?array $viewer = null): int => posts_public('blog', $f, 50, 0, $viewer, $now)['total'];
    same(4, $count([]), 'members-only hidden from visitors');
    same(5, $count([], ['id' => 1, 'role' => 'member', 'status' => 'active']), 'members see everything');
    same(1, $count(['when' => 'today']));
    same(2, $count(['when' => 'month']));
    same(3, $count(['when' => 'year']));
    same(1, $count(['when' => 'older']));
    same(1, $count(['category' => 'recipes']));
    same(2, $count(['tag' => 'soup']));
    same(1, $count(['tag' => 'soup', 'when' => 'today']));
    $rows = posts_public('blog', [], 50, 0, null, $now)['rows'];
    same('Today post', $rows[0]['title'], 'newest first');
    same('/blog/today-post', post_url($rows[0]));
});

test('posts never show up under another base or before publishing', function (): void {
    $news = (string) q_val("SELECT id FROM categories WHERE slug = 'news'");
    fixture_content('posts', ['title' => 'Draft news', 'category_id' => $news, 'status' => 'draft']);
    $titles = array_column(posts_public('projects', [], 100, 0)['rows'], 'title');
    ok(!in_array('Draft news', $titles, true));
    $titles = array_column(posts_public('news', [], 100, 0)['rows'], 'title');
    ok(!in_array('Draft news', $titles, true));
});
