<?php
declare(strict_types=1);

namespace Admin;

/** Delivery log for queued emails (notice copies, birthday emails, retries of transactional mail). */
final class EmailLog
{
    public static function index(array $p): void
    {
        require_permission('communications');
        $status = query_param('status');
        $status = in_array($status, ['queued', 'sending', 'sent', 'failed', 'suppressed', 'bounced'], true) ? $status : '';
        $pg = paging(50);
        $where = $status === 'bounced' ? 'WHERE bounce IS NOT NULL' : ($status !== '' ? 'WHERE status = ?' : '');
        $params = $status !== '' && $status !== 'bounced' ? [$status] : [];
        $rows = q_all("SELECT id, kind, to_email, to_name, subject, status, bounce, attempts, last_error, sent_at, created_at FROM email_queue $where ORDER BY id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);
        $total = (int) q_val("SELECT COUNT(*) FROM email_queue $where", $params);
        $campaigns = q_all("SELECT id, subject, status, recipient_count, queued_at,
                (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.status = 'sent') AS sent_count,
                (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.status = 'failed') AS failed_count,
                (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.status = 'suppressed') AS suppressed_count,
                (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.bounce = 'soft') AS soft_count,
                (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.bounce = 'hard') AS hard_count,
                (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.bounce = 'complaint') AS complaint_count,
                (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.status = 'sent' AND (r.bounce IS NULL OR r.bounce = 'complaint')) AS delivered_count
              FROM campaigns c WHERE status IN ('sending','paused','sent') ORDER BY queued_at DESC LIMIT 10");
        $counts = mail_queue_counts();
        $counts['bounced'] = (int) q_val('SELECT COUNT(*) FROM email_queue WHERE bounce IS NOT NULL');
        render('admin/email_log', ['title' => 'Delivery log', 'rows' => $rows, 'total' => $total, 'status' => $status, 'counts' => $counts,
            'page' => $pg['page'], 'per' => $pg['per'], 'campaigns' => $campaigns, 'per_minute' => mail_per_minute(), 'cron' => setting('cron.last_run')], 'admin');
    }
}
