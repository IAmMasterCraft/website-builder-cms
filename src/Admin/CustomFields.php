<?php
declare(strict_types=1);

namespace Admin;

/** Super admin: custom profile fields (§4.1): text, select, date, checkbox, location (C4). */
final class CustomFields
{
    public const TYPES = ['text' => 'Text', 'select' => 'Choice from a list', 'date' => 'Date', 'checkbox' => 'Tick box', 'location' => 'Location (country → state → local area)'];
    public const CARD_LEVELS = ['full' => 'Everything chosen (area, state, country)', 'country' => 'Country', 'admin1' => 'State or region', 'admin2' => 'Local area'];

    public static function index(array $p): void
    {
        require_permission('super_admin');
        $rows = q_all('SELECT f.*, (SELECT COUNT(*) FROM member_field_values v WHERE v.field_id = f.id AND v.value <> \'\') AS used FROM custom_fields f ORDER BY f.applies_to, f.sort, f.id');
        render('admin/custom_fields', ['title' => 'Custom fields', 'rows' => $rows], 'admin');
    }

    public static function create(array $p): void
    {
        require_permission('super_admin');
        self::form(['label' => '', 'type' => 'text', 'options' => '', 'is_required' => '', 'show_on_card' => '', 'visible_to_members' => '1', 'applies_to' => 'member', 'sort' => '0', 'loc_levels' => '2', 'loc_card_level' => 'full'], [], null);
    }

    public static function edit(array $p): void
    {
        require_permission('super_admin');
        $row = q_one('SELECT * FROM custom_fields WHERE id = ?', [(int) $p['id']]);
        if ($row === null) {
            abort(404);
        }
        $opts = json_decode((string) $row['options'], true);
        $row['options'] = is_array($opts) ? implode("\n", $opts) : '';
        $cfg = json_decode((string) ($row['field_config'] ?? ''), true);
        $cfg = is_array($cfg) ? $cfg : [];
        foreach (['levels' => '2', 'country' => '', 'country_label' => '', 'l1_label' => '', 'l2_label' => '', 'card_level' => 'full'] as $k => $d) {
            $row['loc_' . $k] = (string) ($cfg[$k] ?? $d);
        }
        self::form($row, [], $row);
    }

    private static function form(array $values, array $errors, ?array $row, int $status = 200): void
    {
        render('admin/custom_field_form', ['title' => $row ? 'Edit field' : 'New field', 'values' => $values, 'errors' => $errors, 'row' => $row, 'types' => self::TYPES], 'admin', $status);
    }

    /** @return array{0:array,1:array<string,string>} */
    public static function validate(array $in, ?array $row): array
    {
        $errors = [];
        $label = trim((string) ($in['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 120) {
            $errors['label'] = 'Give the field a label (up to 120 characters).';
        }
        $type = (string) ($in['type'] ?? '');
        if (!array_key_exists($type, self::TYPES)) {
            $errors['type'] = 'Choose a type.';
        }
        $options = lines_to_list((string) ($in['options'] ?? ''), 100, 120);
        if ($type === 'select' && count($options) < 2) {
            $errors['options'] = 'Give at least two choices, one per line.';
        }
        $applies = ($in['applies_to'] ?? 'member') === 'group' ? 'group' : 'member';
        $sort = (int) ($in['sort'] ?? 0);
        $config = null;
        if ($type === 'location') {
            if ($applies === 'group') {
                $errors['applies_to'] = 'Location fields are for members (group accounts have a country field).';
            }
            $c = [
                'levels' => (int) ($in['loc_levels'] ?? 2), 'country' => strtoupper(trim((string) ($in['loc_country'] ?? ''))),
                'country_label' => trim((string) ($in['loc_country_label'] ?? '')), 'l1_label' => trim((string) ($in['loc_l1_label'] ?? '')),
                'l2_label' => trim((string) ($in['loc_l2_label'] ?? '')), 'card_level' => (string) ($in['loc_card_level'] ?? 'full'),
            ];
            if ($c['levels'] < 1 || $c['levels'] > 3) {
                $errors['loc_levels'] = 'Choose how many levels to ask.';
            }
            if ($c['country'] !== '' && !geo_country_exists($c['country'])) {
                $errors['loc_country'] = 'Choose a country from the list, or “Any country”.';
            } elseif ($c['country'] !== '' && $c['levels'] < 2) {
                $errors['loc_levels'] = 'With a fixed country, ask at least the first level (for example the state).';
            }
            foreach (['country_label', 'l1_label', 'l2_label'] as $k) {
                if (mb_strlen($c[$k]) > 60) {
                    $errors['loc_' . $k] = 'Keep labels under 60 characters.';
                }
            }
            if (!array_key_exists($c['card_level'], self::CARD_LEVELS)) {
                $c['card_level'] = 'full';
            }
            $config = json_encode($c, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        $data = [
            'label' => $label, 'type' => $type, 'options' => $type === 'select' ? json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null,
            'field_config' => $config,
            'is_required' => ($in['is_required'] ?? '') === '1' ? 1 : 0, 'show_on_card' => ($in['show_on_card'] ?? '') === '1' ? 1 : 0,
            'visible_to_members' => ($in['visible_to_members'] ?? '') === '1' ? 1 : 0, 'applies_to' => $applies, 'sort' => max(-999, min(999, $sort)),
        ];
        if ($row === null) {
            $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', slugify($label, 50)), '_') ?: 'field';
            $base = $key;
            for ($i = 2; q_val('SELECT id FROM custom_fields WHERE field_key = ?', [$key]) !== null; $i++) {
                $key = $base . '_' . $i;
            }
            $data['field_key'] = $key;
        }
        return [$data, $errors];
    }

    public static function store(array $p): void
    {
        csrf_check();
        require_permission('super_admin');
        [$data, $errors] = self::validate($_POST, null);
        if ($errors) {
            self::form($_POST + ['options' => ''], $errors, null, 422);
            return;
        }
        $id = db_insert('custom_fields', $data);
        audit('create', 'custom_field', $id, $data['label']);
        flash('Field “' . $data['label'] . '” added.');
        redirect('/admin/custom-fields');
    }

    public static function update(array $p): void
    {
        csrf_check();
        require_permission('super_admin');
        $row = q_one('SELECT * FROM custom_fields WHERE id = ?', [(int) $p['id']]);
        if ($row === null) {
            abort(404);
        }
        [$data, $errors] = self::validate($_POST, $row);
        if ($errors) {
            self::form($_POST + ['options' => ''], $errors, $row, 422);
            return;
        }
        db_update('custom_fields', (int) $row['id'], $data);
        $note = '';
        if ($data['type'] === 'location' && in_array($row['type'], ['text', 'select', 'location'], true)) {
            // C4: answers given as text/choices (or under other location settings) become locations; nothing is dropped.
            $r = custom_field_location_convert($data + ['id' => $row['id'], 'is_required' => $data['is_required']]);
            $note = $r['matched'] + $r['kept'] > 0 ? ' ' . pluralise($r['matched'], 'answer') . ' matched the lists' . ($r['kept'] > 0 ? '; ' . pluralise($r['kept'], 'answer') . ' kept as typed' : '') . '.' : '';
        } elseif ($row['type'] === 'location' && $data['type'] !== 'location') {
            // Back to plain text: the display text stays in the answers; the structured rows go.
            q('DELETE FROM member_locations WHERE slot = ?', ['cf' . (int) $row['id']]);
        }
        audit('update', 'custom_field', (int) $row['id'], $data['label'] . ($row['type'] !== $data['type'] ? ' (type ' . $row['type'] . ' -> ' . $data['type'] . ')' : '') . $note);
        flash('Field saved.' . $note);
        redirect('/admin/custom-fields');
    }

    public static function delete(array $p): void
    {
        csrf_check();
        require_permission('super_admin');
        $row = q_one('SELECT * FROM custom_fields WHERE id = ?', [(int) $p['id']]);
        if ($row === null) {
            abort(404);
        }
        q('DELETE FROM custom_fields WHERE id = ?', [$row['id']]);
        q('DELETE FROM member_locations WHERE slot = ?', ['cf' . (int) $row['id']]);
        audit('delete', 'custom_field', (int) $row['id'], (string) $row['label']);
        flash('Field “' . $row['label'] . '” and its saved answers were deleted.');
        redirect('/admin/custom-fields');
    }
}
