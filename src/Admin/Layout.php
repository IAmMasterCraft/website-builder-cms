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
        ] + footer_cta_settings(), []);
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
        foreach (['topbar', 'show_auth', 'sticky', 'listen_live', 'footer_cta', 'footer_word'] as $k) {
            $header[$k] = input($k) === '1';
        }
        $header['footer_cta_heading'] = input('footer_cta_heading');
        $header['footer_cta_text'] = input('footer_cta_text');
        $widgets = input_list('col_widget');
        $titles = input_list('col_title');
        $menus = input_list('col_menu');
        $texts = input_list('col_text');
        $columns = [];
        $errors = [];
        if (mb_strlen($header['footer_cta_heading']) > 120) {
            $errors['footer_cta_heading'] = 'Keep the heading under 120 characters.';
        }
        if (mb_strlen($header['footer_cta_text']) > 300) {
            $errors['footer_cta_text'] = 'Keep the text under 300 characters.';
        }
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
        foreach (['topbar', 'show_auth', 'sticky', 'listen_live'] as $k) {
            setting_set('header.' . $k, $header[$k]);
        }
        setting_set('footer.cta', $header['footer_cta']);
        setting_set('footer.wordmark', $header['footer_word']);
        setting_set('footer.cta_heading', $header['footer_cta_heading']);
        setting_set('footer.cta_text', $header['footer_cta_text']);
        setting_set('footer.columns', $columns);
        audit('update', 'settings', null, 'header and footer');
        flash('Header and footer saved.');
        redirect('/admin/layout');
    }
}
