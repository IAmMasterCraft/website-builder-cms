<?php
declare(strict_types=1);

namespace Admin;

/**
 * Comments (§4.7, Addendum A1): published straight away, so this is a
 * reports queue rather than an approval queue. Admins keep, hide, show again
 * or delete comments; comments hidden automatically after reports from
 * several members wait here for a decision.
 */
final class Comments
{
    public const TABS = ['reported' => 'Reports', 'hidden' => 'Hidden', 'approved' => 'Published'];

    public static function index(array $p): void
    {
        require_permission('content');
        $tab = query_param('tab');
        $tab = array_key_exists($tab, self::TABS) ? $tab : 'reported';
        $base = "SELECT c.*, u.display_name, p.title AS item_title, p.slug AS item_slug, cat.base AS item_base, par.body AS parent_body,
                    (SELECT COUNT(*) FROM comment_reports r WHERE r.comment_id = c.id AND r.resolved_at IS NULL) AS open_reports
                   FROM comments c LEFT JOIN users u ON u.id = c.user_id
                   LEFT JOIN posts p ON c.item_type = 'post' AND p.id = c.item_id LEFT JOIN categories cat ON cat.id = p.category_id
                   LEFT JOIN comments par ON par.id = c.parent_id";
        $rows = $tab === 'reported'
            ? q_all("$base WHERE c.deleted_at IS NULL AND EXISTS (SELECT 1 FROM comment_reports r WHERE r.comment_id = c.id AND r.resolved_at IS NULL) ORDER BY c.created_at DESC LIMIT 200")
            : q_all("$base WHERE c.status = ? AND c.deleted_at IS NULL ORDER BY c.created_at DESC LIMIT 200", [$tab]);
        $reports = [];
        foreach ($rows as $r) {
            if ((int) $r['open_reports'] > 0) {
                $reports[(int) $r['id']] = q_all('SELECT r.reason, u.display_name FROM comment_reports r LEFT JOIN users u ON u.id = r.user_id WHERE r.comment_id = ? AND r.resolved_at IS NULL', [$r['id']]);
            }
        }
        $counts = [];
        foreach (q_all('SELECT status, COUNT(*) AS n FROM comments WHERE deleted_at IS NULL GROUP BY status') as $c) {
            $counts[$c['status']] = (int) $c['n'];
        }
        $counts['reported'] = comments_reported_count();
        render('admin/comments_index', ['title' => 'Comments', 'rows' => $rows, 'tab' => $tab, 'counts' => $counts, 'reports' => $reports], 'admin');
    }

    public static function moderate(array $p): void
    {
        csrf_check();
        $user = require_permission('content');
        $action = input('action');
        $ok = in_array($action, ['keep', 'hide', 'unhide', 'delete'], true) && comment_moderate((int) $p['id'], $action, (int) $user['id']);
        if ($ok) {
            audit($action, 'comment', (int) $p['id']);
        }
        $tab = input('tab');
        flash($ok ? ['keep' => 'Reports dismissed; the comment stays published.', 'hide' => 'Comment hidden.', 'unhide' => 'Comment shown again.', 'delete' => 'Comment deleted.'][$action] : 'Nothing changed.', $ok ? 'success' : 'error');
        redirect('/admin/comments' . (array_key_exists($tab, self::TABS) && $tab !== 'reported' ? '?tab=' . $tab : ''));
    }
}
