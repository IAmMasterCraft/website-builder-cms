<?php
declare(strict_types=1);

// Regression tests for the mail queue, notices, campaigns, cron and backup
// review findings (R4-R6, R9-R12, R14 and the email-log retention item).

use Core\Mail\Mailer;
use Core\Mail\Message;
use Core\Mail\SmtpMailer;
use Core\Mail\TransportException;

/** Fails every send with a transport error (server down) until told otherwise. */
final class DownMailer implements Mailer
{
    public bool $down = true;
    public int $sent = 0;

    public function send(Message $message): void
    {
        if ($this->down) {
            throw new TransportException('SMTP: SMTP Error: Could not connect to SMTP host.');
        }
        $this->sent++;
    }
}

// ---- R4: suppression at send time ------------------------------------------------

test('R4 queue: unsubscribes, opt-outs and suspensions after queuing stop campaign mail', function (): void {
    reset_comms();
    q("DELETE FROM users WHERE role IN ('member','group')");
    $keep = fixture_full_member(['first_name' => 'Keep']);
    $unsub = fixture_full_member(['first_name' => 'Unsub']);
    $optout = fixture_full_member(['first_name' => 'Optout']);
    $susp = fixture_full_member(['first_name' => 'Susp']);
    $rec = rec_mailer();
    $id = fixture_campaign(['type' => 'all_members', 'value' => '', 'field_id' => 0]);
    $t = 1_900_200_000;
    same(4, campaign_queue($id, $t));
    email_unsubscribe($unsub['email']);
    q("UPDATE members SET prefs = JSON_SET(prefs, '$.newsletters', false) WHERE id = ?", [$optout['member_id']]);
    q("UPDATE users SET status = 'suspended' WHERE id = ?", [$susp['user_id']]);
    $s = mail_queue_run($t, 50);
    same(1, $s['sent']);
    same(3, $s['suppressed']);
    same([$keep['email']], $rec->to());
    same(3, campaign_counts($id)['suppressed']);
    same('sent', q_val('SELECT status FROM campaigns WHERE id = ?', [$id]), 'campaign completes');
    mailer(null, true);
});

test('R4 queue: queued notice copies are re-checked too; transactional mail is not', function (): void {
    reset_comms();
    $m = fixture_full_member();
    $rec = rec_mailer();
    mail_queue_message(new Message($m['email'], 'X', 'Notice: hi', 'text'), 'notice', 'n1');
    mail_queue_message(new Message($m['email'], 'X', 'Reset your password', 'text'), 'transactional');
    email_unsubscribe($m['email']);
    $s = mail_queue_run(time() + 1, 50);
    same(1, $s['suppressed']);
    same(['Reset your password'], array_map(static fn (Message $x): string => $x->subject, $rec->sent));
    mailer(null, true);
});

test('R4 queue: deleting an account removes its queued emails', function (): void {
    reset_comms();
    $m = fixture_full_member();
    $id = fixture_campaign(['type' => 'all_members', 'value' => '', 'field_id' => 0]);
    campaign_queue($id, 1_900_300_000);
    mail_queue_message(new Message($m['email'], 'X', 'Notice: hi', 'text'), 'notice', 'n2');
    ok((int) q_val("SELECT COUNT(*) FROM campaign_recipients WHERE email = ?", [$m['email']]) === 1);
    account_delete($m['user_id']);
    same(0, (int) q_val("SELECT COUNT(*) FROM campaign_recipients WHERE email = ? AND status = 'queued'", [$m['email']]));
    same(0, (int) q_val("SELECT COUNT(*) FROM email_queue WHERE to_email = ? AND status = 'queued'", [$m['email']]));
});

// ---- R6: SMTP outages ----------------------------------------------------------------

test('R6 queue: connection/login failures are classified as transport problems', function (): void {
    SmtpMailer::load();
    ok(SmtpMailer::isTransportProblem('SMTP Error: Could not connect to SMTP host. Failed to connect to server'));
    ok(SmtpMailer::isTransportProblem('SMTP Error: Could not authenticate.'));
    ok(SmtpMailer::isTransportProblem('SMTP connect() failed.'));
    ok(!SmtpMailer::isTransportProblem('SMTP Error: The following recipients failed: x@example.test: 550 No such user'));
    ok(!SmtpMailer::isTransportProblem('SMTP Error: data not accepted.'));
});

test('R6 queue: an outage pauses sending without using up attempts, then resumes', function (): void {
    reset_comms();
    q("DELETE FROM users WHERE role IN ('member','group')");
    fixture_full_member();
    fixture_full_member();
    $down = new DownMailer();
    mailer($down);
    $id = fixture_campaign(['type' => 'all_members', 'value' => '', 'field_id' => 0]);
    $t = 1_900_400_000;
    campaign_queue($id, $t);
    for ($i = 0; $i < 10; $i++) {
        $s = mail_queue_run($t, 50);
        same(1, $s['paused']);
        $t += MAIL_PAUSE_MAX_SECONDS + 1;
    }
    same(0, (int) q_val("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND (status = 'failed' OR attempts > 0)", [$id]), 'ten outages later nothing failed and no attempt was used');
    $pause = mail_pause_state($t);
    ok($pause !== null && $pause['seconds'] === MAIL_PAUSE_MAX_SECONDS, 'pause grew to the maximum');
    $health = array_values(array_filter(health_checks($pause['until'] - 1)['Mail and site'], static fn (array $i): bool => $i['label'] === 'Mail server'))[0];
    same('bad', $health['status'], 'health warns while paused');
    same(0, mail_queue_run($pause['until'] - 1, 50)['sent'], 'nothing is tried during the pause');
    $down->down = false;
    $s = mail_queue_run($pause['until'] + 1, 50);
    same(2, $s['sent']);
    same(null, mail_pause_state(), 'pause cleared once the server answers');
    mailer(null, true);
});

test('R6 queue: "retry failed recipients" re-queues never-sent rows but not interrupted ones', function (): void {
    reset_comms();
    q("DELETE FROM users WHERE role IN ('member','group')");
    $a = fixture_full_member();
    $b = fixture_full_member();
    $rec = rec_mailer();
    $id = fixture_campaign(['type' => 'all_members', 'value' => '', 'field_id' => 0]);
    $t = 1_900_500_000;
    campaign_queue($id, $t);
    q("UPDATE campaign_recipients SET status = 'failed', error = 'SMTP 550' WHERE email = ?", [$a['email']]);
    q("UPDATE campaign_recipients SET status = 'sending', claimed_at = ? WHERE email = ?", [gmdate('Y-m-d H:i:s', $t), $b['email']]);
    $s = mail_queue_run($t + MAIL_STUCK_SECONDS + 60, 50);
    same(1, $s['interrupted']);
    same('sent', q_val('SELECT status FROM campaigns WHERE id = ?', [$id]));
    same(1, campaign_counts($id)['retryable']);
    same(1, campaign_retry_failed($id, $t + 2000));
    same('sending', q_val('SELECT status FROM campaigns WHERE id = ?', [$id]));
    mail_queue_run($t + 2000, 50);
    same([$a['email']], $rec->to(), 'only the never-sent recipient is retried');
    same(0, campaign_retry_failed($id), 'interrupted rows are never retried');
    mailer(null, true);
});

// ---- R9: notices ------------------------------------------------------------------------

test('R9 notices: copies are queued with a hashed dedupe key; an interrupted publish completes later', function (): void {
    reset_comms();
    q("DELETE FROM users WHERE role IN ('member','group')");
    $long = fixture_full_member(['email' => str_repeat('l', 60) . '@' . str_repeat('d', 60) . '.' . str_repeat('e', 50) . '.example.test']);
    $short = fixture_full_member();
    $id = db_insert('notices', ['title' => 'Long', 'body' => '<p>x</p>', 'audience' => json_encode(['type' => 'all_members', 'value' => '', 'field_id' => 0]), 'email_copy' => 1]);
    same(2, notice_publish($id), 'a 185-character address still gets its copy');
    ok(strlen((string) q_val("SELECT dedupe_key FROM email_queue WHERE to_email = ?", [$long['email']])) <= 190);
    // Simulate a crash after the first copy: emailed_at not yet set.
    q('UPDATE notices SET emailed_at = NULL WHERE id = ?', [$id]);
    q('DELETE FROM email_queue WHERE to_email = ?', [$short['email']]);
    same(1, notice_publish($id), 'publishing again queues only the missing copy');
    same(0, notice_publish($id));
    ok(q_val('SELECT emailed_at FROM notices WHERE id = ?', [$id]) !== null);
});

test('R9 notices: taking a notice down is audited', function (): void {
    $src = (string) file_get_contents(BASE . '/src/Admin/Notices.php');
    contains("audit('unpublish', 'notice'", $src);
});

// ---- R14: notices per page -----------------------------------------------------------

test('R14 notices: audience checks run once per distinct audience and once per request', function (): void {
    reset_comms();
    $m = fixture_full_member();
    for ($i = 0; $i < 12; $i++) {
        db_insert('notices', ['title' => "N$i", 'body' => '<p>x</p>', 'audience' => json_encode(['type' => 'all_members', 'value' => '', 'field_id' => 0]), 'status' => 'published', 'published_at' => utc_now()]);
    }
    notices_forget();
    $q0 = (int) (q_one("SHOW SESSION STATUS LIKE 'Questions'")['Value'] ?? 0);
    same(12, count(notices_for_user($m['user_id'])));
    same(12, notices_unread_count($m['user_id']));
    $q1 = (int) (q_one("SHOW SESSION STATUS LIKE 'Questions'")['Value'] ?? 0);
    ok($q1 - $q0 <= 4, 'queries used: ' . ($q1 - $q0) . ' (was one per notice)');
    same(1, notice_mark_read($m['user_id'], (int) q_val("SELECT id FROM notices WHERE title = 'N0'")));
    same(11, notices_unread_count($m['user_id']), 'cache cleared after marking read');
});

// ---- R10: campaigns ------------------------------------------------------------------

test('R10 campaigns: saving is refused once queued, and the queued subject is frozen', function (): void {
    reset_comms();
    q("DELETE FROM users WHERE role IN ('member','group')");
    fixture_full_member(['first_name' => 'Fay']);
    $rec = rec_mailer();
    $id = fixture_campaign(['type' => 'all_members', 'value' => '', 'field_id' => 0]);
    ok(campaign_save_draft($id, ['subject' => 'Draft subject {first_name}']));
    campaign_queue($id, 1_900_600_000);
    ok(!campaign_save_draft($id, ['subject' => 'Changed after queuing']), 'autosave after queuing is refused');
    q("UPDATE campaigns SET subject = 'Changed behind the lock' WHERE id = ?", [$id]);
    mail_queue_run(1_900_600_000, 50);
    same('Draft subject Fay', $rec->sent[0]->subject, 'recipients get the subject frozen at queue time');
    mailer(null, true);
});

// ---- R5/R12: cron ----------------------------------------------------------------------

test('R5 cron: a failed daily job is not marked done and is retried every 15 minutes', function (): void {
    $tz = new DateTimeZone('UTC');
    $job = ['daily_at' => '03:00'];
    $day = (new DateTimeImmutable('2030-05-01 03:00:00', $tz))->getTimestamp();
    ok(cron_is_due($job, null, $day, $tz, null));
    ok(!cron_is_due($job, null, $day + 600, $tz, $day), '10 minutes after a failure: wait');
    ok(cron_is_due($job, null, $day + 900, $tz, $day), '15 minutes after a failure: retry');
    ok(!cron_is_due($job, $day + 900, $day + 3600, $tz, null), 'done for the day after a success');
    ok(cron_is_due($job, $day + 900, $day + 86400, $tz, $day + 900), 'due again the next day');
});

test('R5 cron: results are stored per job straight away, failures of daily jobs separately', function (): void {
    setting_set('cron.jobs', []);
    setting_set('cron.failed', []);
    $t = 1_900_700_000;
    same('failed: boom', cron_run_job('backups', ['daily_at' => '03:00', 'run' => static fn () => throw new RuntimeException('boom')], $t));
    same(null, ((array) setting('cron.jobs', []))['backups'] ?? null, 'a failed daily job is not marked as run');
    same($t, (int) ((array) setting('cron.failed', []))['backups']);
    same('ok', cron_run_job('backups', ['daily_at' => '03:00', 'run' => static fn () => 'ok'], $t + 900));
    same($t + 900, (int) ((array) setting('cron.jobs', []))['backups']);
    same(null, ((array) setting('cron.failed', []))['backups'] ?? null, 'failure cleared after a success');
    cron_state_set('cron.jobs', 'cleanup', $t);
    same($t + 900, (int) ((array) setting('cron.jobs', []))['backups'], 'other jobs are kept');
});

test('R12 cron: backups run outside the main cron lock', function (): void {
    ok(!empty(cron_jobs()['backups']['own_lock']));
    // Each test job reports whether another process could take the main cron lock while it runs.
    $probe = static function (): string {
        $fh = fopen(BASE . '/storage/cache/cron.lock', 'c');
        $free = flock($fh, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($fh, LOCK_UN);
        }
        fclose($fh);
        return $free ? 'main lock free' : 'main lock held';
    };
    $results = cron_run(null, true, ['quick' => ['every' => 1, 'run' => $probe], 'slow_backup' => ['daily_at' => '00:00', 'run' => $probe, 'own_lock' => true]]);
    same('main lock free', $results['slow_backup'], 'the next minute\'s cron run is not blocked by a backup');
    same('main lock held', $results['quick']);
    $bl = fopen(BASE . '/storage/cache/backup.lock', 'c');
    flock($bl, LOCK_EX | LOCK_NB);
    throws(RuntimeException::class, fn () => backup_run(false));
    flock($bl, LOCK_UN);
    fclose($bl);
});

// ---- R11: backups ----------------------------------------------------------------------

test('R11 backups: a short write is an error, not a silently truncated file', function (): void {
    // A stream wrapper that accepts only the first 100 bytes, like a full disk.
    stream_wrapper_register('fulldisk', FullDiskStream::class);
    $fh = fopen('fulldisk://x', 'wb');
    throws(RuntimeException::class, fn () => backup_dump_db($fh, true));
    fclose($fh);
    stream_wrapper_unregister('fulldisk');
});

test('R11 backups: stored dumps are verified (gzip trailer) and stale .part files swept', function (): void {
    foreach (glob(backup_dir() . '/gather-*') ?: [] as $f) {
        unlink($f);
    }
    $stale = backup_dir() . '/.gather-20000101-000000-db.sql.gz.part';
    file_put_contents($stale, 'half a backup');
    $files = backup_run(false, 1_900_800_000);
    ok(!is_file($stale), 'stale .part removed');
    $path = (string) backup_path($files[0]);
    $sql = (string) gzdecode((string) file_get_contents($path));
    ok(str_ends_with($sql, "-- End of backup\n"));
    // A truncated copy fails verification.
    $info = ['out_bytes' => filesize($path), 'raw_bytes' => strlen($sql), 'crc32' => hash('crc32b', $sql)];
    backup_verify_dump($path, $info, true);
    $cut = $path . '.cut';
    file_put_contents($cut, substr((string) file_get_contents($path), 0, -20));
    throws(RuntimeException::class, fn () => backup_verify_dump($cut, $info, true));
    unlink($cut);
    foreach (glob(backup_dir() . '/gather-*') ?: [] as $f) {
        unlink($f);
    }
});

test('R11 backups: manual and scheduled backups are pruned separately', function (): void {
    foreach (glob(backup_dir() . '/gather-*') ?: [] as $f) {
        unlink($f);
    }
    setting_set('backups', ['enabled' => true, 'time' => '03:00', 'keep' => 2, 'uploads' => false]);
    $t = 1_900_900_000;
    backup_run(false, $t);
    backup_run(false, $t + 60);
    foreach ([120, 180, 240] as $o) {
        backup_run(false, $t + $o, true);
    }
    $list = backup_list();
    same(2, count(array_filter($list, static fn (array $b): bool => !$b['manual'])), 'both daily backups kept despite three manual ones');
    same(2, count(array_filter($list, static fn (array $b): bool => $b['manual'])));
    ok(str_contains($list[0]['name'], '-manual-'), 'newest first');
    foreach (glob(backup_dir() . '/gather-*') ?: [] as $f) {
        unlink($f);
    }
});

// ---- Low: email queue retention ------------------------------------------------

test('Low cleanup: sent queued emails are purged after 90 days', function (): void {
    reset_comms();
    mail_queue_message(new Message('old@example.test', '', 'old', 'x'), 'notice', 'old-1');
    mail_queue_message(new Message('new@example.test', '', 'new', 'x'), 'notice', 'new-1');
    q("UPDATE email_queue SET status = 'sent', sent_at = UTC_TIMESTAMP() - INTERVAL 91 DAY WHERE to_email = 'old@example.test'");
    q("UPDATE email_queue SET status = 'sent', sent_at = UTC_TIMESTAMP() - INTERVAL 10 DAY WHERE to_email = 'new@example.test'");
    cron_cleanup();
    same(['new@example.test'], array_column(q_all('SELECT to_email FROM email_queue'), 'to_email'));
});

/** Stream wrapper that stops accepting data after 100 bytes. */
final class FullDiskStream
{
    public $context;
    private int $written = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        $room = max(0, 100 - $this->written);
        $n = min($room, strlen($data));
        $this->written += $n;
        return $n;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }
}
