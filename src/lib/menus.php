<?php
declare(strict_types=1);

/*
 * Menu manager (§3.2): multiple menus assigned to locations, two levels,
 * items pointing to a page, a content list, a content item or a URL.
 */

function menu_locations(): array
{
    return [
        'main' => 'Main navigation',
        'topbar' => 'Top bar',
        'footer_1' => 'Footer menu 1',
        'footer_2' => 'Footer menu 2',
        'footer_3' => 'Footer menu 3',
        'footer_4' => 'Footer menu 4',
    ];
}

/** Built-in list pages a menu item can point to. */
function menu_list_targets(): array
{
    return [
        'home' => ['Home', '/'],
        'news' => ['News', '/news'],
        'blog' => ['Blog', '/blog'],
        'projects' => ['Projects', '/projects'],
        'events' => ['Events', '/events'],
        'calendar' => ['Event calendar', '/events/calendar'],
        'gallery' => ['Gallery', '/gallery'],
        'downloads' => ['Downloads', '/downloads'],
        'leaders' => ['Leaders', '/leaders'],
        'awards' => ['Awards', '/awards'],
        'faq' => ['FAQ', '/faq'],
        'branches' => ['Branches', '/branches'],
        'groups' => ['Groups', '/groups'],
        'search' => ['Search', '/search'],
        'login' => ['Log in', '/login'],
    ];
}

/** Content item types a menu item can point to => [table, title column]. */
function menu_item_types(): array
{
    return [
        'post' => ['posts', 'title'],
        'event' => ['events', 'title'],
        'album' => ['albums', 'title'],
        'leader_group' => ['leader_groups', 'name'],
        'leader' => ['leaders', 'name'],
        'download' => ['downloads', 'title'],
    ];
}

/** @return list<array<string,mixed>> items of a menu, parents before children, ordered */
function menu_items(int $menuId): array
{
    $rows = q_all('SELECT * FROM menu_items WHERE menu_id = ? ORDER BY sort, id', [$menuId]);
    $top = array_values(array_filter($rows, static fn (array $r): bool => $r['parent_id'] === null));
    $out = [];
    foreach ($top as $t) {
        $t['depth'] = 0;
        $out[] = $t;
        foreach ($rows as $r) {
            if ((int) $r['parent_id'] === (int) $t['id']) {
                $r['depth'] = 1;
                $out[] = $r;
            }
        }
    }
    return $out;
}

/** Link for an item, or null when its target is missing/unpublished. */
function menu_item_href(array $item): ?string
{
    if ((int) $item['is_broken'] === 1) {
        return null;
    }
    switch ($item['target_type']) {
        case 'url':
            $u = safe_url((string) $item['url']);
            return $u === '' ? null : $u;
        case 'list':
            return menu_list_targets()[$item['target_ref']][1] ?? null;
        case 'page':
            $page = q_one('SELECT id, slug FROM pages WHERE id = ? AND ' . published_sql() . " AND visibility <> 'hidden'", [$item['target_id']]);
            if ($page === null) {
                return null;
            }
            return (int) setting('home.page_id', 0) === (int) $page['id'] ? '/' : '/' . $page['slug'];
        case 'item':
            $type = menu_item_types()[$item['target_ref']] ?? null;
            if ($type === null) {
                return null;
            }
            $hasStatus = !in_array($type[0], ['leader_groups'], true);
            $row = q_one('SELECT * FROM ' . db_ident($type[0]) . ' WHERE id = ?' . ($hasStatus ? ' AND ' . published_sql() : ''), [$item['target_id']]);
            return $row === null ? null : content_public_url((string) $item['target_ref'], $row);
    }
    return null;
}

/**
 * Public menu tree for a location (hidden and broken items skipped).
 * @return list<array{label:string, href:string, new_tab:bool, active:bool, children:list<array>}>
 */
function menu_tree(string $location, string $currentPath = '/'): array
{
    $menuId = q_val('SELECT id FROM menus WHERE location = ?', [$location]);
    if ($menuId === null) {
        return [];
    }
    $tree = [];
    $index = [];
    foreach (menu_items((int) $menuId) as $item) {
        if ((int) $item['is_hidden'] === 1) {
            continue;
        }
        $href = menu_item_href($item);
        if ($href === null) {
            continue;
        }
        $node = [
            'label' => (string) $item['label'],
            'href' => $href,
            'new_tab' => (int) $item['open_new_tab'] === 1,
            'active' => menu_is_active($href, $currentPath),
            'children' => [],
        ];
        if ($item['parent_id'] === null) {
            $index[(int) $item['id']] = count($tree);
            $tree[] = $node;
        } elseif (isset($index[(int) $item['parent_id']])) {
            $tree[$index[(int) $item['parent_id']]]['children'][] = $node;
            if ($node['active']) {
                $tree[$index[(int) $item['parent_id']]]['active'] = true;
            }
        }
    }
    return $tree;
}

function menu_is_active(string $href, string $path): bool
{
    $hrefPath = (string) parse_url($href, PHP_URL_PATH);
    if ($hrefPath === '' || str_starts_with($href, 'http')) {
        return false;
    }
    if ($hrefPath === '/') {
        return $path === '/';
    }
    return $path === $hrefPath || str_starts_with($path, rtrim($hrefPath, '/') . '/');
}

/** Flag menu items whose target was deleted (they stay, marked, until fixed). */
function menu_flag_broken(string $targetType, string $ref, int $id): int
{
    if ($targetType === 'page') {
        return q("UPDATE menu_items SET is_broken = 1 WHERE target_type = 'page' AND target_id = ?", [$id])->rowCount();
    }
    return q("UPDATE menu_items SET is_broken = 1 WHERE target_type = 'item' AND target_ref = ? AND target_id = ?", [$ref, $id])->rowCount();
}

/**
 * Validate a menu item form.
 * @return array{0:array<string,mixed>,1:array<string,string>}
 */
function menu_item_validate(int $menuId, array $input, ?int $itemId = null): array
{
    $errors = [];
    $label = trim((string) ($input['label'] ?? ''));
    $type = (string) ($input['target_type'] ?? '');
    $data = [
        'label' => $label,
        'target_type' => $type,
        'target_ref' => null,
        'target_id' => null,
        'url' => null,
        'open_new_tab' => ($input['open_new_tab'] ?? '') === '1' ? 1 : 0,
        'is_hidden' => ($input['is_hidden'] ?? '') === '1' ? 1 : 0,
        'is_broken' => 0,
        'parent_id' => null,
    ];
    switch ($type) {
        case 'page':
            $pid = (int) ($input['page_id'] ?? 0);
            $page = q_one('SELECT id, title FROM pages WHERE id = ?', [$pid]);
            if ($page === null) {
                $errors['page_id'] = 'Choose a page.';
            } else {
                $data['target_id'] = $pid;
                $label = $label !== '' ? $label : (string) $page['title'];
            }
            break;
        case 'list':
            $ref = (string) ($input['list_key'] ?? '');
            if (!isset(menu_list_targets()[$ref])) {
                $errors['list_key'] = 'Choose a list.';
            } else {
                $data['target_ref'] = $ref;
                $label = $label !== '' ? $label : menu_list_targets()[$ref][0];
            }
            break;
        case 'item':
            $raw = (string) ($input['item_ref'] ?? '');
            if (!preg_match('/^([a-z_]+):(\d+)$/', $raw, $m) || !isset(menu_item_types()[$m[1]])) {
                $errors['item_ref'] = 'Choose a content item.';
                break;
            }
            [$table, $titleCol] = menu_item_types()[$m[1]];
            $row = q_one('SELECT ' . db_ident($titleCol) . ' AS t FROM ' . db_ident($table) . ' WHERE id = ?', [(int) $m[2]]);
            if ($row === null) {
                $errors['item_ref'] = 'That item no longer exists.';
                break;
            }
            $data['target_ref'] = $m[1];
            $data['target_id'] = (int) $m[2];
            $label = $label !== '' ? $label : (string) $row['t'];
            break;
        case 'url':
            $url = trim((string) ($input['url'] ?? ''));
            if (safe_url($url) === '' || mb_strlen($url) > 500) {
                $errors['url'] = 'Enter a link starting with https://, / or mailto:';
            }
            $data['url'] = $url;
            break;
        default:
            $errors['target_type'] = 'Choose what the item links to.';
    }
    $data['label'] = $label;
    if ($label === '' || mb_strlen($label) > 120) {
        $errors['label'] = 'Enter a label (up to 120 characters).';
    }
    $parent = (int) ($input['parent_id'] ?? 0);
    if ($parent > 0) {
        $p = q_one('SELECT id, parent_id FROM menu_items WHERE id = ? AND menu_id = ?', [$parent, $menuId]);
        $hasChildren = $itemId !== null && (int) q_val('SELECT COUNT(*) FROM menu_items WHERE parent_id = ?', [$itemId]) > 0;
        if ($p === null || $p['parent_id'] !== null || $parent === $itemId) {
            $errors['parent_id'] = 'Menus have two levels: choose a top-level item as the parent.';
        } elseif ($hasChildren) {
            $errors['parent_id'] = 'This item has its own sub-items, so it must stay at the top level.';
        } else {
            $data['parent_id'] = $parent;
        }
    }
    return [$data, $errors];
}

/** Move an item up or down among its siblings. */
function menu_item_move(int $itemId, string $direction): void
{
    $item = q_one('SELECT * FROM menu_items WHERE id = ?', [$itemId]);
    if ($item === null) {
        return;
    }
    $siblings = q_all(
        'SELECT id FROM menu_items WHERE menu_id = ? AND ' . ($item['parent_id'] === null ? 'parent_id IS NULL' : 'parent_id = ?') . ' ORDER BY sort, id',
        $item['parent_id'] === null ? [$item['menu_id']] : [$item['menu_id'], $item['parent_id']]
    );
    $ids = array_map('intval', array_column($siblings, 'id'));
    $pos = array_search($itemId, $ids, true);
    $swap = $direction === 'up' ? $pos - 1 : $pos + 1;
    if ($pos === false || $swap < 0 || $swap >= count($ids)) {
        return;
    }
    [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
    db_tx(static function () use ($ids): void {
        foreach ($ids as $i => $id) {
            q('UPDATE menu_items SET sort = ? WHERE id = ?', [$i, $id]);
        }
    });
}

function menu_next_sort(int $menuId, ?int $parentId): int
{
    return (int) q_val(
        'SELECT COALESCE(MAX(sort), -1) + 1 FROM menu_items WHERE menu_id = ? AND ' . ($parentId === null ? 'parent_id IS NULL' : 'parent_id = ?'),
        $parentId === null ? [$menuId] : [$menuId, $parentId]
    );
}

/** Options for the "content item" picker, grouped by type. */
function menu_item_choices(): array
{
    $out = [];
    foreach (menu_item_types() as $key => [$table, $titleCol]) {
        $order = in_array($table, ['events'], true) ? 'start_at DESC' : 'id DESC';
        foreach (q_all('SELECT id, ' . db_ident($titleCol) . ' AS t FROM ' . db_ident($table) . ' ORDER BY ' . $order . ' LIMIT 200') as $r) {
            $out[$key][$key . ':' . $r['id']] = (string) $r['t'];
        }
    }
    return $out;
}

/** Admin description of an item's target. */
function menu_item_target_label(array $item): string
{
    return match ($item['target_type']) {
        'page' => 'Page: ' . (string) (q_val('SELECT title FROM pages WHERE id = ?', [$item['target_id']]) ?? 'deleted'),
        'list' => 'List: ' . (menu_list_targets()[$item['target_ref']][0] ?? $item['target_ref']),
        'item' => ucfirst(str_replace('_', ' ', (string) $item['target_ref'])) . ': ' . (string) (q_val(
            'SELECT ' . db_ident(menu_item_types()[$item['target_ref']][1] ?? 'id') . ' FROM ' . db_ident(menu_item_types()[$item['target_ref']][0] ?? 'posts') . ' WHERE id = ?',
            [$item['target_id']]
        ) ?? 'deleted'),
        default => 'Link: ' . (string) $item['url'],
    };
}
