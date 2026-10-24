<?php
declare(strict_types=1);

namespace Admin;

/**
 * Suppression list (Addendum A5): addresses that unsubscribed, complained or
 * bounced. Admins can clear a bounce suppression once the address is fixed;
 * unsubscribes and complaints are the person's own choice and are only
 * lifted when they switch news emails on again.
 */
final class Suppressions
{
    public const CLEARABLE = ['hard_bounce', 'soft_bounce'];

    public static function index(array $p): void
    {
        require_permission('communications');
        $reason = query_param('reason');
        $reason = array_key_exists($reason, email_suppression_reasons()) ? $reason : '';
        $search = mb_substr(trim(query_param('q')), 0, 100);
        $where = [];
        $params = [];
        if ($reason !== '') {
            $where[] = 's.reason = ?';
            $params[] = $reason;
        }
        if ($search !== '') {
            $where[] = 's.email LIKE ?';
            $params[] = '%' . addcslashes(strtolower($search), '%_\\') . '%';
        }
        $sql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
        $pg = paging(50);
        $rows = q_all("SELECT s.*, u.id AS user_id, u.display_name, u.role,
                (SELECT COUNT(*) FROM email_events ev WHERE ev.email = s.email) AS events
              FROM email_suppressions s LEFT JOIN users u ON u.email = s.email $sql ORDER BY s.updated_at DESC, s.email LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);
        $total = (int) q_val("SELECT COUNT(*) FROM email_suppressions s $sql", $params);
        $counts = ['' => (int) q_val('SELECT COUNT(*) FROM email_suppressions')];
        foreach (q_all('SELECT reason, COUNT(*) AS n FROM email_suppressions GROUP BY reason') as $r) {
            $counts[$r['reason']] = (int) $r['n'];
        }
        $events = q_all('SELECT email, type, status_code, detail, source, created_at FROM email_events ORDER BY id DESC LIMIT 15');
        render('admin/suppressions', ['title' => 'Suppression list', 'rows' => $rows, 'total' => $total, 'counts' => $counts, 'reason' => $reason, 'q' => $search,
            'page' => $pg['page'], 'per' => $pg['per'], 'events' => $events, 'stats7' => bounce_stats(7), 'stats30' => bounce_stats(30)], 'admin');
    }

    public static function clear(array $p): void
    {
        csrf_check();
        require_permission('communications');
        $email = strtolower(trim(input('email')));
        $row = email_suppression($email);
        if ($row === null) {
            flash('That address is not on the suppression list.', 'error');
        } elseif (!in_array($row['reason'], self::CLEARABLE, true)) {
            flash('Only the person can undo an unsubscribe or a spam complaint, by switching news emails on in their preferences.', 'error');
        } else {
            q('DELETE FROM email_suppressions WHERE email = ?', [$email]);
            // Old soft bounces no longer count towards the next suppression.
            q("DELETE FROM email_events WHERE email = ? AND type = 'soft'", [$email]);
            audit('clear', 'suppression', null, $email . ' (' . $row['reason'] . ')');
            flash($email . ' can receive emails again.');
        }
        redirect('/admin/email-suppressions' . (input('reason') !== '' && array_key_exists(input('reason'), email_suppression_reasons()) ? '?reason=' . rawurlencode(input('reason')) : ''));
    }
}
