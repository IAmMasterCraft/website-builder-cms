<?php
declare(strict_types=1);

namespace Admin;

use Core\UploadException;

/** Generic list/create/edit/delete for every type in content_types(). */
final class Content
{
    private static function type(array $p): array
    {
        $types = content_types();
        if (!isset($types[$p['type']])) {
            abort(404);
        }
        return $types[$p['type']];
    }

    public static function index(array $p): void
    {
        $type = self::type($p);
        require_permission($type['permission']);
        $filters = ['q' => mb_substr(query_param('q'), 0, 100)];
        foreach (array_keys($type['filters']) as $k) {
            $filters[$k] = query_param($k);
        }
        $page = max(1, input_int('page', 1, true));
        $list = content_admin_list($type, $filters, $page, 25);
        self::preloadListMedia($type, $list['rows']);
        render('admin/content_index', [
            'title' => $type['plural'],
            'type' => $type,
            'rows' => $list['rows'],
            'cells' => admin_list_cells($type, $list['rows']),
            'filter_options' => array_map(static fn ($o): array => is_string($o) ? field_options(['options' => $o]) : $o, $type['filters']),
            'total' => $list['total'],
            'page' => $page,
            'per' => 25,
            'filters' => $filters,
        ], 'admin');
    }

    private static function preloadListMedia(array $type, array $rows): void
    {
        foreach (['cover_media_id', 'photo_media_id', 'logo_media_id'] as $col) {
            if (content_field($type, $col) !== null) {
                media_preload(array_column($rows, $col));
            }
        }
    }

    public static function create(array $p): void
    {
        $type = self::type($p);
        require_permission($type['permission']);
        $values = [];
        foreach ($type['fields'] as $f) {
            $values[$f['name']] = field_default($f);
        }
        if ($type['key'] === 'leaders' && query_param('group') !== '') {
            $values['group_id'] = query_param('group');
        }
        self::form($type, $values, [], null);
    }

    public static function edit(array $p): void
    {
        $type = self::type($p);
        require_permission($type['permission']);
        $row = content_find($type, (int) $p['id']);
        if ($row === null) {
            abort(404);
        }
        self::form($type, self::rowToValues($type, $row), [], $row);
    }

    /** Convert stored values (UTC datetimes, JSON) into form values. */
    private static function rowToValues(array $type, array $row): array
    {
        $values = $row;
        foreach ($type['fields'] as $f) {
            if ($f['type'] === 'datetime') {
                $tz = isset($f['tz_field']) && !empty($row[$f['tz_field']]) ? new \DateTimeZone((string) $row[$f['tz_field']]) : null;
                $values[$f['name']] = utc_to_input($row[$f['name']] ?? null, $tz);
            }
            if ($f['type'] === 'social') {
                $social = json_decode((string) ($row['social'] ?? ''), true);
                foreach (is_array($social) ? $social : [] as $k => $v) {
                    $values['social_' . $k] = $v;
                }
            }
        }
        return $values;
    }

    private static function form(array $type, array $values, array $errors, ?array $row, int $status = 200): void
    {
        $options = [];
        $datalists = [];
        foreach ($type['fields'] as $f) {
            if ($f['type'] === 'select') {
                $options[$f['name']] = field_options($f);
            }
            if (isset($f['datalist']) && in_array($f['datalist'], ['download_categories', 'faq_categories'], true)) {
                $datalists[$f['name']] = ($f['datalist'])();
            }
            if (in_array($f['type'], ['media', 'file'], true) && !empty($values[$f['name']])) {
                media_preload([(int) $values[$f['name']]]);
            }
        }
        render('admin/content_form', [
            'options' => $options,
            'datalists' => $datalists,
            'title' => ($row ? 'Edit ' : 'New ') . strtolower($type['label']),
            'type' => $type,
            'values' => $values,
            'errors' => $errors,
            'row' => $row,
            'public_url' => $row ? self::publicUrl($type, $row) : null,
        ], 'admin', $status);
    }

    private static function publicUrl(array $type, array $row): ?string
    {
        if (in_array($type['item'], ['category'], true)) {
            return null;
        }
        return content_public_url($type['item'], $row);
    }

    /**
     * Collect form input; handle direct uploads for media/file fields.
     * @return array{0:array,1:array<string,string>}
     */
    private static function input(array $type, int $userId): array
    {
        $input = [];
        $errors = [];
        foreach ($_POST as $k => $v) {
            if (is_string($k) && preg_match('/^[a-z0-9_]+$/', $k)) {
                $input[$k] = is_array($v) ? array_values(array_filter($v, 'is_string')) : (is_string($v) ? $v : '');
            }
        }
        foreach ($type['fields'] as $f) {
            if (!in_array($f['type'], ['media', 'file'], true)) {
                continue;
            }
            $name = $f['name'];
            if (($input[$name . '_remove'] ?? '') === '1') {
                $input[$name] = '';
            }
            $file = $_FILES[$name . '_upload'] ?? null;
            if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                try {
                    $u = media_upload_from_files($file);
                    $visibility = ($input['visibility'] ?? 'public') === 'members' && $f['type'] === 'file' ? 'members' : 'public';
                    $input[$name] = (string) media_store($u['tmp'], $u['name'], $u['size'], $userId, true, [
                        'kind' => $f['type'] === 'media' ? 'image' : null,
                        'visibility' => $visibility,
                        'folder' => $type['key'],
                        'alt' => $f['type'] === 'media' ? (string) ($input[$type['title']] ?? '') : '',
                    ]);
                } catch (UploadException $e) {
                    $errors[$name] = $e->getMessage();
                }
            }
        }
        return [$input, $errors];
    }

    public static function store(array $p): void
    {
        csrf_check();
        $type = self::type($p);
        $user = require_permission($type['permission']);
        [$input, $uploadErrors] = self::input($type, (int) $user['id']);
        [$data, $errors] = content_validate($type, $input);
        $errors += $uploadErrors;
        if ($errors) {
            self::form($type, $input, $errors, null, 422);
            return;
        }
        $id = content_save($type, $data, $input, null, (int) $user['id']);
        audit('create', $type['item'], $id, (string) ($data[$type['title']] ?? ''));
        flash($type['label'] . ' created.');
        redirect('/admin/' . $type['key'] . '/' . $id . '/edit');
    }

    public static function update(array $p): void
    {
        csrf_check();
        $type = self::type($p);
        $user = require_permission($type['permission']);
        $row = content_find($type, (int) $p['id']);
        if ($row === null) {
            abort(404);
        }
        // Optimistic lock for pages (§12): warn when someone else saved first.
        if ($type['key'] === 'pages' && input_int('revision', -1) !== (int) $row['revision']) {
            $by = $row['updated_by'] ? (string) q_val('SELECT display_name FROM users WHERE id = ?', [$row['updated_by']]) : 'someone';
            flash('This page was edited by ' . $by . ' at ' . fmt_date($row['updated_at'], 'H:i') . ' while you were working. Your changes were not saved: reload and try again.', 'error');
            redirect('/admin/pages/' . $row['id'] . '/edit');
        }
        [$input, $uploadErrors] = self::input($type, (int) $user['id']);
        [$data, $errors] = content_validate($type, $input, $row);
        $errors += $uploadErrors;
        if ($errors) {
            self::form($type, $input + ['id' => $row['id']], $errors, $row, 422);
            return;
        }
        content_save($type, $data, $input, (int) $row['id'], (int) $user['id']);
        audit('update', $type['item'], (int) $row['id'], (string) ($data[$type['title']] ?? ''));
        flash($type['label'] . ' saved.');
        redirect('/admin/' . $type['key'] . '/' . $row['id'] . '/edit');
    }

    public static function delete(array $p): void
    {
        csrf_check();
        $type = self::type($p);
        require_permission($type['permission']);
        $row = content_find($type, (int) $p['id']);
        if ($row === null) {
            abort(404);
        }
        try {
            content_delete($type, (int) $row['id']);
        } catch (\DomainException $e) {
            flash($e->getMessage(), 'error');
            redirect('/admin/' . $type['key'] . '/' . $row['id'] . '/edit');
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            flash('This ' . strtolower($type['label']) . ' is still used elsewhere (for example leaders in a group), so it cannot be deleted yet.', 'error');
            redirect('/admin/' . $type['key'] . '/' . $row['id'] . '/edit');
        }
        audit('delete', $type['item'], (int) $row['id'], (string) ($row[$type['title']] ?? ''));
        flash($type['label'] . ' deleted.' . (in_array($type['item'], ['page', 'post', 'event', 'album', 'leader', 'leader_group', 'download'], true) ? ' Any menu links to it are flagged in Menus.' : ''));
        redirect('/admin/' . $type['key']);
    }
}
