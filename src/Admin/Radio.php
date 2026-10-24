<?php
declare(strict_types=1);

namespace Admin;

use Core\UploadException;

/** Radio admin (§6): library, categories, playlists, schedule, station on/off. */
final class Radio
{
    // ---- Library ----------------------------------------------------------------------

    public static function library(array $p): void
    {
        require_permission('radio');
        $old = take_old_input();
        $q = mb_substr(query_param('q'), 0, 100);
        $params = [];
        $where = '';
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where = 'WHERE r.title LIKE ? OR r.artist LIKE ?';
            $params = [$like, $like];
        }
        $rows = q_all(radio_media_sql() . " $where ORDER BY r.created_at DESC, r.id DESC LIMIT 300", $params);
        media_preload(array_filter(array_map('intval', array_column($rows, 'cover_media_id'))));
        render('admin/radio_library', [
            'title' => 'Radio library', 'rows' => $rows, 'q' => $q, 'values' => $old['values'], 'errors' => $old['errors'],
            'categories' => q_all('SELECT c.*, (SELECT COUNT(*) FROM radio_media r WHERE r.category_id = c.id) AS n FROM radio_categories c ORDER BY sort, name'),
            'playlists' => q_all('SELECT id, name FROM radio_playlists ORDER BY name'),
            'enabled' => radio_enabled(), 'loop_id' => radio_loop_playlist_id(), 'now' => radio_now_playing(),
            'upload_limit' => self::uploadLimit(),
        ], 'admin');
    }

    private static function uploadLimit(): string
    {
        return (string) ini_get('upload_max_filesize');
    }

    /** @return array{0:array,1:array} validated metadata fields */
    private static function meta(): array
    {
        $v = [
            'title' => input('title'), 'artist' => input('artist'), 'description' => mb_substr(trim(is_string($_POST['description'] ?? null) ? $_POST['description'] : ''), 0, 5001),
            'category_id' => input_int('category_id'), 'on_demand' => input('on_demand') === '1' ? 1 : 0, 'publish' => input('publish_at'),
        ];
        $e = [];
        if ($v['title'] === '' || mb_strlen($v['title']) > 200) {
            $e['title'] = 'Enter a title (up to 200 characters).';
        }
        if (mb_strlen($v['artist']) > 160) {
            $e['artist'] = 'Keep this under 160 characters.';
        }
        if (mb_strlen($v['description']) > 5000) {
            $e['description'] = 'Keep the description under 5,000 characters.';
        }
        if ($v['category_id'] > 0 && q_val('SELECT id FROM radio_categories WHERE id = ?', [$v['category_id']]) === null) {
            $e['category_id'] = 'Choose a category.';
        }
        $publish = null;
        if ($v['publish'] !== '') {
            $publish = local_to_utc($v['publish']);
            if ($publish === null) {
                $e['publish_at'] = 'Enter a valid date and time.';
            }
        }
        return [[
            'title' => $v['title'], 'artist' => $v['artist'], 'description' => $v['description'], 'category_id' => $v['category_id'] > 0 ? $v['category_id'] : null,
            'on_demand' => $v['on_demand'], 'publish_at' => $publish,
        ], $e];
    }

    /** Optional cover image upload; returns media id, null, or throws. */
    private static function cover(int $userId, string $alt): ?int
    {
        $f = $_FILES['cover_upload'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $u = media_upload_from_files($f);
        return media_store($u['tmp'], $u['name'], $u['size'], $userId, true, ['kind' => 'image', 'folder' => 'radio', 'alt' => $alt]);
    }

    public static function upload(array $p): void
    {
        csrf_check();
        $user = require_permission('radio');
        [$data, $errors] = self::meta();
        $duration = radio_parse_duration(input('duration'));
        $file = $_FILES['file'] ?? null;
        $mediaId = null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $errors['file'] = 'Choose an audio (MP3, M4A, OGG) or video (MP4, WebM) file.';
        } elseif (!$errors) {
            try {
                $u = media_upload_from_files($file);
                $info = media_validate($u['tmp'], $u['name'], $u['size']);
                if (!in_array($info['kind'], ['audio', 'video'], true)) {
                    throw new UploadException('Choose an audio (MP3, M4A, OGG) or video (MP4, WebM) file.');
                }
                $mediaId = media_store($u['tmp'], $u['name'], $u['size'], (int) $user['id'], true, ['folder' => 'radio', 'visibility' => 'public', 'title' => $data['title']]);
            } catch (UploadException $e) {
                $errors['file'] = $e->getMessage();
            }
        }
        $coverId = null;
        if (!$errors) {
            try {
                $coverId = self::cover((int) $user['id'], $data['title']);
            } catch (UploadException $e) {
                $errors['cover'] = $e->getMessage();
            }
        }
        if ($errors) {
            if ($mediaId !== null) {
                media_delete($mediaId);
            }
            remember_input($_POST, $errors);
            redirect('/admin/radio#upload');
        }
        if ($duration !== null) {
            q('UPDATE media SET duration = ? WHERE id = ?', [$duration, $mediaId]);
        }
        $id = db_insert('radio_media', $data + ['media_id' => $mediaId, 'cover_media_id' => $coverId, 'duration' => $duration, 'created_by' => $user['id']]);
        audit('upload', 'radio_media', $id, $data['title']);
        flash($duration !== null ? 'Uploaded “' . $data['title'] . '” (' . radio_fmt_duration($duration) . ').' : 'Uploaded “' . $data['title'] . '”. Its duration is unknown, so it cannot be scheduled until you measure it.', $duration !== null ? 'success' : 'info');
        redirect($duration !== null ? '/admin/radio' : '/admin/radio/media/' . $id);
    }

    private static function findMedia(int $id): array
    {
        $r = radio_media_find($id);
        if ($r === null) {
            abort(404);
        }
        return $r;
    }

    public static function editMedia(array $p): void
    {
        require_permission('radio');
        $r = self::findMedia((int) $p['id']);
        $old = take_old_input();
        render('admin/radio_media_edit', [
            'title' => $r['title'], 'r' => $r, 'values' => $old['values'], 'errors' => $old['errors'], 'usage' => radio_media_usage((int) $r['id']),
            'categories' => q_all('SELECT id, name FROM radio_categories ORDER BY sort, name'), 'url' => radio_media_json($r)['url'],
            'cover' => media_find($r['cover_media_id'] ? (int) $r['cover_media_id'] : null),
        ], 'admin');
    }

    public static function updateMedia(array $p): void
    {
        csrf_check();
        $user = require_permission('radio');
        $r = self::findMedia((int) $p['id']);
        [$data, $errors] = self::meta();
        $durationRaw = input('duration');
        $duration = radio_parse_duration($durationRaw);
        if ($durationRaw !== '' && $duration === null) {
            $errors['duration'] = 'The measured duration is not valid. Use “Measure duration” again.';
        }
        $usage = radio_media_usage((int) $r['id']);
        $changed = $duration !== null && ($r['duration'] === null || abs((float) $r['duration'] - $duration) > 0.01);
        if ($changed && $usage !== [] && $r['duration'] !== null) {
            $errors['duration'] = 'This programme is used in ' . implode(', ', $usage) . '; its duration cannot change while it is scheduled.';
        }
        $coverId = null;
        if (!$errors) {
            try {
                $coverId = self::cover((int) $user['id'], $data['title']);
            } catch (UploadException $e) {
                $errors['cover'] = $e->getMessage();
            }
        }
        if ($errors) {
            remember_input($_POST, $errors);
            redirect('/admin/radio/media/' . $r['id']);
        }
        $update = $data;
        if ($coverId !== null) {
            $update['cover_media_id'] = $coverId;
        } elseif (input('cover_remove') === '1') {
            $update['cover_media_id'] = null;
        }
        if ($duration !== null) {
            $update['duration'] = $duration;
            q('UPDATE media SET duration = ? WHERE id = ?', [$duration, $r['media_id']]);
        }
        db_update('radio_media', (int) $r['id'], $update);
        audit('update', 'radio_media', (int) $r['id'], $data['title']);
        flash('Saved.');
        redirect('/admin/radio/media/' . $r['id']);
    }

    public static function deleteMedia(array $p): void
    {
        csrf_check();
        require_permission('radio');
        $r = self::findMedia((int) $p['id']);
        $usage = radio_media_usage((int) $r['id']);
        if ($usage !== []) {
            flash('“' . $r['title'] . '” is used in ' . implode(', ', $usage) . '. Remove it from there first.', 'error');
            redirect('/admin/radio/media/' . $r['id']);
        }
        q('DELETE FROM radio_media WHERE id = ?', [$r['id']]);
        try {
            media_delete((int) $r['media_id']);
        } catch (UploadException) {
            // The file is also used elsewhere: keep it.
        }
        audit('delete', 'radio_media', (int) $r['id'], $r['title']);
        flash('Deleted “' . $r['title'] . '”.');
        redirect('/admin/radio');
    }

    // ---- Categories and station --------------------------------------------------------------

    public static function addCategory(array $p): void
    {
        csrf_check();
        require_permission('radio');
        $name = input('name');
        if ($name === '' || mb_strlen($name) > 80) {
            flash('Enter a category name (up to 80 characters).', 'error');
            redirect('/admin/radio');
        }
        $slug = unique_slug('radio_categories', slugify($name, 80) ?: 'category');
        $catId = db_insert('radio_categories', ['name' => $name, 'slug' => $slug, 'sort' => (int) q_val('SELECT COALESCE(MAX(sort), 0) + 1 FROM radio_categories')]);
        audit('create', 'radio_category', $catId, $name);
        flash('Category added.');
        redirect('/admin/radio');
    }

    public static function deleteCategory(array $p): void
    {
        csrf_check();
        require_permission('radio');
        $cat = q_one('SELECT id, name FROM radio_categories WHERE id = ?', [(int) $p['id']]);
        q('DELETE FROM radio_categories WHERE id = ?', [(int) $p['id']]);
        if ($cat !== null) {
            audit('delete', 'radio_category', (int) $cat['id'], (string) $cat['name']);
        }
        flash('Category removed (its programmes are kept, without a category).');
        redirect('/admin/radio');
    }

    public static function station(array $p): void
    {
        csrf_check();
        require_permission('radio');
        $loop = input_int('loop_playlist_id');
        if ($loop > 0) {
            if (q_val('SELECT id FROM radio_playlists WHERE id = ?', [$loop]) === null) {
                flash('Choose a playlist for the loop.', 'error');
                redirect('/admin/radio');
            }
            if (radio_playlist_duration($loop) === null) {
                flash('The loop playlist must have at least one item, and every item needs a known duration.', 'error');
                redirect('/admin/radio');
            }
        }
        setting_set('radio.enabled', input('enabled') === '1');
        setting_set('radio.loop_playlist_id', $loop > 0 ? $loop : null);
        audit('update', 'settings', null, 'radio station ' . (input('enabled') === '1' ? 'on' : 'off'));
        flash(input('enabled') === '1' ? 'The station is switched on.' : 'The station is switched off.');
        redirect('/admin/radio');
    }

    // ---- Playlists ------------------------------------------------------------------------------

    public static function playlists(array $p): void
    {
        require_permission('radio');
        $rows = q_all('SELECT p.*, (SELECT COUNT(*) FROM radio_playlist_items i WHERE i.playlist_id = p.id) AS n,
                (SELECT COUNT(*) FROM radio_slots s WHERE s.playlist_id = p.id) AS slots FROM radio_playlists p ORDER BY p.name');
        foreach ($rows as &$r) {
            $r['duration'] = radio_playlist_duration((int) $r['id']);
        }
        unset($r);
        render('admin/radio_playlists', ['title' => 'Playlists', 'rows' => $rows, 'loop_id' => radio_loop_playlist_id()], 'admin');
    }

    public static function createPlaylist(array $p): void
    {
        csrf_check();
        require_permission('radio');
        $name = input('name');
        if ($name === '' || mb_strlen($name) > 120) {
            flash('Enter a playlist name (up to 120 characters).', 'error');
            redirect('/admin/radio/playlists');
        }
        $id = db_insert('radio_playlists', ['name' => $name, 'description' => mb_substr(input('description'), 0, 500)]);
        audit('create', 'radio_playlist', $id, $name);
        redirect('/admin/radio/playlists/' . $id);
    }

    private static function findPlaylist(int $id): array
    {
        $pl = q_one('SELECT * FROM radio_playlists WHERE id = ?', [$id]);
        if ($pl === null) {
            abort(404);
        }
        return $pl;
    }

    public static function playlist(array $p): void
    {
        require_permission('radio');
        $pl = self::findPlaylist((int) $p['id']);
        render('admin/radio_playlist', [
            'title' => $pl['name'], 'pl' => $pl, 'items' => radio_playlist_items((int) $pl['id']), 'duration' => radio_playlist_duration((int) $pl['id']),
            'library' => q_all('SELECT r.id, r.title, r.artist, r.duration, m.kind FROM radio_media r JOIN media m ON m.id = r.media_id ORDER BY r.title'),
            'slots' => (int) q_val('SELECT COUNT(*) FROM radio_slots WHERE playlist_id = ?', [$pl['id']]), 'is_loop' => radio_loop_playlist_id() === (int) $pl['id'],
        ], 'admin');
    }

    /** op: save (name), add (media), up:i, down:i, remove:i. Scheduled slots are resynced and re-checked. */
    public static function updatePlaylist(array $p): void
    {
        csrf_check();
        require_permission('radio');
        $pl = self::findPlaylist((int) $p['id']);
        $op = input('op', 'save');
        $ids = array_map(static fn (array $i): int => (int) $i['radio_media_id'], q_all('SELECT radio_media_id FROM radio_playlist_items WHERE playlist_id = ? ORDER BY sort, id', [$pl['id']]));
        if ($op === 'add') {
            $add = input_int('radio_media_id');
            if (radio_media_find($add) === null) {
                flash('Choose a programme to add.', 'error');
                redirect('/admin/radio/playlists/' . $pl['id']);
            }
            $ids[] = $add;
        } elseif (preg_match('/^(up|down|remove):(\d+)$/', $op, $m) && isset($ids[(int) $m[2]])) {
            $i = (int) $m[2];
            if ($m[1] === 'up' && $i > 0) {
                [$ids[$i - 1], $ids[$i]] = [$ids[$i], $ids[$i - 1]];
            } elseif ($m[1] === 'down' && $i < count($ids) - 1) {
                [$ids[$i + 1], $ids[$i]] = [$ids[$i], $ids[$i + 1]];
            } elseif ($m[1] === 'remove') {
                array_splice($ids, $i, 1);
            }
        }
        $name = input('name', (string) $pl['name']);
        if ($name === '' || mb_strlen($name) > 120) {
            flash('Enter a playlist name (up to 120 characters).', 'error');
            redirect('/admin/radio/playlists/' . $pl['id']);
        }
        try {
            radio_schedule_lock_row();
            db_tx(static function () use ($pl, $ids, $name): void {
                radio_schedule_lock();
                db_update('radio_playlists', (int) $pl['id'], ['name' => $name, 'description' => mb_substr(input('description', (string) $pl['description']), 0, 500)]);
                q('DELETE FROM radio_playlist_items WHERE playlist_id = ?', [$pl['id']]);
                foreach ($ids as $sort => $rid) {
                    q('INSERT INTO radio_playlist_items (playlist_id, radio_media_id, sort) VALUES (?, ?, ?)', [$pl['id'], $rid, $sort]);
                }
                radio_playlist_resync_slots((int) $pl['id']);
            });
        } catch (\RuntimeException $e) {
            flash($e->getMessage() . ' Nothing was changed.', 'error');
            redirect('/admin/radio/playlists/' . $pl['id']);
        }
        audit('update', 'radio_playlist', (int) $pl['id'], $name . ($op !== 'save' ? ' (' . $op . ')' : ''));
        flash('Playlist saved.');
        redirect('/admin/radio/playlists/' . $pl['id']);
    }

    public static function deletePlaylist(array $p): void
    {
        csrf_check();
        require_permission('radio');
        $pl = self::findPlaylist((int) $p['id']);
        if ((int) q_val('SELECT COUNT(*) FROM radio_slots WHERE playlist_id = ?', [$pl['id']]) > 0 || radio_loop_playlist_id() === (int) $pl['id']) {
            flash('This playlist is scheduled or used as the fallback loop. Remove it from there first.', 'error');
            redirect('/admin/radio/playlists/' . $pl['id']);
        }
        q('DELETE FROM radio_playlists WHERE id = ?', [$pl['id']]);
        audit('delete', 'radio_playlist', (int) $pl['id'], $pl['name']);
        flash('Playlist deleted.');
        redirect('/admin/radio/playlists');
    }

    // ---- Schedule -------------------------------------------------------------------------------

    /** Week calendar (Monday start, site time zone) plus the list of slots. */
    public static function schedule(array $p): void
    {
        require_permission('radio');
        $tz = site_tz();
        $weekParam = query_param('week');
        $base = preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekParam) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $weekParam, $tz) : false;
        $base = $base ?: new \DateTimeImmutable('now', $tz);
        $monday = $base->setTime(0, 0)->modify('-' . ((int) $base->format('N') - 1) . ' days');
        $slots = q_all('SELECT * FROM radio_slots ORDER BY start_at');
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $monday->modify("+$i days");
            $days[$d->format('Y-m-d')] = ['date' => $d, 'items' => []];
        }
        $from = $monday->getTimestamp();
        $to = $monday->modify('+7 days')->getTimestamp();
        foreach ($slots as $slot) {
            foreach (radio_slot_occurrences($slot, $from, $to, $tz) as [$s, $e]) {
                // Split occurrences that cross midnight so each day column shows its part.
                $cur = $s;
                while ($cur < $e) {
                    $local = (new \DateTimeImmutable('@' . $cur))->setTimezone($tz);
                    $dayEnd = $local->setTime(0, 0)->modify('+1 day')->getTimestamp();
                    $partEnd = min($e, $dayEnd);
                    $key = $local->format('Y-m-d');
                    if (isset($days[$key])) {
                        $days[$key]['items'][] = [
                            'slot' => $slot, 'start' => $cur, 'end' => $partEnd, 'label' => radio_slot_label($slot),
                            'start_min' => (int) $local->format('G') * 60 + (int) $local->format('i'), 'len_min' => max(1, (int) round(($partEnd - $cur) / 60)),
                            'continued' => $cur !== $s,
                        ];
                    }
                    $cur = $partEnd;
                }
            }
        }
        foreach ($days as &$d) {
            usort($d['items'], static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        }
        unset($d);
        $old = take_old_input();
        render('admin/radio_schedule', [
            'title' => 'Radio schedule', 'days' => $days, 'monday' => $monday, 'slots' => $slots, 'values' => $old['values'], 'errors' => $old['errors'],
            'sources' => self::sources(), 'loop' => radio_loop_playlist_id(), 'enabled' => radio_enabled(), 'now' => radio_now_playing(), 'tz' => $tz,
            'body_class' => 'wide-mode',
        ], 'admin');
    }

    /** Media items and playlists for the slot form, with their durations. */
    private static function sources(): array
    {
        $media = q_all('SELECT r.id, r.title, r.artist, r.duration, m.kind FROM radio_media r JOIN media m ON m.id = r.media_id ORDER BY r.title');
        $lists = q_all('SELECT id, name FROM radio_playlists ORDER BY name');
        foreach ($lists as &$l) {
            $l['duration'] = radio_playlist_duration((int) $l['id']);
        }
        unset($l);
        return ['media' => $media, 'playlists' => $lists];
    }

    public static function createSlot(array $p): void
    {
        csrf_check();
        $user = require_permission('radio');
        [$id, $row, $errors] = radio_slot_save($_POST, null, (int) $user['id']);
        if ($errors || $id === null) {
            remember_input($_POST, $errors);
            redirect('/admin/radio/schedule' . (input('week') !== '' ? '?week=' . rawurlencode(input('week')) : '') . '#slot-form');
        }
        audit('create', 'radio_slot', $id, radio_slot_label($row));
        flash('Added “' . radio_slot_label($row) . '” to the schedule.');
        redirect('/admin/radio/schedule?week=' . utc_to_local($row['start_at'])->format('Y-m-d'));
    }

    private static function findSlot(int $id): array
    {
        $s = q_one('SELECT * FROM radio_slots WHERE id = ?', [$id]);
        if ($s === null) {
            abort(404);
        }
        return $s;
    }

    public static function editSlot(array $p): void
    {
        require_permission('radio');
        $slot = self::findSlot((int) $p['id']);
        $old = take_old_input();
        $values = $old['values'] + [
            'title' => $slot['title'], 'start' => utc_to_input($slot['start_at']), 'repeat_rule' => $slot['repeat_rule'],
            'days' => array_map('strval', radio_mask_days((int) $slot['days_mask'])), 'repeat_until' => (string) ($slot['repeat_until'] ?? ''),
            'source' => $slot['media_id'] ? 'media:' . $slot['media_id'] : 'playlist:' . $slot['playlist_id'],
        ];
        render('admin/radio_slot_edit', ['title' => 'Edit slot', 'slot' => $slot, 'values' => $values, 'errors' => $old['errors'], 'sources' => self::sources()], 'admin');
    }

    public static function updateSlot(array $p): void
    {
        csrf_check();
        require_permission('radio');
        $slot = self::findSlot((int) $p['id']);
        [, $row, $errors] = radio_slot_save($_POST, (int) $slot['id']);
        if ($errors) {
            remember_input($_POST, $errors);
            redirect('/admin/radio/schedule/' . $slot['id']);
        }
        audit('update', 'radio_slot', (int) $slot['id'], radio_slot_label($row));
        flash('Slot saved.');
        redirect('/admin/radio/schedule?week=' . utc_to_local($row['start_at'])->format('Y-m-d'));
    }

    public static function deleteSlot(array $p): void
    {
        csrf_check();
        require_permission('radio');
        $slot = self::findSlot((int) $p['id']);
        q('DELETE FROM radio_slots WHERE id = ?', [$slot['id']]);
        audit('delete', 'radio_slot', (int) $slot['id'], radio_slot_label($slot));
        flash('Slot removed from the schedule.');
        redirect('/admin/radio/schedule');
    }
}
