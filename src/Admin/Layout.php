<?php
declare(strict_types=1);

namespace Admin;

/** Header options and footer widget columns (§3.2). */
final class Layout
{
    public static function edit(array $p): void
    {
        require_permission('pages_menus');
        self::form(footer_columns(), [
            'topbar' => (bool) setting('header.topbar', true), 'show_auth' => (bool) setting('header.show_auth', true),
            'sticky' => (bool) setting('header.sticky', true), 'listen_live' => (bool) setting('header.listen_live', true),
        ], []);
    }

    private static function form(array $columns, array $header, array $errors, int $status = 200): void
    {
        while (count($columns) < 4) {
            $columns[] = ['widget' => '', 'title' => '', 'menu' => 'footer_1', 'text' => ''];
        }
        render('admin/layout', [
            'title' => 'Header and footer', 'columns' => $columns, 'header' => $header, 'errors' => $errors,
            'widgets' => footer_widgets(), 'menus' => array_filter(menu_locations(), static fn (string $k): bool => str_starts_with($k, 'footer'), ARRAY_FILTER_USE_KEY),
        ], 'admin', $status);
    }

    public static function update(array $p): void
    {
        csrf_check();
        require_permission('pages_menus');
        $header = [];
        foreach (['topbar', 'show_auth', 'sticky', 'listen_live'] as $k) {
            $header[$k] = input($k) === '1';
        }
        $widgets = input_list('col_widget');
        $titles = input_list('col_title');
        $menus = input_list('col_menu');
        $texts = input_list('col_text');
        $columns = [];
        $errors = [];
        for ($i = 0; $i < 4; $i++) {
            $col = ['widget' => $widgets[$i] ?? '', 'title' => $titles[$i] ?? '', 'menu' => $menus[$i] ?? 'footer_1', 'text' => $texts[$i] ?? ''];
            if ($col['widget'] === '') {
                continue;
            }
            if (!array_key_exists($col['widget'], footer_widgets())) {
                $errors["col_$i"] = 'Choose a widget.';
            }
            if (mb_strlen($col['title']) > 60) {
                $errors["col_$i"] = 'Keep column headings under 60 characters.';
            }
            if (mb_strlen($col['text']) > 500) {
                $errors["col_$i"] = 'Keep the text under 500 characters.';
            }
            if ($col['widget'] === 'menu' && !array_key_exists($col['menu'], menu_locations())) {
                $errors["col_$i"] = 'Choose a footer menu.';
            }
            $columns[] = $col;
        }
        if ($errors) {
            self::form($columns, $header, $errors, 422);
            return;
        }
        foreach ($header as $k => $v) {
            setting_set('header.' . $k, $v);
        }
        setting_set('footer.columns', $columns);
        audit('update', 'settings', null, 'header and footer');
        flash('Header and footer saved.');
        redirect('/admin/layout');
    }
}
