<?php
declare(strict_types=1);

namespace Admin;

/**
 * Suppression list (Addendum A5): addresses that unsubscribed, complained or
 * bounced. Admins can clear a bounce suppression once the address is fixed,
 * and a complaint that came from an emailed feedback report (ARF/DSN in the
 * bounce mailbox, or applied from the review list): such reports are not
 * authenticated. Unsubscribes and complaints reported by the sending
 * provider's signed webhook are the person's own choice and are only lifted
 * when they switch news emails on again. Reports that could not be tied to
 * a message we sent wait in "Reports to review" (apply or dismiss).
 */
final class Suppressions
{
    public const CLEARABLE = ['hard_bounce', 'soft_bounce'];
    /** Complaints from these sources came by email (not authenticated) and may be cleared by an admin. */
    public const CLEARABLE_COMPLAINT_SOURCES = ['arf', 'dsn', 'review'];

    /** May an admin clear this suppression row? */
    public static function clearable(array $row): bool
    {
        return in_array($row['reason'], self::CLEARABLE, true)
            || ($row['reason'] === 'complaint' && in_array((string) $row['source'], self::CLEARABLE_COMPLAINT_SOURCES, true));
    }

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
        $events = q_all('SELECT email, type, status_code, detail, source, review, created_at FROM email_events ORDER BY id DESC LIMIT 15');
        $review = q_all("SELECT id, email, type, status_code, detail, source, created_at FROM email_events WHERE review = 'pending' ORDER BY id DESC LIMIT 50");
        render('admin/suppressions', ['title' => 'Suppression list', 'rows' => $rows, 'total' => $total, 'counts' => $counts, 'reason' => $reason, 'q' => $search,
            'page' => $pg['page'], 'per' => $pg['per'], 'events' => $events, 'review' => $review, 'review_total' => bounce_review_count(),
            'stats7' => bounce_stats(7), 'stats30' => bounce_stats(30)], 'admin');
    }

    public static function clear(array $p): void
    {
        csrf_check();
        require_permission('communications');
        $email = strtolower(trim(input('email')));
        $row = email_suppression($email);
        if ($row === null) {
            flash('That address is not on the suppression list.', 'error');
        } elseif (!self::clearable($row)) {
            flash('Only the person can undo an unsubscribe or a spam complaint reported by your sending provider, by switching news emails on in their preferences.', 'error');
        } else {
            q('DELETE FROM email_suppressions WHERE email = ?', [$email]);
            // Old soft bounces no longer count towards the next suppression.
            q("DELETE FROM email_events WHERE email = ? AND type = 'soft'", [$email]);
            audit('clear', 'suppression', null, $email . ' (' . $row['reason'] . ')');
            flash($email . ' can receive emails again.');
        }
        redirect('/admin/email-suppressions' . (input('reason') !== '' && array_key_exists(input('reason'), email_suppression_reasons()) ? '?reason=' . rawurlencode(input('reason')) : ''));
    }

    /** POST /admin/email-suppressions/review/{id}: apply or dismiss a report waiting for review. */
    public static function review(array $p): void
    {
        csrf_check();
        require_permission('communications');
        $decision = input('decision');
        $ev = bounce_review_decide((int) $p['id'], $decision);
        if ($ev === null) {
            flash('That report is no longer waiting for review.', 'error');
        } else {
            audit($decision === 'apply' ? 'review_apply' : 'review_dismiss', 'email_event', (int) $ev['id'], $ev['email'] . ' (' . $ev['type'] . ', ' . $ev['source'] . ')');
            flash($decision === 'apply'
                ? ($ev['type'] === 'block' ? 'Recorded as a block for ' . $ev['email'] . ' (blocks never suppress an address).' : 'Applied: ' . $ev['email'] . ' is handled as a ' . ($ev['type'] === 'complaint' ? 'complaint' : $ev['type'] . ' bounce') . '.')
                : 'Dismissed: nothing changes for ' . $ev['email'] . '.');
        }
        redirect('/admin/email-suppressions#review');
    }
}
