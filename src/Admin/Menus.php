<?php
declare(strict_types=1);

namespace Admin;

final class Menus
{
    private static function menu(array $p): array
    {
        $m = q_one('SELECT * FROM menus WHERE id = ?', [(int) $p['id']]);
        if ($m === null) {
            abort(404);
        }
        return $m;
    }

    private static function item(array $menu, array $p): array
    {
        $i = q_one('SELECT * FROM menu_items WHERE id = ? AND menu_id = ?', [(int) $p['item'], $menu['id']]);
        if ($i === null) {
            abort(404);
        }
        return $i;
    }

    public static function index(array $p): void
    {
        require_permission('pages_menus');
        $menus = q_all('SELECT m.*, (SELECT COUNT(*) FROM menu_items i WHERE i.menu_id = m.id) AS n,
            (SELECT COUNT(*) FROM menu_items i WHERE i.menu_id = m.id AND i.is_broken = 1) AS broken FROM menus m ORDER BY m.location IS NULL, m.location, m.name');
        render('admin/menus_index', ['title' => 'Menus', 'menus' => $menus], 'admin');
    }

    /** @return array{0:array,1:array} */
    private static function menuInput(?int $menuId): array
    {
        $name = input('name');
        $location = input('location');
        $errors = [];
        if ($name === '' || mb_strlen($name) > 80) {
            $errors['name'] = 'Enter a menu name (up to 80 characters).';
        }
        if ($location !== '' && !isset(menu_locations()[$location])) {
            $errors['location'] = 'Choose a valid location.';
        }
        return [['name' => $name, 'location' => $location === '' ? null : $location], $errors];
    }

    /** One menu per location: assigning a taken location moves it here. */
    private static function claimLocation(?string $location, int $menuId): void
    {
        if ($location !== null) {
            q('UPDATE menus SET location = NULL WHERE location = ? AND id <> ?', [$location, $menuId]);
        }
    }

    public static function createMenu(array $p): void
    {
        csrf_check();
        require_permission('pages_menus');
        [$data, $errors] = self::menuInput(null);
        if ($errors) {
            flash(implode(' ', $errors), 'error');
            redirect('/admin/menus');
        }
        $loc = $data['location'];
        $data['location'] = null;
        $id = db_insert('menus', $data);
        self::claimLocation($loc, $id);
        db_update('menus', $id, ['location' => $loc]);
        audit('create', 'menu', $id, $data['name']);
        flash('Menu created. Now add some items.');
        redirect('/admin/menus/' . $id);
    }

    public static function show(array $p): void
    {
        require_permission('pages_menus');
        $menu = self::menu($p);
        $old = take_old_input();
        render('admin/menu_show', [
            'title' => 'Menu: ' . $menu['name'],
            'menu' => $menu,
            'items' => menu_items((int) $menu['id']),
            'pages' => q_all('SELECT id, title, status FROM pages ORDER BY title'),
            'choices' => menu_item_choices(),
            'old' => $old['values'],
            'errors' => $old['errors'],
        ], 'admin');
    }

    public static function updateMenu(array $p): void
    {
        csrf_check();
        require_permission('pages_menus');
        $menu = self::menu($p);
        [$data, $errors] = self::menuInput((int) $menu['id']);
        if ($errors) {
            flash(implode(' ', $errors), 'error');
            redirect('/admin/menus/' . $menu['id']);
        }
        db_tx(static function () use ($data, $menu): void {
            self::claimLocation($data['location'], (int) $menu['id']);
            db_update('menus', (int) $menu['id'], $data);
        });
        audit('update', 'menu', (int) $menu['id'], $data['name']);
        flash('Menu saved.');
        redirect('/admin/menus/' . $menu['id']);
    }

    public static function deleteMenu(array $p): void
    {
        csrf_check();
        require_permission('pages_menus');
        $menu = self::menu($p);
        q('DELETE FROM menus WHERE id = ?', [$menu['id']]);
        audit('delete', 'menu', (int) $menu['id'], (string) $menu['name']);
        flash('Menu deleted.');
        redirect('/admin/menus');
    }

    public static function addItem(array $p): void
    {
        csrf_check();
        require_permission('pages_menus');
        $menu = self::menu($p);
        [$data, $errors] = menu_item_validate((int) $menu['id'], self::itemInput());
        if ($errors) {
            remember_input(self::itemInput(), $errors);
            flash('Please check the new item.', 'error');
            redirect('/admin/menus/' . $menu['id'] . '#add-item');
        }
        $data['menu_id'] = $menu['id'];
        $data['sort'] = menu_next_sort((int) $menu['id'], $data['parent_id']);
        $id = db_insert('menu_items', $data);
        audit('create', 'menu_item', $id, $data['label']);
        flash('Item added.');
        redirect('/admin/menus/' . $menu['id']);
    }

    private static function itemInput(): array
    {
        $in = [];
        foreach (['label', 'target_type', 'page_id', 'list_key', 'item_ref', 'url', 'parent_id', 'open_new_tab', 'is_hidden'] as $k) {
            $in[$k] = input($k);
        }
        return $in;
    }

    public static function editItem(array $p): void
    {
        require_permission('pages_menus');
        $menu = self::menu($p);
        $item = self::item($menu, $p);
        $old = take_old_input();
        $values = $old['values'] ?: [
            'label' => $item['label'], 'target_type' => $item['target_type'], 'page_id' => (string) $item['target_id'],
            'list_key' => (string) $item['target_ref'], 'item_ref' => $item['target_type'] === 'item' ? $item['target_ref'] . ':' . $item['target_id'] : '',
            'url' => (string) $item['url'], 'parent_id' => (string) $item['parent_id'], 'open_new_tab' => (string) $item['open_new_tab'], 'is_hidden' => (string) $item['is_hidden'],
        ];
        render('admin/menu_item', [
            'title' => 'Edit menu item', 'menu' => $menu, 'item' => $item, 'values' => $values, 'errors' => $old['errors'],
            'parents' => q_all('SELECT id, label FROM menu_items WHERE menu_id = ? AND parent_id IS NULL AND id <> ? ORDER BY sort', [$menu['id'], $item['id']]),
            'pages' => q_all('SELECT id, title, status FROM pages ORDER BY title'),
            'choices' => menu_item_choices(),
        ], 'admin');
    }

    public static function updateItem(array $p): void
    {
        csrf_check();
        require_permission('pages_menus');
        $menu = self::menu($p);
        $item = self::item($menu, $p);
        [$data, $errors] = menu_item_validate((int) $menu['id'], self::itemInput(), (int) $item['id']);
        if ($errors) {
            remember_input(self::itemInput(), $errors);
            redirect('/admin/menus/' . $menu['id'] . '/items/' . $item['id']);
        }
        if ($data['parent_id'] !== $item['parent_id']) {
            $data['sort'] = menu_next_sort((int) $menu['id'], $data['parent_id']);
        }
        db_update('menu_items', (int) $item['id'], $data);
        audit('update', 'menu_item', (int) $item['id'], $data['label']);
        flash('Item saved.');
        redirect('/admin/menus/' . $menu['id']);
    }

    public static function moveItem(array $p): void
    {
        csrf_check();
        require_permission('pages_menus');
        $menu = self::menu($p);
        $item = self::item($menu, $p);
        menu_item_move((int) $item['id'], input('dir') === 'up' ? 'up' : 'down');
        audit('move', 'menu_item', (int) $item['id'], $menu['name'] . ': ' . $item['label'] . ' ' . (input('dir') === 'up' ? 'up' : 'down'));
        redirect('/admin/menus/' . $menu['id'] . '#item-' . $item['id']);
    }

    public static function toggleItem(array $p): void
    {
        csrf_check();
        require_permission('pages_menus');
        $menu = self::menu($p);
        $item = self::item($menu, $p);
        db_update('menu_items', (int) $item['id'], ['is_hidden' => (int) $item['is_hidden'] === 1 ? 0 : 1]);
        audit((int) $item['is_hidden'] === 1 ? 'show' : 'hide', 'menu_item', (int) $item['id'], $menu['name'] . ': ' . $item['label']);
        flash((int) $item['is_hidden'] === 1 ? 'Item is visible again.' : 'Item hidden (it stays in the menu so you can show it later).');
        redirect('/admin/menus/' . $menu['id'] . '#item-' . $item['id']);
    }

    public static function deleteItem(array $p): void
    {
        csrf_check();
        require_permission('pages_menus');
        $menu = self::menu($p);
        $item = self::item($menu, $p);
        q('DELETE FROM menu_items WHERE id = ?', [$item['id']]);
        audit('delete', 'menu_item', (int) $item['id'], (string) $item['label']);
        flash('Item deleted (with any sub-items).');
        redirect('/admin/menus/' . $menu['id']);
    }
}
