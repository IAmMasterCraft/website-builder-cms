<?php
declare(strict_types=1);

/**
 * Site search (§3.5) over published posts, events, pages and downloads.
 * Uses FULLTEXT (natural language); short or unmatched terms fall back to LIKE.
 * Members see members-only results too.
 * @return list<array{type:string, title:string, url:string, excerpt:string, date:?string, score:float}>
 */
function site_search(string $query, ?array $viewer = null, int $limit = 40): array
{
    $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
    if ($query === '' || mb_strlen($query) > 100) {
        return [];
    }
    $member = viewer_is_member($viewer);
    $sources = [
        'post' => [
            'sql' => 'SELECT p.id, p.title, p.slug, p.summary, p.body, p.publish_at AS date, c.base, %s AS score
                      FROM posts p JOIN categories c ON c.id = p.category_id WHERE %s AND ' . published_sql('p') . ($member ? '' : " AND p.visibility = 'public'"),
            'cols' => 'p.title, p.summary, p.body', 'label' => 'News',
        ],
        'event' => [
            'sql' => 'SELECT e.id, e.title, e.slug, e.summary, e.body, e.start_at AS date, %s AS score
                      FROM events e WHERE %s AND ' . published_sql('e') . ($member ? '' : " AND e.visibility = 'public'"),
            'cols' => 'e.title, e.summary, e.body', 'label' => 'Event',
        ],
        'page' => [
            'sql' => 'SELECT g.id, g.title, g.slug, g.summary, g.body, g.updated_at AS date, %s AS score
                      FROM pages g WHERE %s AND ' . published_sql('g') . ($member ? " AND g.visibility <> 'hidden'" : " AND g.visibility = 'public'"),
            'cols' => 'g.title, g.summary, g.body', 'label' => 'Page',
        ],
        'download' => [
            'sql' => 'SELECT d.id, d.title, d.slug, d.summary, d.body, d.publish_at AS date, %s AS score
                      FROM downloads d WHERE %s AND ' . published_sql('d') . ($member ? '' : " AND d.visibility = 'public'"),
            'cols' => 'd.title, d.summary, d.body', 'label' => 'Download',
        ],
    ];
    $results = [];
    $useFulltext = mb_strlen($query) >= 3;
    foreach ($sources as $type => $src) {
        $rows = [];
        if ($useFulltext) {
            $match = 'MATCH(' . $src['cols'] . ') AGAINST (? IN NATURAL LANGUAGE MODE)';
            $rows = q_all(sprintf($src['sql'], $match, $match) . ' ORDER BY score DESC LIMIT ' . (int) $limit, [$query, $query]);
            $rows = array_values(array_filter($rows, static fn (array $r): bool => (float) $r['score'] > 0));
        }
        if ($rows === []) {
            $like = '%' . addcslashes($query, '%_\\') . '%';
            $first = explode(',', $src['cols'])[0];
            $cond = '(' . implode(' OR ', array_map(static fn (string $c): string => trim($c) . ' LIKE ?', explode(',', $src['cols']))) . ')';
            $rows = q_all(sprintf($src['sql'], 'IF(' . $first . ' LIKE ?, 2, 1)', $cond) . ' LIMIT ' . (int) $limit, [$like, $like, $like, $like]);
        }
        foreach ($rows as $r) {
            $results[] = [
                'type' => $src['label'],
                'title' => (string) $r['title'],
                'url' => match ($type) {
                    'post' => post_url($r),
                    'event' => event_url($r),
                    'page' => content_public_url('page', $r),
                    default => '/downloads#download-' . $r['id'],
                },
                'excerpt' => excerpt((string) ($r['summary'] ?: $r['body']), 200),
                'date' => $r['date'],
                'score' => (float) $r['score'],
            ];
        }
    }
    usort($results, static fn (array $a, array $b): int => [$b['score'], (string) $b['date']] <=> [$a['score'], (string) $a['date']]);
    return array_slice($results, 0, $limit);
}
