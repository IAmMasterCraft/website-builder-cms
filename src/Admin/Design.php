<?php
declare(strict_types=1);

namespace Admin;

/**
 * Super admin Design screen (§8): theme, brand/accent colours, fonts,
 * corner radius and button style, with a live preview and contrast checks.
 * Only values that differ from the chosen theme's defaults are stored, so
 * switching theme later starts from that theme's own look.
 */
final class Design
{
    public static function edit(array $p): void
    {
        require_permission('super_admin');
        $theme = active_theme();
        self::form($theme, design_effective($theme), []);
    }

    private static function form(string $theme, array $values, array $errors, int $status = 200): void
    {
        $defaults = [];
        foreach (array_keys(theme_list()) as $key) {
            $defaults[$key] = theme_design_defaults($key);
        }
        render('admin/design', [
            'title' => 'Design',
            'themes' => theme_list(),
            'theme' => $theme,
            'values' => $values,
            'errors' => $errors,
            'fonts' => design_fonts(),
            'radii' => design_radius_scales(),
            'buttons' => design_button_styles(),
            'report' => design_contrast_report($values + ['surface' => $defaults[$theme]['surface']]),
            'config' => ['defaults' => $defaults, 'fonts' => array_map(static fn (array $f): string => $f['stack'], design_fonts()),
                'radii' => array_map(static fn (array $r): array => $r['values'], design_radius_scales())],
            'body_class' => 'design-mode',
        ], 'admin', $status);
    }

    public static function update(array $p): void
    {
        csrf_check();
        require_permission('super_admin');
        $theme = input('theme');
        if (!array_key_exists($theme, theme_list())) {
            self::form(active_theme(), design_effective(), ['theme' => 'Choose an installed theme.'], 422);
            return;
        }
        if (input('reset') === '1') {
            setting_set('site.theme', $theme);
            setting_set('design', []);
            audit('update', 'design', null, 'reset to ' . $theme . ' defaults');
            flash('Design reset to the ' . theme_list()[$theme]['name'] . ' theme’s defaults.');
            redirect('/admin/design');
        }
        [$values, $errors] = design_validate($_POST);
        if ($errors) {
            self::form($theme, $values + theme_design_defaults($theme), $errors, 422);
            return;
        }
        $defaults = theme_design_defaults($theme);
        $custom = array_filter($values, static fn (string $v, string $k): bool => ($defaults[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH);
        setting_set('site.theme', $theme);
        setting_set('design', $custom);
        audit('update', 'design', null, $theme . ($custom ? ' + ' . implode(', ', array_keys($custom)) : ''));
        $failing = array_filter(design_contrast_report(design_effective($theme)), static fn (array $r): bool => !$r['ok']);
        if ($failing) {
            flash('Design saved, but ' . pluralise(count($failing), 'colour pair') . ' fall below the 4.5:1 contrast needed for readable text (' . implode('; ', array_column($failing, 'label')) . '). Consider a darker colour.', 'error');
        } else {
            flash('Design saved. All checked colour pairs meet WCAG AA contrast.');
        }
        redirect('/admin/design');
    }
}
