<?php
declare(strict_types=1);

/*
 * One crontab entry (`* * * * * php bin/cron`) dispatches every due job.
 * Each job: interval in minutes and a function name. Later phases add the
 * email queue, birthdays (daily at the configured hour), job expiry and backups.
 */
function cron_jobs(): array
{
    return [
        'publish_scheduled' => ['every' => 1, 'run' => 'cron_publish_scheduled'],
        'cleanup' => ['every' => 60, 'run' => 'cron_cleanup'],
        // 'email_queue' => ['every' => 1, 'run' => ...],       phase 5
        // 'birthdays'   => ['daily_at' => '07:00', 'run' => ...], phase 5
        // 'job_expiry'  => ['every' => 60, 'run' => ...],      phase 5
        // 'backups'     => ['daily_at' => '03:00', 'run' => ...], phase 7
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
    return "removed $a attempts, $b rate hits, $c expired resets";
}

/** Is a job due, given its last run (unix time) and now? */
function cron_is_due(array $job, ?int $lastRun, int $now): bool
{
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
