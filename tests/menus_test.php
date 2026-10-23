<?php
declare(strict_types=1);

function fresh_menu(string $location = 'main'): int
{
    q('DELETE FROM menus WHERE location = ?', [$location]);
    return db_insert('menus', ['name' => 'Menu ' . $location, 'location' => $location]);
}

function add_item(int $menu, array $input): int
{
    [$data, $errors] = menu_item_validate($menu, $input);
    if ($errors) {
        throw new RuntimeException(json_encode($errors));
    }
    return db_insert('menu_items', $data + ['menu_id' => $menu, 'sort' => menu_next_sort($menu, $data['parent_id'])]);
}

test('menu items validate each target type', function (): void {
    $menu = fresh_menu();
    [, $e] = menu_item_validate($menu, ['target_type' => 'page', 'page_id' => '999999']);
    ok(isset($e['page_id']));
    [, $e] = menu_item_validate($menu, ['target_type' => 'list', 'list_key' => 'nope']);
    ok(isset($e['list_key']));
    [, $e] = menu_item_validate($menu, ['target_type' => 'url', 'label' => 'X', 'url' => 'javascript:alert(1)']);
    ok(isset($e['url']));
    [, $e] = menu_item_validate($menu, ['target_type' => 'item', 'item_ref' => 'users:1']);
    ok(isset($e['item_ref']));
    [, $e] = menu_item_validate($menu, ['target_type' => 'teleport']);
    ok(isset($e['target_type']));
    [$d, $e] = menu_item_validate($menu, ['target_type' => 'list', 'list_key' => 'events']);
    same([], $e);
    same('Events', $d['label'], 'label defaults to the target name');
});

test('menu tree: two levels, hidden and unpublished targets skipped, active state', function (): void {
    $menu = fresh_menu();
    $page = fixture_content('pages', ['title' => 'Our story', 'status' => 'published', 'visibility' => 'public']);
    $draft = fixture_content('pages', ['title' => 'Secret draft', 'status' => 'draft', 'visibility' => 'public']);
    $about = add_item($menu, ['target_type' => 'page', 'page_id' => (string) $page, 'label' => 'About']);
    add_item($menu, ['target_type' => 'list', 'list_key' => 'leaders', 'parent_id' => (string) $about]);
    add_item($menu, ['target_type' => 'page', 'page_id' => (string) $draft]);
    add_item($menu, ['target_type' => 'url', 'label' => 'Partner', 'url' => 'https://partner.example', 'open_new_tab' => '1']);
    add_item($menu, ['target_type' => 'list', 'list_key' => 'gallery', 'is_hidden' => '1']);
    $tree = menu_tree('main', '/leaders/executive');
    same(['About', 'Partner'], array_column($tree, 'label'));
    same('/our-story', $tree[0]['href']);
    same('Leaders', $tree[0]['children'][0]['label']);
    ok($tree[0]['active'] && $tree[0]['children'][0]['active'], 'parent active through child');
    ok($tree[1]['new_tab']);
});

test('menus only allow two levels', function (): void {
    $menu = fresh_menu('footer_1');
    $top = add_item($menu, ['target_type' => 'list', 'list_key' => 'news']);
    $child = add_item($menu, ['target_type' => 'list', 'list_key' => 'blog', 'parent_id' => (string) $top]);
    [, $e] = menu_item_validate($menu, ['target_type' => 'list', 'list_key' => 'faq', 'parent_id' => (string) $child]);
    ok(isset($e['parent_id']), 'cannot nest under a child');
    [, $e] = menu_item_validate($menu, ['target_type' => 'list', 'list_key' => 'news', 'parent_id' => (string) $child], $top);
    ok(isset($e['parent_id']), 'an item with children cannot become a child');
});

test('deleting a linked page flags its menu item as broken (acceptance 3)', function (): void {
    $menu = fresh_menu();
    $page = fixture_content('pages', ['title' => 'Soon gone', 'status' => 'published', 'visibility' => 'public']);
    $item = add_item($menu, ['target_type' => 'page', 'page_id' => (string) $page]);
    $post = fixture_content('posts', ['title' => 'Linked post', 'category_id' => (string) q_val("SELECT id FROM categories WHERE slug='news'"), 'status' => 'published']);
    $item2 = add_item($menu, ['target_type' => 'item', 'item_ref' => 'post:' . $post]);
    same(2, count(menu_tree('main')), 'both items visible before deletion');
    content_delete(content_type('pages'), $page);
    content_delete(content_type('posts'), $post);
    same(1, (int) q_val('SELECT is_broken FROM menu_items WHERE id = ?', [$item]));
    same(1, (int) q_val('SELECT is_broken FROM menu_items WHERE id = ?', [$item2]));
    same(null, menu_item_href(q_one('SELECT * FROM menu_items WHERE id = ?', [$item])), 'broken items are not linked publicly');
    contains('deleted', menu_item_target_label(q_one('SELECT * FROM menu_items WHERE id = ?', [$item])));
});

test('menu items move up and down among siblings', function (): void {
    $menu = fresh_menu('footer_2');
    $a = add_item($menu, ['target_type' => 'list', 'list_key' => 'news']);
    $b = add_item($menu, ['target_type' => 'list', 'list_key' => 'events']);
    $c = add_item($menu, ['target_type' => 'list', 'list_key' => 'faq']);
    menu_item_move($c, 'up');
    same([$a, $c, $b], array_map('intval', array_column(menu_items($menu), 'id')));
    menu_item_move($a, 'up');
    same([$a, $c, $b], array_map('intval', array_column(menu_items($menu), 'id')), 'first item stays first');
});

test('default main menu is used until one is assigned', function (): void {
    q("DELETE FROM menus WHERE location = 'main'");
    $_SERVER['REQUEST_URI'] = '/events';
    same(['Home', 'News', 'Events', 'Gallery', 'Leaders', 'Downloads'], array_column(default_main_menu(), 'label'));
});
