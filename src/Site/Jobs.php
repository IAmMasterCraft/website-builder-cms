<?php
declare(strict_types=1);

namespace Site;

/** The job board (§4.6, D8: members only unless the setting makes it public). */
final class Jobs
{
    private static function viewer(): ?array
    {
        $user = current_user();
        if (!jobs_visible_to($user)) {
            if ($user === null) {
                redirect('/login?next=' . rawurlencode(request_path()));
            }
            abort(403, 'The job board is for verified members. Please verify your email first.');
        }
        return $user;
    }

    public static function index(array $p): void
    {
        $user = self::viewer();
        $f = ['q' => mb_substr(query_param('q'), 0, 100), 'type' => query_param('type'), 'remote' => query_param('remote') === '1' ? '1' : ''];
        $f['type'] = array_key_exists($f['type'], JOB_TYPES) ? $f['type'] : '';
        $pg = paging(12);
        $res = jobs_board($f, $pg['per'], $pg['offset']);
        render('site/jobs_index', ['title' => 'Job board', 'rows' => $res['rows'], 'total' => $res['total'], 'filters' => $f, 'page' => $pg['page'], 'per' => $pg['per'], 'user' => $user, 'noindex' => !jobs_settings()['public']]);
    }

    public static function show(array $p): void
    {
        $user = self::viewer();
        $job = q_one('SELECT j.* FROM jobs j WHERE j.id = ? AND ' . jobs_live_sql(), [(int) $p['id']]);
        if ($job === null && $user !== null) {
            // Owners and job moderators can see posts that are not live.
            $job = q_one('SELECT * FROM jobs WHERE id = ? AND (user_id = ? OR ?)', [(int) $p['id'], $user['id'], user_can($user, 'jobs') ? 1 : 0]);
        }
        if ($job === null) {
            abort(404);
        }
        $reported = $user !== null && q_val('SELECT 1 FROM job_reports WHERE job_id = ? AND user_id = ?', [$job['id'], $user['id']]) !== null;
        render('site/job_show', ['title' => $job['title'], 'job' => $job, 'user' => $user, 'reported' => $reported, 'noindex' => true]);
    }

    public static function report(array $p): void
    {
        csrf_check();
        $user = require_login();
        if (!viewer_is_member($user)) {
            abort(403);
        }
        $job = q_one('SELECT id, title FROM jobs j WHERE j.id = ? AND ' . jobs_live_sql(), [(int) $p['id']]);
        if ($job === null) {
            abort(404);
        }
        $new = job_report((int) $job['id'], (int) $user['id'], input('reason'));
        flash($new ? 'Thank you. An administrator will review this job.' : 'You have already reported this job.', 'info');
        redirect('/jobs/' . $job['id']);
    }
}
