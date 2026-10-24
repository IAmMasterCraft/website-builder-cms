<?php
declare(strict_types=1);

namespace Admin;

use Core\UploadException;

/** Photo management inside an album: upload, captions/alt, order, remove. */
final class Albums
{
    private static function album(array $p): array
    {
        $a = q_one('SELECT * FROM albums WHERE id = ?', [(int) $p['id']]);
        if ($a === null) {
            abort(404);
        }
        return $a;
    }

    public static function photos(array $p): void
    {
        require_permission('gallery_media');
        $album = self::album($p);
        $photos = q_all('SELECT * FROM album_photos WHERE album_id = ? ORDER BY sort, id', [$album['id']]);
        media_preload(array_column($photos, 'media_id'));
        render('admin/album_photos', ['title' => 'Photos: ' . $album['title'], 'album' => $album, 'photos' => $photos], 'admin');
    }

    public static function upload(array $p): void
    {
        csrf_check();
        $user = require_permission('gallery_media');
        $album = self::album($p);
        $files = media_files_list($_FILES['photos'] ?? []);
        $sort = (int) q_val('SELECT COALESCE(MAX(sort), -1) + 1 FROM album_photos WHERE album_id = ?', [$album['id']]);
        $ok = 0;
        $failed = [];
        foreach (array_slice($files, 0, 50) as $f) {
            try {
                $u = media_upload_from_files($f);
                $mid = media_store($u['tmp'], $u['name'], $u['size'], (int) $user['id'], true, [
                    'kind' => 'image', 'folder' => 'gallery', 'visibility' => $album['visibility'] === 'members' ? 'members' : 'public',
                ]);
                db_insert('album_photos', ['album_id' => $album['id'], 'media_id' => $mid, 'sort' => $sort++]);
                $ok++;
            } catch (UploadException $e) {
                $failed[] = basename((string) $f['name']) . ': ' . $e->getMessage();
            }
        }
        if ($ok > 0 && $album['cover_media_id'] === null) {
            db_update('albums', (int) $album['id'], ['cover_media_id' => (int) q_val('SELECT media_id FROM album_photos WHERE album_id = ? ORDER BY sort, id LIMIT 1', [$album['id']])]);
        }
        audit('upload', 'album', (int) $album['id'], "$ok photo(s)");
        flash($failed ? ($ok ? "$ok added. " : '') . 'Not added: ' . implode(' ', $failed) : pluralise($ok, 'photo') . ' added.', $failed ? 'error' : 'success');
        redirect('/admin/albums/' . $album['id'] . '/photos');
    }

    public static function save(array $p): void
    {
        csrf_check();
        require_permission('gallery_media');
        $album = self::album($p);
        $captions = is_array($_POST['caption'] ?? null) ? $_POST['caption'] : [];
        $alts = is_array($_POST['alt'] ?? null) ? $_POST['alt'] : [];
        foreach ($captions as $id => $caption) {
            if (!is_string($caption) || !ctype_digit((string) $id)) {
                continue;
            }
            $alt = isset($alts[$id]) && is_string($alts[$id]) ? $alts[$id] : '';
            q('UPDATE album_photos SET caption = ?, alt = ? WHERE id = ? AND album_id = ?', [mb_substr(trim($caption), 0, 300), mb_substr(trim($alt), 0, 300), (int) $id, $album['id']]);
        }
        audit('update', 'album', (int) $album['id'], 'captions and alt text');
        flash('Captions saved.');
        redirect('/admin/albums/' . $album['id'] . '/photos');
    }

    public static function move(array $p): void
    {
        csrf_check();
        require_permission('gallery_media');
        $album = self::album($p);
        $ids = array_map('intval', array_column(q_all('SELECT id FROM album_photos WHERE album_id = ? ORDER BY sort, id', [$album['id']]), 'id'));
        $pos = array_search((int) $p['photo'], $ids, true);
        $swap = input('dir') === 'up' ? $pos - 1 : $pos + 1;
        if ($pos !== false && $swap >= 0 && $swap < count($ids)) {
            [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
            db_tx(static function () use ($ids): void {
                foreach ($ids as $i => $id) {
                    q('UPDATE album_photos SET sort = ? WHERE id = ?', [$i, $id]);
                }
            });
            audit('move', 'album_photo', (int) $p['photo'], 'album #' . $album['id'] . ' ' . (input('dir') === 'up' ? 'up' : 'down'));
        }
        redirect('/admin/albums/' . $album['id'] . '/photos#photo-' . (int) $p['photo']);
    }

    public static function remove(array $p): void
    {
        csrf_check();
        require_permission('gallery_media');
        $album = self::album($p);
        $photo = q_one('SELECT * FROM album_photos WHERE id = ? AND album_id = ?', [(int) $p['photo'], $album['id']]);
        if ($photo === null) {
            abort(404);
        }
        q('DELETE FROM album_photos WHERE id = ?', [$photo['id']]);
        // Delete the file too when nothing else uses it.
        if (input('delete_file') === '1' && media_usage((int) $photo['media_id']) === []) {
            media_delete((int) $photo['media_id']);
        }
        audit('delete', 'album_photo', (int) $photo['id'], 'from album #' . $album['id'] . (input('delete_file') === '1' ? ' (file deleted if unused)' : ''));
        flash('Photo removed from the album.');
        redirect('/admin/albums/' . $album['id'] . '/photos');
    }
}
