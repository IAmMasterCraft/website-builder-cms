<?php
declare(strict_types=1);

/*
 * One crontab entry (`* * * * * php bin/cron`) dispatches every due job.
 * Each job: an interval in minutes ('every') or a local time of day
 * ('daily_at', site time zone), and a function name. Backups run daily when switched on.
 */
function cron_jobs(): array
{
    return [
        'publish_scheduled' => ['every' => 1, 'run' => 'cron_publish_scheduled'],
        'cleanup' => ['every' => 60, 'run' => 'cron_cleanup'],
        'purge_unverified' => ['every' => 60, 'run' => 'cron_purge_unverified'],
        'campaigns_due' => ['every' => 1, 'run' => 'cron_campaigns_due'],
        'email_queue' => ['every' => 1, 'run' => 'cron_email_queue'],
        'birthdays' => ['daily_at' => birthday_time(), 'run' => 'cron_birthdays'],
        'job_expiry' => ['every' => 15, 'run' => 'cron_job_expiry'],
        // Backups can take long: they run after the main lock is released, under their own lock.
        'backups' => ['daily_at' => backup_settings()['time'], 'run' => 'cron_backups', 'own_lock' => true],
    ];
}

function cron_publish_scheduled(): string
{
    return publish_due() . ' item(s) published';
}

function cron_cleanup(): string
{
    $a = q('DELETE FROM login_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 1 DAY')->rowCount();
    $b = q('DELETE FROM rate_hits WHERE hit_at < UTC_TIMESTAMP() - INTERVAL 1 DAY')->rowCount();
    $c = q('DELETE FROM password_resets WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY')->rowCount();
    q('DELETE FROM user_tokens WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
    q("DELETE FROM settings WHERE `key` LIKE 'birthdays.digest.%' AND updated_at < UTC_TIMESTAMP() - INTERVAL 30 DAY");
    // Sent copies of queued emails hold full message bodies: keep them for the delivery log for 90 days.
    $d = q("DELETE FROM email_queue WHERE status IN ('sent','suppressed') AND COALESCE(sent_at, created_at) < UTC_TIMESTAMP() - INTERVAL " . MAIL_KEEP_SENT_DAYS . ' DAY')->rowCount();
    return "removed $a attempts, $b rate hits, $c expired resets, $d old sent emails";
}

/** Accounts that did not verify their email within 14 days are deleted (D7). */
function cron_purge_unverified(): string
{
    return purge_unverified() . ' unverified account(s) removed, ' . purge_orphan_group_logos() . ' orphaned group logo(s) deleted';
}

function cron_campaigns_due(): string
{
    return campaigns_due() . ' scheduled campaign(s) queued';
}

function cron_email_queue(): string
{
    $s = mail_queue_run();
    return "{$s['sent']} sent, {$s['retry']} to retry, {$s['failed']} failed";
}

function cron_job_expiry(): string
{
    return jobs_expire() . ' job(s) expired';
}

/** A failed daily job is tried again after this many seconds (until it succeeds or the day ends). */
const CRON_DAILY_RETRY_SECONDS = 900;
/** Sent queued emails are kept this long for the delivery log. */
const MAIL_KEEP_SENT_DAYS = 90;

/**
 * Is a job due, given its last successful run and now (unix times)?
 * Daily jobs that failed today ($lastFailure) are retried every 15 minutes.
 */
function cron_is_due(array $job, ?int $lastRun, int $now, ?DateTimeZone $tz = null, ?int $lastFailure = null): bool
{
    if (isset($job['daily_at'])) {
        $tz ??= site_tz();
        $local = (new DateTimeImmutable('@' . $now))->setTimezone($tz);
        if ($local->format('H:i') < (string) $job['daily_at']) {
            return false;
        }
        $doneToday = $lastRun !== null && (new DateTimeImmutable('@' . $lastRun))->setTimezone($tz)->format('Y-m-d') >= $local->format('Y-m-d');
        if ($doneToday) {
            return false;
        }
        return $lastFailure === null || $now - $lastFailure >= CRON_DAILY_RETRY_SECONDS;
    }
    if ($lastRun === null) {
        return true;
    }
    return $now - $lastRun >= ((int) $job['every']) * 60 - 5;
}

/**
 * Change one key of a JSON object setting under a row lock, so two cron
 * processes (the minute run and a long backup) never overwrite each other.
 */
function cron_state_set(string $key, string $job, ?int $value): void
{
    db_tx(static function () use ($key, $job, $value): void {
        q('INSERT IGNORE INTO settings (`key`, `value`) VALUES (?, ?)', [$key, '{}']);
        $state = json_decode((string) q_val('SELECT `value` FROM settings WHERE `key` = ? FOR UPDATE', [$key]), true);
        $state = is_array($state) ? $state : [];
        if ($value === null) {
            unset($state[$job]);
        } else {
            $state[$job] = $value;
        }
        q('UPDATE settings SET `value` = ? WHERE `key` = ?', [json_encode((object) $state, JSON_THROW_ON_ERROR), $key]);
    });
    settings_all([]);
}

/**
 * Run one job and record the outcome straight away: success -> cron.jobs
 * (last run); a daily job that throws is not marked done but noted in
 * cron.failed and retried every 15 minutes. Interval jobs simply run again
 * at their next interval.
 */
function cron_run_job(string $name, array $job, int $now): string
{
    try {
        $result = ($job['run'])();
    } catch (Throwable $e) {
        error_log('cron ' . $name . ': ' . $e->getMessage());
        if (isset($job['daily_at'])) {
            cron_state_set('cron.failed', $name, $now);
        } else {
            cron_state_set('cron.jobs', $name, $now);
        }
        return 'failed: ' . $e->getMessage();
    }
    cron_state_set('cron.jobs', $name, $now);
    cron_state_set('cron.failed', $name, null);
    return (string) $result;
}

/**
 * Run due jobs once. A lock file prevents overlapping runs; jobs marked
 * own_lock (backups) run after it is released, under their own lock, so a
 * long backup never holds up the mail queue or publishing.
 * @return array<string,string> job => result message
 */
function cron_run(?int $now = null, bool $force = false, ?array $jobs = null): array
{
    $now ??= time();
    $results = [];
    $later = [];
    $lock = fopen(BASE . '/storage/cache/cron.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        $results['_'] = 'another run is in progress';
    } else {
        try {
            settings_all([]);
            $last = (array) setting('cron.jobs', []);
            $failed = (array) setting('cron.failed', []);
            foreach ($jobs ?? cron_jobs() as $name => $job) {
                if (!$force && !cron_is_due($job, isset($last[$name]) ? (int) $last[$name] : null, $now, null, isset($failed[$name]) ? (int) $failed[$name] : null)) {
                    continue;
                }
                if (!empty($job['own_lock'])) {
                    $later[$name] = $job;
                    continue;
                }
                $results[$name] = cron_run_job($name, $job, $now);
            }
            setting_set('cron.last_run', gmdate('Y-m-d H:i:s', $now));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    foreach ($later as $name => $job) {
        $results[$name] = cron_run_job($name, $job, $now);
    }
    return $results;
}
