<?php
declare(strict_types=1);

namespace Site;

final class Posts
{
    public const BASES = [
        'news' => ['News', 'Updates and announcements from across the organisation.'],
        'blog' => ['Blog', 'Stories, reflections and ideas from our members and volunteers.'],
        'projects' => ['Projects', 'The work we are doing together in the community.'],
    ];

    public static function index_news(array $p): void { self::index('news'); }
    public static function index_blog(array $p): void { self::index('blog'); }
    public static function index_projects(array $p): void { self::index('projects'); }
    public static function show_news(array $p): void { self::show('news', $p['slug']); }
    public static function show_blog(array $p): void { self::show('blog', $p['slug']); }
    public static function show_projects(array $p): void { self::show('projects', $p['slug']); }

    private static function index(string $base): void
    {
        $viewer = current_user();
        $when = query_param('when');
        $when = array_key_exists($when, ARCHIVE_FILTERS) ? $when : '';
        $category = slugify(query_param('category'));
        $tag = slugify(query_param('tag'));
        $pg = paging(9);
        $result = posts_public($base, ['when' => $when, 'category' => $category, 'tag' => $tag], $pg['per'], $pg['offset'], $viewer);
        media_preload(array_column($result['rows'], 'cover_media_id'));
        engagement_preload('post', array_map('intval', array_column($result['rows'], 'id')));
        $categories = [];
        foreach (q_all('SELECT name, slug FROM categories WHERE base = ? AND is_base = 0 ORDER BY sort, name', [$base]) as $c) {
            $categories[$c['slug']] = $c['name'];
        }
        $tagName = $tag !== '' ? (string) q_val('SELECT name FROM tags WHERE slug = ?', [$tag]) : '';
        render('site/posts_index', [
            'title' => self::BASES[$base][0],
            'base' => $base,
            'heading' => self::BASES[$base][0],
            'intro' => self::BASES[$base][1],
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $pg['page'],
            'per' => $pg['per'],
            'when' => $when,
            'category' => $category,
            'categories' => $categories,
            'tag' => $tag,
            'tag_name' => $tagName,
            'query' => array_filter(['when' => $when, 'category' => $category, 'tag' => $tag]),
            'canonical' => '/' . $base,
        ]);
    }

    private static function show(string $base, string $slug): void
    {
        $viewer = current_user();
        $row = q_one(
            'SELECT p.*, c.name AS category_name, c.slug AS category_slug, c.base FROM posts p JOIN categories c ON c.id = p.category_id
             WHERE p.slug = ? AND ' . published_sql('p'),
            [$slug]
        );
        if ($row === null && is_staff($viewer)) {
            // Staff can preview drafts and scheduled posts.
            $row = q_one('SELECT p.*, c.name AS category_name, c.slug AS category_slug, c.base FROM posts p JOIN categories c ON c.id = p.category_id WHERE p.slug = ?', [$slug]);
        }
        if ($row === null) {
            abort(404);
        }
        if ($row['base'] !== $base) {
            redirect(post_url($row), 301);
        }
        $locked = $row['visibility'] === 'members' && !viewer_is_member($viewer);
        $post = post_load_extra($row);
        $related = q_all(
            'SELECT p.*, c.name AS category_name, c.slug AS category_slug, c.base FROM posts p JOIN categories c ON c.id = p.category_id
             WHERE c.base = ? AND p.id <> ? AND ' . published_sql('p') . ' AND ' . visibility_sql('p', $viewer) . ' ORDER BY p.publish_at DESC LIMIT 4',
            [$base, $row['id']]
        );
        $cover = media_find($row['cover_media_id'] ? (int) $row['cover_media_id'] : null);
        engagement_preload('post', array_merge([(int) $row['id']], array_map('intval', array_column($related, 'id'))));
        $commentError = $_SESSION['comment_error'] ?? null;
        unset($_SESSION['comment_error']);
        render('site/post_show', [
            'viewer' => $viewer,
            'thread' => !$locked && (int) $row['allow_comments'] && viewer_is_member($viewer) ? comment_thread('post', (int) $row['id'], $viewer) : [],
            'comment_error' => is_array($commentError) ? $commentError : null,
            'title' => $row['seo_title'] ?: $row['title'],
            'meta_description' => $row['seo_description'] ?: excerpt($row['summary'] ?: $row['body'], 300),
            'post' => $post,
            'locked' => $locked,
            'cover' => $cover,
            'related' => $related,
            'share_url' => base_url(post_url($row)),
            'canonical' => post_url($row),
            'og_image' => $cover ? media_url($cover, 1600) : null,
            'is_preview' => $row['status'] !== 'published' && !($row['status'] === 'scheduled' && $row['publish_at'] <= utc_now()),
            'base_label' => self::BASES[$base][0],
        ]);
    }
}
