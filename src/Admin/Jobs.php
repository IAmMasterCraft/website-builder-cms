<?php
declare(strict_types=1);

namespace Admin;

/** Job board moderation (§4.6): approval queue, reports, settings. */
final class Jobs
{
    public const TABS = ['pending' => 'Awaiting approval', 'reported' => 'Reported', 'published' => 'Live', 'expired' => 'Expired', 'rejected' => 'Rejected'];

    public static function index(array $p): void
    {
        require_permission('jobs');
        jobs_expire();
        $tab = query_param('tab');
        $tab = array_key_exists($tab, self::TABS) ? $tab : 'pending';
        $base = "SELECT j.*, u.display_name AS poster, u.email AS poster_email,
                    (SELECT COUNT(*) FROM job_reports r WHERE r.job_id = j.id AND r.resolved_at IS NULL) AS open_reports
                   FROM jobs j LEFT JOIN users u ON u.id = j.user_id";
        $rows = $tab === 'reported'
            ? q_all("$base WHERE EXISTS (SELECT 1 FROM job_reports r WHERE r.job_id = j.id AND r.resolved_at IS NULL) ORDER BY j.created_at DESC LIMIT 200")
            : q_all("$base WHERE j.status = ? ORDER BY j.created_at DESC LIMIT 200", [$tab]);
        $reports = [];
        foreach ($rows as $r) {
            if ((int) $r['open_reports'] > 0) {
                $reports[(int) $r['id']] = q_all('SELECT r.reason, r.created_at, u.display_name FROM job_reports r LEFT JOIN users u ON u.id = r.user_id WHERE r.job_id = ? AND r.resolved_at IS NULL ORDER BY r.created_at', [$r['id']]);
            }
        }
        $counts = [];
        foreach (q_all('SELECT status, COUNT(*) AS n FROM jobs GROUP BY status') as $c) {
            $counts[$c['status']] = (int) $c['n'];
        }
        $counts['reported'] = (int) q_val('SELECT COUNT(DISTINCT job_id) FROM job_reports WHERE resolved_at IS NULL');
        render('admin/jobs_index', ['title' => 'Jobs', 'rows' => $rows, 'tab' => $tab, 'counts' => $counts, 'reports' => $reports, 'settings' => jobs_settings()], 'admin');
    }

    public static function create(array $p): void
    {
        require_permission('jobs');
        $old = take_old_input();
        render('admin/job_form', ['title' => 'Post a job', 'values' => $old['values'] + ['job_type' => 'full_time'], 'errors' => $old['errors']], 'admin');
    }

    public static function store(array $p): void
    {
        csrf_check();
        $user = require_permission('jobs');
        [$data, $errors] = job_validate($_POST);
        if ($errors) {
            remember_input($_POST, $errors);
            redirect('/admin/jobs/new');
        }
        [$id] = job_create($data, $user);
        audit('create', 'job', $id, $data['title']);
        flash('Job published on the board.');
        redirect('/admin/jobs?tab=published');
    }

    public static function approve(array $p): void
    {
        csrf_check();
        $user = require_permission('jobs');
        $ok = job_approve((int) $p['id'], (int) $user['id']);
        if ($ok) {
            audit('approve', 'job', (int) $p['id']);
        }
        flash($ok ? 'Job approved and the poster notified.' : 'This job cannot be approved (it may have passed its closing date).', $ok ? 'success' : 'error');
        redirect('/admin/jobs');
    }

    public static function reject(array $p): void
    {
        csrf_check();
        $user = require_permission('jobs');
        $reason = mb_substr(input('reason'), 0, 500);
        $ok = job_reject((int) $p['id'], (int) $user['id'], $reason);
        if ($ok) {
            audit('reject', 'job', (int) $p['id'], $reason);
        }
        flash($ok ? 'Job rejected and the poster notified.' : 'Nothing changed.');
        redirect('/admin/jobs' . (input('tab') !== '' && array_key_exists(input('tab'), self::TABS) ? '?tab=' . input('tab') : ''));
    }

    /** Keep a reported job: close its reports. */
    public static function resolve(array $p): void
    {
        csrf_check();
        require_permission('jobs');
        q('UPDATE job_reports SET resolved_at = UTC_TIMESTAMP() WHERE job_id = ? AND resolved_at IS NULL', [(int) $p['id']]);
        audit('resolve_reports', 'job', (int) $p['id']);
        flash('Reports closed; the job stays on the board.');
        redirect('/admin/jobs?tab=reported');
    }

    public static function delete(array $p): void
    {
        csrf_check();
        require_permission('jobs');
        $title = (string) q_val('SELECT title FROM jobs WHERE id = ?', [(int) $p['id']]);
        q('DELETE FROM jobs WHERE id = ?', [(int) $p['id']]);
        audit('delete', 'job', (int) $p['id'], $title);
        flash('Job deleted.');
        redirect('/admin/jobs');
    }

    public static function settings(array $p): void
    {
        csrf_check();
        require_permission('jobs');
        setting_set('jobs', ['auto_approve_trusted' => input('auto_approve_trusted') === '1', 'public' => input('public') === '1']);
        audit('update', 'settings', null, 'job board settings');
        flash('Job board settings saved.');
        redirect('/admin/jobs');
    }
}
