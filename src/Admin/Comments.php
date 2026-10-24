<?php
declare(strict_types=1);

namespace Admin;

/** Comment moderation queue (§4.7, D11). */
final class Comments
{
    public const TABS = ['pending' => 'Awaiting approval', 'reported' => 'Reported', 'approved' => 'Published', 'rejected' => 'Rejected'];

    public static function index(array $p): void
    {
        require_permission('content');
        $tab = query_param('tab');
        $tab = array_key_exists($tab, self::TABS) ? $tab : 'pending';
        $base = "SELECT c.*, u.display_name, p.title AS item_title, p.slug AS item_slug, cat.base AS item_base, par.body AS parent_body,
                    (SELECT COUNT(*) FROM comment_reports r WHERE r.comment_id = c.id AND r.resolved_at IS NULL) AS open_reports
                   FROM comments c LEFT JOIN users u ON u.id = c.user_id
                   LEFT JOIN posts p ON c.item_type = 'post' AND p.id = c.item_id LEFT JOIN categories cat ON cat.id = p.category_id
                   LEFT JOIN comments par ON par.id = c.parent_id";
        $rows = $tab === 'reported'
            ? q_all("$base WHERE EXISTS (SELECT 1 FROM comment_reports r WHERE r.comment_id = c.id AND r.resolved_at IS NULL) ORDER BY c.created_at DESC LIMIT 200")
            : q_all("$base WHERE c.status = ? AND c.deleted_at IS NULL ORDER BY c.created_at " . ($tab === 'pending' ? 'ASC' : 'DESC') . ' LIMIT 200', [$tab]);
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
        $counts['reported'] = (int) q_val('SELECT COUNT(DISTINCT comment_id) FROM comment_reports WHERE resolved_at IS NULL');
        render('admin/comments_index', ['title' => 'Comments', 'rows' => $rows, 'tab' => $tab, 'counts' => $counts, 'reports' => $reports, 'mode' => comments_moderation()], 'admin');
    }

    public static function moderate(array $p): void
    {
        csrf_check();
        $user = require_permission('content');
        $action = input('action');
        $ok = comment_moderate((int) $p['id'], $action, (int) $user['id']);
        if ($ok) {
            audit($action, 'comment', (int) $p['id']);
        }
        $tab = input('tab');
        flash($ok ? ['approve' => 'Comment published.', 'reject' => 'Comment rejected (hidden).', 'delete' => 'Comment deleted.'][$action] ?? 'Done.' : 'Nothing changed.', $ok ? 'success' : 'error');
        redirect('/admin/comments' . (array_key_exists($tab, self::TABS) && $tab !== 'pending' ? '?tab=' . $tab : ''));
    }

    public static function settings(array $p): void
    {
        csrf_check();
        require_permission('content');
        setting_set('comments.moderation', input('moderation') === 'post' ? 'post' : 'pre');
        audit('update', 'settings', null, 'comment moderation');
        flash('Comment settings saved.');
        redirect('/admin/comments');
    }
}
