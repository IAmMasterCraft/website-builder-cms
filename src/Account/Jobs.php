<?php
declare(strict_types=1);

namespace Account;

/** Members' own job posts (§4.4 "Jobs: browse, my posts"). */
final class Jobs
{
    private static function member(): array
    {
        $user = require_login();
        if (is_staff($user)) {
            redirect('/admin/jobs');
        }
        if ($user['status'] !== 'active') {
            flash('Please verify your email first: open the link we sent you, or send a new one below.', 'info');
            redirect('/account');
        }
        return $user;
    }

    private static function page(string $template, string $title, array $data, int $status = 200): void
    {
        $user = $data['user'];
        $data += ['title' => $title, 'active' => 'jobs', 'noindex' => true, 'is_group' => $user['role'] === 'group'];
        render('account/_shell', $data + ['inner' => view('account/' . $template, $data)], 'site', $status);
    }

    private static function own(int $id, array $user): array
    {
        $job = q_one('SELECT * FROM jobs WHERE id = ? AND user_id = ?', [$id, $user['id']]);
        if ($job === null) {
            abort(404);
        }
        return $job;
    }

    public static function mine(array $p): void
    {
        $user = self::member();
        jobs_expire();
        $rows = q_all('SELECT * FROM jobs WHERE user_id = ? ORDER BY created_at DESC', [$user['id']]);
        self::page('jobs', 'Jobs', ['user' => $user, 'rows' => $rows]);
    }

    public static function create(array $p): void
    {
        $user = self::member();
        $old = take_old_input();
        self::page('job_form', 'Post a job', ['user' => $user, 'job' => null, 'values' => $old['values'] + ['job_type' => 'full_time'], 'errors' => $old['errors']]);
    }

    public static function store(array $p): void
    {
        csrf_check();
        $user = self::member();
        if (rate_limited('job-post:' . $user['id'], 5, 86400)) {
            flash('You have posted several jobs today. Please try again tomorrow.', 'error');
            redirect('/account/jobs');
        }
        [$data, $errors] = job_validate($_POST);
        if ($errors) {
            remember_input($_POST, $errors);
            redirect('/account/jobs/new');
        }
        rate_hit('job-post:' . $user['id']);
        [$id, $status] = job_create($data, $user);
        audit('create', 'job', $id, $data['title']);
        flash($status === 'published' ? 'Your job is live on the board.' : 'Thank you! Your job will appear on the board once an administrator approves it.');
        redirect('/account/jobs');
    }

    public static function edit(array $p): void
    {
        $user = self::member();
        $job = self::own((int) $p['id'], $user);
        $old = take_old_input();
        self::page('job_form', 'Edit job', ['user' => $user, 'job' => $job, 'values' => $old['values'] + $job, 'errors' => $old['errors']]);
    }

    public static function update(array $p): void
    {
        csrf_check();
        $user = self::member();
        $job = self::own((int) $p['id'], $user);
        [$data, $errors] = job_validate($_POST);
        if ($errors) {
            remember_input($_POST, $errors);
            redirect('/account/jobs/' . $job['id'] . '/edit');
        }
        $status = job_update($job, $data, $user);
        flash($status === 'pending' ? 'Saved. The changes will appear once an administrator approves them.' : 'Saved.');
        redirect('/account/jobs');
    }

    public static function delete(array $p): void
    {
        csrf_check();
        $user = self::member();
        $job = self::own((int) $p['id'], $user);
        q('DELETE FROM jobs WHERE id = ? AND user_id = ?', [$job['id'], $user['id']]);
        flash('Your job post was removed.');
        redirect('/account/jobs');
    }
}
