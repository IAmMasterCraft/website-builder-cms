<?php
declare(strict_types=1);

namespace Admin;

/**
 * Location lists (C4, super admin): per-country level labels and local
 * areas missing from the bundled GeoNames lists.
 */
final class Locations
{
    private static function country(string $raw): string
    {
        $cc = strtoupper(trim($raw));
        return geo_country_exists($cc) ? $cc : '';
    }

    public static function index(array $p): void
    {
        require_permission('super_admin');
        $cc = self::country(query_param('country', 'GB'));
        $cc = $cc !== '' ? $cc : 'GB';
        $data = geo_country_data($cc);
        $counts = [];
        foreach ($data['a1'] as $code => $_) {
            $counts[$code] = count($data['a2'][$code] ?? []);
        }
        $custom = q_all('SELECT a.*, u.display_name AS author FROM geo_custom_areas a LEFT JOIN users u ON u.id = a.created_by WHERE a.country_code = ? ORDER BY a.admin1_code, a.name', [$cc]);
        foreach ($custom as &$c) {
            $c['used'] = geo_custom_area_usage((int) $c['id']);
        }
        unset($c);
        $overrides = (array) setting('geo.labels', []);
        render('admin/locations', [
            'title' => 'Location lists', 'cc' => $cc, 'admin1' => $data['a1'], 'counts' => $counts, 'custom' => $custom,
            'labels' => geo_labels($cc), 'builtin' => geo_index()['labels'][$cc] ?? ['Region', 'Area'], 'override' => is_array($overrides[$cc] ?? null) ? $overrides[$cc] : ['', ''],
            'members_using' => (int) q_val('SELECT COUNT(DISTINCT member_id) FROM member_locations WHERE country_code = ?', [$cc]),
            'state' => query_param('state'),
        ], 'admin');
    }

    public static function labels(array $p): void
    {
        csrf_check();
        require_permission('super_admin');
        $cc = self::country(input('country'));
        if ($cc === '') {
            abort(422);
        }
        $l1 = input('l1_label');
        $l2 = input('l2_label');
        if (mb_strlen($l1) > 60 || mb_strlen($l2) > 60) {
            flash('Keep labels under 60 characters.', 'error');
            redirect('/admin/locations?country=' . $cc);
        }
        $all = (array) setting('geo.labels', []);
        if ($l1 === '' && $l2 === '') {
            unset($all[$cc]);
        } else {
            $all[$cc] = [$l1, $l2];
        }
        setting_set('geo.labels', $all);
        audit('update', 'settings', null, 'location labels for ' . geo_country_name($cc) . ': ' . ($l1 !== '' || $l2 !== '' ? trim($l1 . ' / ' . $l2, ' /') : 'defaults'));
        flash('Labels saved for ' . geo_country_name($cc) . '.');
        redirect('/admin/locations?country=' . $cc);
    }

    public static function addArea(array $p): void
    {
        csrf_check();
        $admin = require_permission('super_admin');
        $cc = self::country(input('country'));
        $a1 = input('state');
        try {
            $id = geo_custom_area_add($cc, $a1, input('name'), (int) $admin['id']);
        } catch (\InvalidArgumentException $e) {
            flash($e->getMessage(), 'error');
            redirect('/admin/locations?country=' . ($cc ?: 'GB') . '&state=' . rawurlencode($a1));
        }
        $name = (string) q_val('SELECT name FROM geo_custom_areas WHERE id = ?', [$id]);
        audit('create', 'geo_area', $id, $name . ' (' . (geo_admin1_options($cc)[$a1] ?? $a1) . ', ' . geo_country_name($cc) . ')');
        flash('“' . $name . '” added to ' . (geo_admin1_options($cc)[$a1] ?? $a1) . '.');
        redirect('/admin/locations?country=' . $cc . '&state=' . rawurlencode($a1));
    }

    public static function deleteArea(array $p): void
    {
        csrf_check();
        require_permission('super_admin');
        $row = q_one('SELECT * FROM geo_custom_areas WHERE id = ?', [(int) $p['id']]);
        if ($row === null) {
            abort(404);
        }
        $used = geo_custom_area_usage((int) $row['id']);
        if ($used > 0) {
            flash('“' . $row['name'] . '” is chosen by ' . pluralise($used, 'member') . ', so it stays on the list.', 'error');
            redirect('/admin/locations?country=' . $row['country_code']);
        }
        q('DELETE FROM geo_custom_areas WHERE id = ?', [$row['id']]);
        setting_set('geo.custom_version', (int) setting('geo.custom_version', 0) + 1);
        geo_custom_areas(null, true);
        audit('delete', 'geo_area', (int) $row['id'], (string) $row['name']);
        flash('“' . $row['name'] . '” removed.');
        redirect('/admin/locations?country=' . $row['country_code']);
    }
}
