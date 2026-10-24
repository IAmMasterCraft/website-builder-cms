<?php
declare(strict_types=1);

namespace Site;

final class Leaders
{
    public static function index(array $p): void
    {
        $groups = q_all(
            'SELECT g.*, (SELECT COUNT(*) FROM leaders l WHERE l.group_id = g.id AND ' . published_sql('l') . ') AS n FROM leader_groups g ORDER BY g.sort, g.name'
        );
        if (count($groups) === 1) {
            redirect('/leaders/' . $groups[0]['slug']);
        }
        $today = (new \DateTimeImmutable('now', site_tz()))->format('Y-m-d');
        $people = [];
        foreach (q_all('SELECT l.* FROM leaders l WHERE ' . published_sql('l') . ' AND (l.term_end IS NULL OR l.term_end >= ?) ORDER BY l.sort, l.name', [$today]) as $l) {
            $people[(int) $l['group_id']][] = $l;
        }
        media_preload(array_merge([], ...array_map(static fn (array $g): array => array_column($g, 'photo_media_id'), array_values($people))));
        render('site/leaders_index', ['title' => 'Leaders', 'groups' => $groups, 'people' => $people, 'canonical' => '/leaders']);
    }

    public static function group(array $p): void
    {
        $group = q_one('SELECT * FROM leader_groups WHERE slug = ?', [$p['group']]);
        if ($group === null) {
            abort(404);
        }
        $past = query_param('term') === 'past';
        $today = (new \DateTimeImmutable('now', site_tz()))->format('Y-m-d');
        $cond = $past ? 'l.term_end IS NOT NULL AND l.term_end < ?' : '(l.term_end IS NULL OR l.term_end >= ?)';
        $leaders = q_all('SELECT l.* FROM leaders l WHERE l.group_id = ? AND ' . published_sql('l') . " AND $cond ORDER BY " . ($past ? 'l.term_end DESC, l.sort' : 'l.sort, l.name'), [$group['id'], $today]);
        $pastCount = (int) q_val('SELECT COUNT(*) FROM leaders l WHERE l.group_id = ? AND ' . published_sql('l') . ' AND l.term_end IS NOT NULL AND l.term_end < ?', [$group['id'], $today]);
        media_preload(array_column($leaders, 'photo_media_id'));
        $groups = q_all('SELECT name, slug FROM leader_groups ORDER BY sort, name');
        render('site/leaders_group', [
            'title' => $group['name'] . ($past ? ' (past)' : ''), 'group' => $group, 'leaders' => $leaders, 'past' => $past,
            'past_count' => $pastCount, 'groups' => $groups, 'canonical' => '/leaders/' . $group['slug'],
        ]);
    }

    public static function show(array $p): void
    {
        $group = q_one('SELECT * FROM leader_groups WHERE slug = ?', [$p['group']]);
        $leader = $group ? q_one('SELECT * FROM leaders WHERE slug = ? AND group_id = ? AND ' . published_sql(), [$p['slug'], $group['id']]) : null;
        if ($leader === null) {
            abort(404);
        }
        $social = json_decode((string) $leader['social'], true);
        render('site/leader_show', [
            'title' => $leader['name'], 'meta_description' => $leader['role_title'] . ' · ' . $group['name'],
            'group' => $group, 'leader' => $leader, 'social' => is_array($social) ? $social : [],
            'photo' => media_find($leader['photo_media_id'] ? (int) $leader['photo_media_id'] : null),
            'canonical' => '/leaders/' . $group['slug'] . '/' . $leader['slug'],
        ]);
    }
}
