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
        'backups' => ['daily_at' => backup_settings()['time'], 'run' => 'cron_backups'],
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
    return "removed $a attempts, $b rate hits, $c expired resets";
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

/** Is a job due, given its last run (unix time) and now? */
function cron_is_due(array $job, ?int $lastRun, int $now, ?DateTimeZone $tz = null): bool
{
    if (isset($job['daily_at'])) {
        $tz ??= site_tz();
        $local = (new DateTimeImmutable('@' . $now))->setTimezone($tz);
        if ($local->format('H:i') < (string) $job['daily_at']) {
            return false;
        }
        return $lastRun === null || (new DateTimeImmutable('@' . $lastRun))->setTimezone($tz)->format('Y-m-d') < $local->format('Y-m-d');
    }
    if ($lastRun === null) {
        return true;
    }
    return $now - $lastRun >= ((int) $job['every']) * 60 - 5;
}

/**
 * Run due jobs once. A lock file prevents overlapping runs.
 * @return array<string,string> job => result message
 */
function cron_run(?int $now = null, bool $force = false): array
{
    $now ??= time();
    $lock = fopen(BASE . '/storage/cache/cron.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        return ['_' => 'another run is in progress'];
    }
    $results = [];
    try {
        $last = (array) setting('cron.jobs', []);
        foreach (cron_jobs() as $name => $job) {
            if (!$force && !cron_is_due($job, isset($last[$name]) ? (int) $last[$name] : null, $now)) {
                continue;
            }
            try {
                $results[$name] = ($job['run'])();
            } catch (Throwable $e) {
                error_log('cron ' . $name . ': ' . $e->getMessage());
                $results[$name] = 'failed: ' . $e->getMessage();
            }
            $last[$name] = $now;
        }
        setting_set('cron.jobs', $last);
        setting_set('cron.last_run', gmdate('Y-m-d H:i:s', $now));
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $results;
}
