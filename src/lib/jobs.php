<?php
declare(strict_types=1);

/*
 * Job board (§4.6, D8): members and admins post jobs; posts wait for admin
 * approval unless the poster is trusted (setting) or staff. Published jobs
 * are visible to members only (setting: or public) and expire at the end
 * of their closing date or 60 days after publication, whichever is first.
 */

const JOB_TYPES = ['full_time' => 'Full-time', 'part_time' => 'Part-time', 'contract' => 'Contract', 'volunteer' => 'Volunteer', 'internship' => 'Internship'];
const JOB_MAX_DAYS = 60;

function jobs_settings(): array
{
    $s = setting('jobs', []);
    $s = is_array($s) ? $s : [];
    return [
        'auto_approve_trusted' => (bool) ($s['auto_approve_trusted'] ?? false),
        'public' => (bool) ($s['public'] ?? false),
    ];
}

/** Can this viewer see the job board? */
function jobs_visible_to(?array $user): bool
{
    return jobs_settings()['public'] || viewer_is_member($user);
}

/**
 * A trusted member has had at least one job approved and none rejected
 * or removed after reports.
 */
function job_poster_trusted(int $userId): bool
{
    $r = q_one("SELECT SUM(status IN ('published','expired') AND reviewed_by IS NOT NULL) AS ok, SUM(status = 'rejected') AS bad FROM jobs WHERE user_id = ?", [$userId]);
    // Rejections are also counted on the account: deleting a rejected post does not restore trust.
    $rejections = (int) q_val('SELECT job_rejections FROM users WHERE id = ?', [$userId]);
    return (int) ($r['ok'] ?? 0) > 0 && (int) ($r['bad'] ?? 0) === 0 && $rejections === 0;
}

/**
 * Validate a job form.
 * @return array{0:array,1:array<string,string>}
 */
function job_validate(array $in, ?DateTimeImmutable $now = null): array
{
    $s = static fn (string $k, int $max): string => mb_substr(trim(is_string($in[$k] ?? null) ? $in[$k] : ''), 0, $max + 1);
    $d = [
        'title' => $s('title', 200), 'organisation' => $s('organisation', 200), 'location' => $s('location', 200),
        'job_type' => (string) ($in['job_type'] ?? ''), 'is_remote' => ($in['is_remote'] ?? '') === '1' ? 1 : 0,
        'salary_text' => $s('salary_text', 120), 'description' => $s('description', 10000),
        'apply_url' => $s('apply_url', 500), 'apply_email' => strtolower($s('apply_email', 190)), 'closing_date' => $s('closing_date', 10),
    ];
    $e = [];
    if ($d['title'] === '' || mb_strlen($d['title']) > 200) {
        $e['title'] = 'Enter the job title (up to 200 characters).';
    }
    if ($d['organisation'] === '' || mb_strlen($d['organisation']) > 200) {
        $e['organisation'] = 'Enter the organisation (up to 200 characters).';
    }
    if (mb_strlen($d['location']) > 200) {
        $e['location'] = 'Keep the location under 200 characters.';
    }
    if (!array_key_exists($d['job_type'], JOB_TYPES)) {
        $e['job_type'] = 'Choose the type of job.';
    }
    if (mb_strlen($d['salary_text']) > 120) {
        $e['salary_text'] = 'Keep the salary under 120 characters.';
    }
    if ($d['description'] === '' || mb_strlen($d['description']) > 10000) {
        $e['description'] = 'Describe the job (up to 10,000 characters).';
    }
    if ($d['apply_url'] !== '' && (!preg_match('#^https?://[^\s"<>]+$#i', $d['apply_url']) || mb_strlen($d['apply_url']) > 500)) {
        $e['apply_url'] = 'Use a full link starting with https://';
    }
    if ($d['apply_email'] !== '' && !filter_var($d['apply_email'], FILTER_VALIDATE_EMAIL)) {
        $e['apply_email'] = 'Enter a valid email address.';
    }
    if ($d['apply_url'] === '' && $d['apply_email'] === '') {
        $e['apply_url'] = 'Give a link or an email address for applications.';
    }
    if ($d['closing_date'] !== '') {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d['closing_date'], site_tz());
        $today = ($now ?? new DateTimeImmutable('now', utc()))->setTimezone(site_tz())->format('Y-m-d');
        if ($dt === false || $dt->format('Y-m-d') !== $d['closing_date']) {
            $e['closing_date'] = 'Enter a valid date.';
        } elseif ($d['closing_date'] < $today) {
            $e['closing_date'] = 'The closing date has already passed.';
        }
    }
    $d['apply_url'] = $d['apply_url'] !== '' ? $d['apply_url'] : null;
    $d['apply_email'] = $d['apply_email'] !== '' ? $d['apply_email'] : null;
    $d['closing_date'] = $d['closing_date'] !== '' ? $d['closing_date'] : null;
    return [$d, $e];
}

/** UTC expiry: end of the closing date (site time) or publication + 60 days, whichever is first. */
function job_expiry(string $publishedUtc, ?string $closingDate): string
{
    $max = (new DateTimeImmutable($publishedUtc, utc()))->modify('+' . JOB_MAX_DAYS . ' days');
    if ($closingDate !== null) {
        $close = DateTimeImmutable::createFromFormat('!Y-m-d', $closingDate, site_tz());
        if ($close !== false) {
            $endOfDay = $close->modify('+1 day')->setTimezone(utc());
            if ($endOfDay < $max) {
                $max = $endOfDay;
            }
        }
    }
    return $max->format('Y-m-d H:i:s');
}

/** Create a job. Returns [id, status]. */
function job_create(array $data, array $user, ?DateTimeImmutable $now = null): array
{
    $nowSql = ($now ?? new DateTimeImmutable('now', utc()))->setTimezone(utc())->format('Y-m-d H:i:s');
    $auto = is_staff($user) || (jobs_settings()['auto_approve_trusted'] && job_poster_trusted((int) $user['id']));
    $row = $data + ['user_id' => $user['id'], 'status' => $auto ? 'published' : 'pending'];
    if ($auto) {
        $row['published_at'] = $nowSql;
        $row['expires_at'] = job_expiry($nowSql, $data['closing_date']);
        if (is_staff($user)) {
            $row['reviewed_by'] = $user['id'];
            $row['reviewed_at'] = $nowSql;
        }
    }
    $id = db_insert('jobs', $row);
    if (!$auto) {
        job_notify_moderators($id, $data['title'], (string) $user['display_name']);
    }
    return [$id, $row['status']];
}

/** Owner edit: a published job goes back to approval unless the owner is trusted or staff. */
function job_update(array $job, array $data, array $user, ?DateTimeImmutable $now = null): string
{
    $nowSql = ($now ?? new DateTimeImmutable('now', utc()))->setTimezone(utc())->format('Y-m-d H:i:s');
    $status = $job['status'];
    $auto = is_staff($user) || (jobs_settings()['auto_approve_trusted'] && job_poster_trusted((int) $user['id']));
    if (in_array($status, ['published', 'rejected', 'expired'], true) && !$auto) {
        $status = 'pending';
    }
    $extra = ['status' => $status];
    if ($status === 'published') {
        $extra['expires_at'] = job_expiry((string) ($job['published_at'] ?? $nowSql), $data['closing_date']);
    }
    db_update('jobs', (int) $job['id'], $data + $extra);
    if ($status === 'pending' && $job['status'] !== 'pending') {
        job_notify_moderators((int) $job['id'], $data['title'], (string) $user['display_name']);
    }
    return $status;
}

function job_notify_moderators(int $jobId, string $title, string $poster): void
{
    $mods = q_all("SELECT u.email, u.display_name FROM users u WHERE u.status = 'active' AND (u.role = 'super_admin' OR (u.role = 'admin' AND EXISTS (SELECT 1 FROM admin_permissions p WHERE p.user_id = u.id AND p.permission = 'jobs')))");
    foreach ($mods as $m) {
        send_template_mail((string) $m['email'], explode(' ', (string) $m['display_name'])[0], 'job_pending', ['job_title' => $title, 'poster' => $poster, 'queue_url' => base_url('/admin/jobs')]);
    }
}

/** Admin approval: publishes and emails the poster. */
function job_approve(int $id, int $adminId, ?DateTimeImmutable $now = null): bool
{
    $nowSql = ($now ?? new DateTimeImmutable('now', utc()))->setTimezone(utc())->format('Y-m-d H:i:s');
    $job = q_one('SELECT j.*, u.email, u.display_name FROM jobs j LEFT JOIN users u ON u.id = j.user_id WHERE j.id = ?', [$id]);
    if ($job === null || !in_array($job['status'], ['pending', 'rejected'], true)) {
        return false;
    }
    // Every approval (first, after an edit, or after a rejection) starts a fresh listing:
    // published_at and the 60-day expiry both count from now, so the board order matches.
    $expires = job_expiry($nowSql, $job['closing_date']);
    if ($expires <= $nowSql) {
        return false;
    }
    db_update('jobs', $id, ['status' => 'published', 'published_at' => $nowSql, 'expires_at' => $expires, 'reviewed_by' => $adminId, 'reviewed_at' => $nowSql, 'reject_reason' => '']);
    if (!empty($job['email']) && (int) $job['user_id'] !== $adminId) {
        send_template_mail((string) $job['email'], job_first_name($job), 'job_approved', [
            'job_title' => $job['title'], 'job_url' => base_url('/jobs/' . $id), 'expires' => fmt_date($expires, 'j F Y'),
        ]);
    }
    return true;
}

function job_reject(int $id, int $adminId, string $reason, ?DateTimeImmutable $now = null): bool
{
    $nowSql = ($now ?? new DateTimeImmutable('now', utc()))->setTimezone(utc())->format('Y-m-d H:i:s');
    $job = q_one('SELECT j.*, u.email, u.display_name FROM jobs j LEFT JOIN users u ON u.id = j.user_id WHERE j.id = ?', [$id]);
    if ($job === null || $job['status'] === 'rejected') {
        return false;
    }
    db_update('jobs', $id, ['status' => 'rejected', 'reviewed_by' => $adminId, 'reviewed_at' => $nowSql, 'reject_reason' => mb_substr($reason, 0, 500)]);
    if ($job['user_id'] !== null) {
        q('UPDATE users SET job_rejections = job_rejections + 1 WHERE id = ?', [$job['user_id']]);
    }
    q('UPDATE job_reports SET resolved_at = ? WHERE job_id = ? AND resolved_at IS NULL', [$nowSql, $id]);
    if (!empty($job['email']) && (int) $job['user_id'] !== $adminId) {
        send_template_mail((string) $job['email'], job_first_name($job), 'job_rejected', [
            'job_title' => $job['title'], 'reason' => $reason !== '' ? $reason : 'It does not meet our job board guidelines.', 'jobs_url' => base_url('/account/jobs'),
        ]);
    }
    return true;
}

function job_first_name(array $job): string
{
    $m = $job['user_id'] ? member_for_user((int) $job['user_id']) : null;
    return $m !== null ? (string) $m['first_name'] : explode(' ', (string) ($job['display_name'] ?? ''))[0];
}

/** Cron (and lazily in queries): published jobs past their expiry become expired. */
function jobs_expire(?DateTimeImmutable $now = null): int
{
    $nowSql = ($now ?? new DateTimeImmutable('now', utc()))->setTimezone(utc())->format('Y-m-d H:i:s');
    return q("UPDATE jobs SET status = 'expired' WHERE status = 'published' AND expires_at IS NOT NULL AND expires_at <= ?", [$nowSql])->rowCount();
}

/** SQL for jobs currently on the board. */
function jobs_live_sql(string $alias = 'j'): string
{
    return "($alias.status = 'published' AND ($alias.expires_at IS NULL OR $alias.expires_at > UTC_TIMESTAMP()))";
}

/**
 * Jobs on the board with filters q, type, remote.
 * @return array{rows:list<array>, total:int}
 */
function jobs_board(array $f, int $limit, int $offset): array
{
    $where = [jobs_live_sql()];
    $params = [];
    if (($f['q'] ?? '') !== '') {
        $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
        $where[] = '(j.title LIKE ? OR j.organisation LIKE ? OR j.location LIKE ? OR j.description LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    if (array_key_exists((string) ($f['type'] ?? ''), JOB_TYPES)) {
        $where[] = 'j.job_type = ?';
        $params[] = $f['type'];
    }
    if (($f['remote'] ?? '') === '1') {
        $where[] = 'j.is_remote = 1';
    }
    $w = implode(' AND ', $where);
    return [
        'rows' => q_all("SELECT j.* FROM jobs j WHERE $w ORDER BY j.published_at DESC, j.id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset, $params),
        'total' => (int) q_val("SELECT COUNT(*) FROM jobs j WHERE $w", $params),
    ];
}

/** Report a job (one report per member). Returns false if already reported. */
function job_report(int $jobId, int $userId, string $reason): bool
{
    return q('INSERT IGNORE INTO job_reports (job_id, user_id, reason) VALUES (?, ?, ?)', [$jobId, $userId, mb_substr($reason, 0, 500)])->rowCount() === 1;
}

function job_apply_href(array $job): string
{
    if (!empty($job['apply_url'])) {
        return (string) $job['apply_url'];
    }
    return !empty($job['apply_email']) ? 'mailto:' . $job['apply_email'] . '?subject=' . rawurlencode('Application: ' . $job['title']) : '';
}
