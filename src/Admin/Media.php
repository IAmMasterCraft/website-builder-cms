<?php
declare(strict_types=1);

namespace Admin;

use Core\UploadException;

final class Media
{
    public const KINDS = ['image' => 'Images', 'document' => 'Documents', 'audio' => 'Audio', 'video' => 'Video'];

    /** @return array{0:string,1:array} WHERE clause + params for library filters */
    private static function filters(string $q, string $kind, string $folder): array
    {
        $where = ["visibility <> 'private'", "folder <> 'member-photos'"]; // member photos are managed from Members, not the library or picker
        $params = [];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(title LIKE ? OR original_name LIKE ? OR alt LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        if (isset(self::KINDS[$kind])) {
            $where[] = 'kind = ?';
            $params[] = $kind;
        }
        if ($folder !== '') {
            $where[] = 'folder = ?';
            $params[] = $folder;
        }
        return [implode(' AND ', $where), $params];
    }

    public static function index(array $p): void
    {
        require_permission('gallery_media');
        $q = mb_substr(query_param('q'), 0, 100);
        $kind = query_param('kind');
        $folder = slugify(query_param('folder'), 80);
        [$where, $params] = self::filters($q, $kind, $folder);
        $page = max(1, input_int('page', 1, true));
        $per = 48;
        $total = (int) q_val("SELECT COUNT(*) FROM media WHERE $where", $params);
        $rows = q_all("SELECT * FROM media WHERE $where ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params);
        $rows = array_map('media_decode', $rows);
        $folders = array_column(q_all("SELECT DISTINCT folder FROM media WHERE folder <> '' ORDER BY folder"), 'folder');
        render('admin/media_index', [
            'title' => 'Media library', 'rows' => $rows, 'total' => $total, 'page' => $page, 'per' => $per,
            'q' => $q, 'kind' => isset(self::KINDS[$kind]) ? $kind : '', 'folder' => $folder, 'folders' => $folders,
        ], 'admin');
    }

    /** JSON list of images for the media picker dialog. */
    public static function picker(array $p): void
    {
        require_staff();
        $q = mb_substr(query_param('q'), 0, 100);
        $kind = query_param('kind', 'image');
        [$where, $params] = self::filters($q, isset(self::KINDS[$kind]) ? $kind : 'image', '');
        $rows = array_map('media_decode', q_all("SELECT * FROM media WHERE $where ORDER BY id DESC LIMIT 60", $params));
        json_out(['items' => array_map(static fn (array $m): array => [
            'id' => (int) $m['id'],
            'title' => (string) $m['title'],
            'alt' => (string) $m['alt'],
            'kind' => (string) $m['kind'],
            'thumb' => $m['kind'] === 'image' ? media_url($m, 400) : null,
        ], $rows)]);
    }

    public static function upload(array $p): void
    {
        csrf_check();
        $user = require_permission('gallery_media');
        $files = media_files_list($_FILES['files'] ?? []);
        $folder = input('folder');
        $visibility = in_array(input('visibility'), ['public', 'members'], true) ? input('visibility') : 'public';
        if ($files === []) {
            flash('Choose one or more files to upload.', 'error');
            redirect('/admin/media');
        }
        $ok = 0;
        $failed = [];
        foreach (array_slice($files, 0, 50) as $f) {
            try {
                $u = media_upload_from_files($f);
                $id = media_store($u['tmp'], $u['name'], $u['size'], (int) $user['id'], true, ['folder' => $folder, 'visibility' => $visibility]);
                audit('upload', 'media', $id, $u['name']);
                $ok++;
            } catch (UploadException $e) {
                $failed[] = basename((string) $f['name']) . ': ' . $e->getMessage();
            }
        }
        if ($failed) {
            flash(($ok ? "$ok uploaded. " : '') . 'Not uploaded: ' . implode(' ', $failed), 'error');
        } else {
            flash(pluralise($ok, 'file') . ' uploaded.');
        }
        redirect('/admin/media');
    }

    private static function find(array $p): array
    {
        $m = media_find((int) $p['id']);
        // C3: message attachments are private to the conversation, never library items.
        if ($m === null || (string) $m['folder'] === 'messages') {
            abort(404);
        }
        return $m;
    }

    public static function edit(array $p): void
    {
        require_permission('gallery_media');
        $m = self::find($p);
        render('admin/media_edit', ['title' => 'Edit media', 'm' => $m, 'usage' => media_usage((int) $m['id']), 'errors' => []], 'admin');
    }

    public static function update(array $p): void
    {
        csrf_check();
        require_permission('gallery_media');
        $m = self::find($p);
        $title = input('title');
        $alt = input('alt');
        $errors = [];
        if ($title === '' || mb_strlen($title) > 200) {
            $errors['title'] = 'Enter a title (up to 200 characters).';
        }
        if (mb_strlen($alt) > 300) {
            $errors['alt'] = 'Alt text is limited to 300 characters.';
        }
        $focal = [];
        foreach (['focal_x' => 'Horizontal focal point', 'focal_y' => 'Vertical focal point'] as $k => $label) {
            $v = input($k, '50');
            if (!preg_match('/^\d{1,3}$/', $v) || (int) $v > 100) {
                $errors[$k] = "$label must be a whole number from 0 to 100.";
                $v = '50';
            }
            $focal[$k] = (int) $v;
        }
        if ($m['kind'] !== 'image') {
            $focal = [];
        }
        if ($errors) {
            render('admin/media_edit', ['title' => 'Edit media', 'm' => array_merge($m, ['title' => $title, 'alt' => $alt], $focal), 'usage' => media_usage((int) $m['id']), 'errors' => $errors], 'admin', 422);
            return;
        }
        db_update('media', (int) $m['id'], ['title' => $title, 'alt' => $alt, 'folder' => slugify(input('folder'), 80)] + $focal);
        audit('update', 'media', (int) $m['id'], $title);
        flash('Media details saved.');
        redirect('/admin/media/' . $m['id']);
    }

    public static function replace(array $p): void
    {
        csrf_check();
        require_permission('gallery_media');
        $m = self::find($p);
        try {
            $u = media_upload_from_files($_FILES['file'] ?? []);
            media_replace((int) $m['id'], $u['tmp'], $u['name'], $u['size']);
            audit('replace', 'media', (int) $m['id'], $u['name']);
            flash('File replaced. Everything that uses it now shows the new file.');
        } catch (UploadException $e) {
            flash($e->getMessage(), 'error');
        }
        redirect('/admin/media/' . $m['id']);
    }

    public static function delete(array $p): void
    {
        csrf_check();
        require_permission('gallery_media');
        $m = self::find($p);
        try {
            media_delete((int) $m['id']);
        } catch (UploadException $e) {
            flash($e->getMessage(), 'error');
            redirect('/admin/media/' . $m['id']);
        }
        audit('delete', 'media', (int) $m['id'], (string) $m['title']);
        flash('File deleted.');
        redirect('/admin/media');
    }
}
