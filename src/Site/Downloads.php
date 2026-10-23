<?php
declare(strict_types=1);

namespace Site;

final class Downloads
{
    public static function index(array $p): void
    {
        $viewer = current_user();
        $category = query_param('category');
        $cats = download_categories();
        $where = published_sql('d');
        $params = [];
        if ($category !== '' && in_array($category, $cats, true)) {
            $where .= ' AND d.category = ?';
            $params[] = $category;
        } else {
            $category = '';
        }
        // Members-only files are listed for everyone (with a lock) so visitors know they exist.
        $rows = q_all("SELECT d.*, m.mime, m.size, m.path FROM downloads d JOIN media m ON m.id = d.file_media_id WHERE $where ORDER BY d.category, d.title", $params);
        render('site/downloads', [
            'title' => 'Downloads', 'rows' => $rows, 'categories' => array_combine($cats, $cats) ?: [], 'category' => $category,
            'is_member' => viewer_is_member($viewer), 'canonical' => '/downloads',
        ]);
    }

    public static function file(array $p): void
    {
        $viewer = current_user();
        $d = q_one('SELECT * FROM downloads WHERE id = ? AND ' . published_sql(), [(int) $p['id']]);
        if ($d === null) {
            abort(404);
        }
        if ($d['visibility'] === 'members' && !viewer_is_member($viewer)) {
            if ($viewer === null) {
                flash(t('members.login_to_view'), 'info');
                redirect('/login?next=' . rawurlencode('/downloads'));
            }
            abort(403);
        }
        $m = media_find((int) $d['file_media_id']);
        $file = $m ? media_file_path($m) : null;
        if ($m === null || $file === null) {
            abort(404);
        }
        // Count full downloads only (not range requests for later chunks).
        if (!isset($_SERVER['HTTP_RANGE']) || str_starts_with((string) $_SERVER['HTTP_RANGE'], 'bytes=0-')) {
            q('UPDATE downloads SET download_count = download_count + 1 WHERE id = ?', [$d['id']]);
        }
        $ext = strtolower(pathinfo((string) $m['path'], PATHINFO_EXTENSION));
        send_file($file, (string) $m['mime'], ($d['slug'] ?: 'download') . '.' . $ext, in_array($m['kind'], ['audio', 'video'], true) || $m['mime'] === 'application/pdf', $d['visibility'] === 'public');
    }
}
