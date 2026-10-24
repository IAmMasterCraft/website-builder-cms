<?php
declare(strict_types=1);

// Phase 5: mail transport and queue, unsubscribe tokens, audiences,
// campaigns, templates, notices, birthdays, jobs, likes and comments.

use Core\Mail\Mailer;
use Core\Mail\Message;

/** Records messages; can be told to fail the next N sends. */
final class RecordingMailer implements Mailer
{
    /** @var list<Message> */
    public array $sent = [];
    public int $failNext = 0;

    public function send(Message $message): void
    {
        if ($this->failNext > 0) {
            $this->failNext--;
            throw new RuntimeException('SMTP 451 try again later');
        }
        $this->sent[] = $message;
    }

    /** @return list<string> */
    public function to(): array
    {
        return array_map(static fn (Message $m): string => $m->toEmail, $this->sent);
    }
}

function rec_mailer(): RecordingMailer
{
    $m = new RecordingMailer();
    mailer($m);
    return $m;
}

function reset_comms(): void
{
    foreach (['campaign_recipients', 'campaigns', 'email_queue', 'email_suppressions', 'notice_reads', 'notices', 'birthday_sends', 'job_reports', 'jobs', 'comment_reports', 'comments', 'likes', 'email_templates'] as $t) {
        q('DELETE FROM ' . db_ident($t));
    }
    q("DELETE FROM settings WHERE `key` LIKE 'birthdays.digest.%'");
    setting_set('mail.per_minute', 60);
    setting_set('jobs', []);
    setting_set('comments.moderation', 'pre');
    engagement_forget();
    settings_all([]);
}

/** An active member with a members row. */
function fixture_full_member(array $m = [], array $prefs = []): array
{
    $email = ($m['email'] ?? 'm-' . bin2hex(random_bytes(4))) . (str_contains($m['email'] ?? '', '@') ? '' : '@example.test');
    $uid = create_user($email, 'correct horse battery', 'member', $m['first_name'] ?? 'Ada', $m['status'] ?? 'active');
    $mid = db_insert('members', [
        'user_id' => $uid, 'first_name' => $m['first_name'] ?? 'Ada', 'last_name' => $m['last_name'] ?? 'Okafor',
        'membership_type' => $m['type'] ?? 'Standard', 'region' => $m['region'] ?? 'England',
        'birth_day' => $m['birth_day'] ?? 1, 'birth_month' => $m['birth_month'] ?? 1, 'member_number' => $m['number'] ?? null,
        'prefs' => json_encode($prefs + ['newsletters' => true, 'birthday_greeting' => true, 'celebrants' => false]),
    ]);
    return ['user_id' => $uid, 'member_id' => $mid, 'email' => strtolower($email)];
}

// ---- Transport and templates ----------------------------------------------------

test('SMTP password is stored encrypted and round-trips', function (): void {
    $sealed = secret_seal('s3cret-pass');
    ok(str_starts_with($sealed, 'sb1:') && !str_contains($sealed, 's3cret'));
    same('s3cret-pass', secret_open($sealed));
    same('', secret_open('sb1:' . base64_encode(str_repeat('x', 40))), 'tampered value');
    same('', secret_seal(''));
});

test('SmtpMailer delivers through a real SMTP conversation (local test server)', function (): void {
    $script = sys_get_temp_dir() . '/gather-smtp-' . bin2hex(random_bytes(3)) . '.php';
    $log = $script . '.log';
    $port = 2525 + random_int(0, 400);
    file_put_contents($script, <<<'PHP'
<?php
// One-connection SMTP sink for tests.
[$port, $log] = [(int) $argv[1], $argv[2]];
$srv = stream_socket_server("tcp://127.0.0.1:$port", $errno, $err);
$c = stream_socket_accept($srv, 10);
$w = function (string $s) use ($c) { fwrite($c, $s . "\r\n"); };
$w('220 test ESMTP');
$data = false; $buf = '';
while (($line = fgets($c)) !== false) {
    if ($data) {
        if (rtrim($line, "\r\n") === '.') { $data = false; file_put_contents($log, $buf); $w('250 OK queued'); continue; }
        $buf .= $line; continue;
    }
    $cmd = strtoupper(substr(trim($line), 0, 4));
    if ($cmd === 'EHLO' || $cmd === 'HELO') { fwrite($c, "250-test\r\n250 AUTH PLAIN\r\n"); }
    elseif ($cmd === 'AUTH') { $w('334 '); fgets($c); $w('235 ok'); }
    elseif ($cmd === 'DATA') { $data = true; $w('354 go'); }
    elseif ($cmd === 'QUIT') { $w('221 bye'); break; }
    else { $w('250 OK'); }
}
PHP);
    $proc = proc_open([PHP_BINARY, $script, (string) $port, $log], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    usleep(300000);
    try {
        $m = new \Core\Mail\SmtpMailer(['host' => '127.0.0.1', 'port' => $port, 'encryption' => 'none', 'username' => 'u', 'password' => 'p', 'timeout' => 5], 'from@example.test', 'Gather Tests');
        $m->send(new Message('to@example.test', 'To Person', 'Hello over SMTP', "Plain body\n", '<p>HTML body</p>', null, ['List-Unsubscribe' => '<https://x.test/u>']));
        unset($m);
        $raw = '';
        for ($i = 0; $i < 30 && $raw === ''; $i++) {
            usleep(50000);
            $raw = is_file($log) ? (string) file_get_contents($log) : '';
        }
        contains('Subject: Hello over SMTP', $raw);
        contains('List-Unsubscribe: <https://x.test/u>', $raw);
        contains('HTML body', quoted_printable_decode($raw));
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        is_file($script) && unlink($script);
        is_file($log) && unlink($log);
    }
});

test('transactional templates fill every placeholder listed in §5 and escape HTML', function (): void {
    reset_comms();
    foreach (['verify_email', 'welcome_member', 'password_reset', 'event_registration', 'job_approved', 'job_rejected', 'comment_reply', 'birthday_greeting', 'contact_copy'] as $key) {
        $def = email_template_def($key);
        $vars = email_template_samples($def);
        $r = email_template_render($key, $vars);
        ok($r['subject'] !== '', "$key subject");
        foreach (email_placeholders_in($def['body'] . $def['subject']) as $ph) {
            not_contains('{' . $ph . '}', $r['text'], "$key leaves {{$ph}}");
        }
        contains('<!doctype html>', $r['html']);
    }
    $r = email_template_render('welcome_member', ['first_name' => '<b>Eve</b>', 'member_number' => 'ORG-26-0042', 'card_url' => 'https://x.test/card']);
    same('Welcome! Your member number is ORG-26-0042', $r['subject']);
    contains('ORG-26-0042', $r['text']);
    contains('&lt;b&gt;Eve&lt;/b&gt;', $r['html']);
    not_contains('<b>Eve</b>', $r['html']);
    contains(design_effective()['brand'], $r['html'], 'theme colour in the layout');
    contains('See my member card: https://x.test/card', $r['text']);
});

test('edited templates override defaults; unknown placeholders are rejected', function (): void {
    reset_comms();
    same([], email_template_validate('birthday_greeting', 'Cheers {first_name}', "Hi {first_name} from {site_name}", ''));
    $e = email_template_validate('birthday_greeting', 'Hi {firstname}', 'Body {member_number}', '');
    ok(isset($e['subject'], $e['body']), json_encode($e));
    q('INSERT INTO email_templates (template_key, subject, body, button_label) VALUES (?, ?, ?, ?)', ['birthday_greeting', 'Many happy returns, {first_name}', "Dear {first_name},\n\nCake time.", 'Open']);
    $r = email_template_render('birthday_greeting', ['first_name' => 'Sam', 'account_url' => 'https://x.test/a']);
    same('Many happy returns, Sam', $r['subject']);
    contains('Cake time.', $r['text']);
    contains('Open: https://x.test/a', $r['text']);
    q('DELETE FROM email_templates');
});

test('send_template_mail queues a retry when the transport fails', function (): void {
    reset_comms();
    $rec = rec_mailer();
    $rec->failNext = 1;
    same(false, send_template_mail('late@example.test', 'Lee', 'password_reset', ['reset_url' => 'https://x.test/r']));
    $row = q_one("SELECT * FROM email_queue WHERE to_email = 'late@example.test'");
    ok($row !== null && $row['status'] === 'queued' && (int) $row['attempts'] === 1);
    $s = mail_queue_run(time() + MAIL_RETRY_SECONDS + 1);
    same(1, $s['sent']);
    same(['late@example.test'], $rec->to());
    mailer(null, true);
});

// ---- Unsubscribe and audiences -----------------------------------------------------

test('unsubscribe tokens are signed per address and cannot be forged', function (): void {
    $t = unsubscribe_token('Pat@Example.test');
    same('pat@example.test', unsubscribe_verify($t));
    [$payload, $sig] = explode('.', $t);
    same(null, unsubscribe_verify(b64url('other@example.test') . '.' . $sig), 'signature bound to the address');
    same(null, unsubscribe_verify($payload . '.' . str_repeat('0', 32)));
    same(null, unsubscribe_verify('garbage'));
    contains('/unsubscribe/' . $t, unsubscribe_url('pat@example.test'));
});

test('unsubscribing turns newsletters off and suppresses the address; opting in again lifts it', function (): void {
    reset_comms();
    $m = fixture_full_member();
    email_unsubscribe($m['email']);
    ok(email_suppressed($m['email']));
    same(false, member_pref(member_for_user($m['user_id']), 'newsletters'));
    email_resubscribe($m['email']);
    ok(!email_suppressed($m['email']));
});

test('audience resolution: segments, preferences, suppression and dedupe', function (): void {
    reset_comms();
    q("DELETE FROM users WHERE role IN ('member','group')");
    $a = fixture_full_member(['type' => 'Life', 'region' => 'Wales', 'birth_month' => 3]);
    $b = fixture_full_member(['type' => 'Standard', 'region' => 'Wales', 'birth_month' => 7]);
    $c = fixture_full_member(['type' => 'Life', 'region' => 'England'], ['newsletters' => false]);
    $d = fixture_full_member(['type' => 'Life', 'region' => 'England', 'status' => 'unverified']);
    $e = fixture_full_member(['type' => 'Life']);
    email_unsubscribe($e['email']);
    $emails = static fn (array $r): array => array_column($r['recipients'], 'email');

    $all = audience_resolve(['type' => 'all_members', 'value' => '', 'field_id' => 0]);
    same([$a['email'], $b['email']], $emails($all), 'opted-in active members only');
    same(2, $all['excluded'], 'newsletters off + unsubscribed are counted as excluded');
    same([$a['email']], $emails(audience_resolve(['type' => 'membership_type', 'value' => 'Life', 'field_id' => 0])));
    same([$a['email'], $b['email']], $emails(audience_resolve(['type' => 'region', 'value' => 'Wales', 'field_id' => 0])));
    same([$a['email']], $emails(audience_resolve(['type' => 'birthday_month', 'value' => '', 'field_id' => 0], true, new DateTimeImmutable('2026-03-10 12:00', utc()))));

    $f = db_insert('custom_fields', ['field_key' => 'aud_district', 'label' => 'District', 'type' => 'text']);
    q('INSERT INTO member_field_values (member_id, field_id, value) VALUES (?, ?, ?)', [$b['member_id'], $f, 'Riverside']);
    same([$b['email']], $emails(audience_resolve(['type' => 'custom_field', 'value' => 'Riverside', 'field_id' => $f])));
    q('DELETE FROM custom_fields WHERE id = ?', [$f]);

    $ev = fixture_content('events', ['title' => 'Audience Picnic', 'status' => 'published', 'start_at' => local_in('+5 days'), 'timezone' => site_timezone_name(), 'registration_open' => '1', 'guest_registration' => '1']);
    db_insert('event_registrations', ['event_id' => $ev, 'name' => 'Gina Guest', 'email' => 'gina@example.test', 'quantity' => 1, 'status' => 'registered']);
    db_insert('event_registrations', ['event_id' => $ev, 'name' => 'Gina Again', 'email' => 'GINA@example.test', 'quantity' => 1, 'status' => 'registered']);
    db_insert('event_registrations', ['event_id' => $ev, 'name' => 'Cancelled', 'email' => 'gone@example.test', 'quantity' => 1, 'status' => 'cancelled']);
    db_insert('event_registrations', ['event_id' => $ev, 'user_id' => $b['user_id'], 'name' => 'Member', 'email' => $b['email'], 'quantity' => 1, 'status' => 'registered']);
    same(['gina@example.test', $b['email']], $emails(audience_resolve(['type' => 'event', 'value' => (string) $ev, 'field_id' => 0])), 'registrants, deduped, cancelled left out');

    $g = create_user('grp-' . bin2hex(random_bytes(3)) . '@example.test', 'correct horse battery', 'group', 'River Group');
    ok(in_array(q_val('SELECT email FROM users WHERE id = ?', [$g]), $emails(audience_resolve(['type' => 'groups', 'value' => '', 'field_id' => 0])), true));
    $admins = $emails(audience_resolve(['type' => 'admins', 'value' => '', 'field_id' => 0]));
    ok($admins !== [] && !in_array($a['email'], $admins, true));

    ok(audience_includes_user(['type' => 'region', 'value' => 'Wales', 'field_id' => 0], $b['user_id']));
    ok(!audience_includes_user(['type' => 'region', 'value' => 'Wales', 'field_id' => 0], $c['user_id']));
    ok(audience_includes_user(['type' => 'all_members', 'value' => '', 'field_id' => 0], $c['user_id']), 'dashboard notices ignore email preferences');
    [, $err] = audience_validate(['audience_type' => 'region', 'audience_region' => 'Atlantis']);
    ok(isset($err['audience']));
});

// ---- Campaigns and the queue ------------------------------------------------------

function fixture_campaign(array $audience, array $blocks = []): int
{
    return db_insert('campaigns', [
        'subject' => 'Hello {first_name}', 'blocks' => json_encode($blocks ?: [['type' => 'heading', 'text' => 'Spring news', 'size' => 'large'], ['type' => 'text', 'html' => '<p>Dear {first_name}, <script>x</script>welcome.</p>'], ['type' => 'button', 'label' => 'Visit', 'url' => '/events']]),
        'audience' => json_encode($audience),
    ]);
}

test('campaign blocks validate, sanitise and render responsive email HTML', function (): void {
    [$blocks, $errors] = campaign_blocks_validate([
        ['type' => 'heading', 'text' => ''], ['type' => 'text', 'html' => '<p onclick="x()">Hi</p><script>bad()</script>'],
        ['type' => 'button', 'label' => 'Go', 'url' => 'javascript:alert(1)'], ['type' => 'image', 'media_id' => '999999'],
        ['type' => 'latest_posts', 'count' => '50', 'base' => 'nope'], ['type' => 'bogus'], ['type' => 'divider'],
    ]);
    ok(isset($errors['b0.text'], $errors['b2.url'], $errors['b3.media_id']), json_encode($errors));
    same(6, count($blocks), 'unknown block types are dropped');
    not_contains('script', $blocks[1]['html']);
    not_contains('onclick', $blocks[1]['html']);
    same(6, $blocks[4]['count']);
    same('', $blocks[4]['base']);
    $id = fixture_campaign(['type' => 'admins']);
    $r = campaign_render(q_one('SELECT * FROM campaigns WHERE id = ?', [$id]), ['email' => 'x@example.test', 'first_name' => 'Xu']);
    contains('Dear Xu,', $r['html']);
    contains('@media (max-width: 620px)', $r['html']);
    contains(base_url('/events'), $r['html'], 'relative links made absolute');
    contains('/unsubscribe/', $r['html']);
    contains('Unsubscribe: ' . base_url('/unsubscribe/'), $r['text']);
    same('Hello Xu', $r['subject']);
});

test('queue sends in batches within the per-minute limit and resumes without duplicates', function (): void {
    reset_comms();
    q("DELETE FROM users WHERE role IN ('member','group')");
    $members = [];
    for ($i = 0; $i < 7; $i++) {
        $members[] = fixture_full_member(['first_name' => 'Batch' . $i]);
    }
    $rec = rec_mailer();
    $id = fixture_campaign(['type' => 'all_members', 'value' => '', 'field_id' => 0]);
    $t0 = 1_900_000_000;
    same(7, campaign_queue($id, $t0));
    same(0, campaign_queue($id, $t0), 'queuing twice adds nobody (status is no longer draft)');
    same('sending', q_val('SELECT status FROM campaigns WHERE id = ?', [$id]));

    $s = mail_queue_run($t0, 3);
    same(3, $s['sent']);
    same(0, mail_queue_run($t0 + 10, 3)['sent'], 'limit applies per minute, even if cron runs again');
    // Simulate a crash mid-batch: one row claimed but never finished.
    $stuck = (int) q_val("SELECT id FROM campaign_recipients WHERE campaign_id = ? AND status = 'queued' ORDER BY id LIMIT 1", [$id]);
    q("UPDATE campaign_recipients SET status = 'sending', claimed_at = ? WHERE id = ?", [gmdate('Y-m-d H:i:s', $t0 + 20), $stuck]);
    $s = mail_queue_run($t0 + 61, 3);
    same(3, $s['sent']);
    $s = mail_queue_run($t0 + 61 + MAIL_STUCK_SECONDS + 60, 3);
    same(1, $s['interrupted'], 'a row stuck in "sending" is failed, never re-sent');
    same(0, (int) q_val("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND status IN ('queued','sending')", [$id]));
    same('sent', q_val('SELECT status FROM campaigns WHERE id = ?', [$id]));
    $to = $rec->to();
    same(count($to), count(array_unique($to)), 'nobody got the campaign twice');
    same(6, count($to));
    same('Hello Batch0', $rec->sent[0]->subject);
    contains('One-Click', $rec->sent[0]->headers['List-Unsubscribe-Post']);
    contains(unsubscribe_token($rec->sent[0]->toEmail), (string) $rec->sent[0]->html, 'personal unsubscribe link');
    // Re-queuing the same recipients is impossible.
    q("UPDATE campaigns SET status = 'draft' WHERE id = ?", [$id]);
    same(0, campaign_queue($id, $t0 + 9999), 'unique (campaign, email) blocks a second copy');
    mailer(null, true);
});

test('failed sends are retried and then marked failed', function (): void {
    reset_comms();
    q("DELETE FROM users WHERE role IN ('member','group')");
    fixture_full_member(['first_name' => 'Flaky']);
    $rec = rec_mailer();
    $rec->failNext = MAIL_MAX_ATTEMPTS;
    $id = fixture_campaign(['type' => 'all_members']);
    $t = 1_900_100_000;
    campaign_queue($id, $t);
    same(1, mail_queue_run($t, 10)['retry']);
    same(0, mail_queue_run($t + 60, 10)['sent'], 'not retried before the back-off');
    same(1, mail_queue_run($t + MAIL_RETRY_SECONDS + 1, 10)['retry']);
    same(1, mail_queue_run($t + MAIL_RETRY_SECONDS * 4, 10)['failed']);
    $row = q_one('SELECT * FROM campaign_recipients WHERE campaign_id = ?', [$id]);
    same('failed', $row['status']);
    contains('451', $row['error']);
    same('sent', q_val('SELECT status FROM campaigns WHERE id = ?', [$id]), 'campaign completes with a failed recipient');
    mailer(null, true);
});

test('scheduled campaigns are queued by cron when due', function (): void {
    reset_comms();
    q("DELETE FROM users WHERE role IN ('member','group')");
    fixture_full_member();
    $id = fixture_campaign(['type' => 'all_members']);
    q("UPDATE campaigns SET status = 'scheduled', send_at = ? WHERE id = ?", [gmdate('Y-m-d H:i:s', 1_900_200_000), $id]);
    same(0, campaigns_due(1_900_199_000));
    same(1, campaigns_due(1_900_200_001));
    same('sending', q_val('SELECT status FROM campaigns WHERE id = ?', [$id]));
    ok((string) q_val('SELECT html_snapshot FROM campaigns WHERE id = ?', [$id]) !== '', 'content snapshot taken when queued');
});

// ---- Notices -----------------------------------------------------------------------

test('notices reach their audience, track reads and queue one email copy', function (): void {
    reset_comms();
    q("DELETE FROM users WHERE role IN ('member','group')");
    $w = fixture_full_member(['region' => 'Wales']);
    $e = fixture_full_member(['region' => 'England']);
    $off = fixture_full_member(['region' => 'Wales'], ['newsletters' => false]);
    $id = db_insert('notices', ['title' => 'Hall closed', 'body' => '<p>The hall is closed on Monday.</p>', 'audience' => json_encode(['type' => 'region', 'value' => 'Wales', 'field_id' => 0]), 'email_copy' => 1]);
    same([], notices_for_user($w['user_id']), 'drafts are not shown');
    same(1, notice_publish($id), 'email copy honours preferences');
    same(0, notice_publish($id), 'email copy is only queued once');
    same(1, count(notices_for_user($w['user_id'])));
    same(1, count(notices_for_user($off['user_id'])), 'dashboard notice still shown without newsletters');
    same([], notices_for_user($e['user_id']));
    same(1, notices_unread_count($w['user_id']));
    same(1, notice_mark_read($w['user_id'], $id));
    same(0, notice_mark_read($w['user_id'], $id));
    same(0, notices_unread_count($w['user_id']));
    same(0, notice_mark_read($e['user_id'], $id), 'cannot mark notices outside your audience');
    $q = q_one("SELECT * FROM email_queue WHERE kind = 'notice'");
    same($w['email'], $q['to_email']);
    contains('List-Unsubscribe', (string) $q['headers']);
});

// ---- Birthdays ------------------------------------------------------------------------

test('birthday job: greets once per year, digest once a day, honours the greeting preference', function (): void {
    reset_comms();
    q("DELETE FROM users WHERE role IN ('member','group')");
    $tz = site_tz();
    $today = new DateTimeImmutable('2026-06-15 08:00', $tz);
    $a = fixture_full_member(['first_name' => 'Today', 'birth_day' => 15, 'birth_month' => 6]);
    $b = fixture_full_member(['first_name' => 'NoGreet', 'birth_day' => 15, 'birth_month' => 6], ['birthday_greeting' => false]);
    $c = fixture_full_member(['first_name' => 'Soon', 'birth_day' => 20, 'birth_month' => 6]);
    $d = fixture_full_member(['first_name' => 'Later', 'birth_day' => 30, 'birth_month' => 6]);
    $s = birthday_run($today);
    same(1, $s['greeted']);
    ok($s['digests'] >= 1);
    $again = birthday_run($today->modify('+2 hours'));
    same(0, $again['greeted'], 'second run the same day sends nothing');
    same(0, $again['digests']);
    same(1, (int) q_val('SELECT COUNT(*) FROM birthday_sends WHERE member_id = ?', [$a['member_id']]));
    same(1, (int) q_val("SELECT COUNT(*) FROM email_queue WHERE kind = 'birthday'"));
    $digest = (string) q_val("SELECT text_body FROM email_queue WHERE kind = 'digest' LIMIT 1");
    contains('Today Okafor', $digest);
    contains('NoGreet Okafor', $digest);
    contains('Soon Okafor', $digest);
    not_contains('Later Okafor', $digest, 'more than 7 days away');
    same(1, birthday_run($today->modify('+1 year'))['greeted'], 'next year greets again');
    unset($b, $c, $d);
});

test('29 February birthdays are celebrated on 28 February in non-leap years', function (): void {
    reset_comms();
    q("DELETE FROM users WHERE role IN ('member','group')");
    $leapling = fixture_full_member(['first_name' => 'Leap', 'birth_day' => 29, 'birth_month' => 2]);
    $tz = site_tz();
    same(0, birthday_run(new DateTimeImmutable('2027-02-27 09:00', $tz))['greeted']);
    same(1, birthday_run(new DateTimeImmutable('2027-02-28 09:00', $tz))['greeted']);
    same(0, birthday_run(new DateTimeImmutable('2027-03-01 09:00', $tz))['greeted']);
    same(0, birthday_run(new DateTimeImmutable('2028-02-28 09:00', $tz))['greeted'], 'leap year: not on the 28th');
    same(1, birthday_run(new DateTimeImmutable('2028-02-29 09:00', $tz))['greeted']);
    same(2, (int) q_val('SELECT COUNT(*) FROM birthday_sends WHERE member_id = ?', [$leapling['member_id']]));
    $dates = birthday_dates_ahead(new DateTimeImmutable('2027-02-25', $tz), 7);
    ok(in_array([2, 29, '2027-02-28'], $dates, true), 'digest window maps 29 Feb to 28 Feb');
});

test('daily cron jobs run once per local day at or after their time', function (): void {
    $tz = new DateTimeZone('Europe/London');
    $job = ['daily_at' => '07:00'];
    $at = static fn (string $local): int => (new DateTimeImmutable($local, $tz))->getTimestamp();
    ok(!cron_is_due($job, null, $at('2026-06-15 06:59'), $tz));
    ok(cron_is_due($job, null, $at('2026-06-15 07:00'), $tz));
    ok(!cron_is_due($job, $at('2026-06-15 07:01'), $at('2026-06-15 21:00'), $tz));
    ok(cron_is_due($job, $at('2026-06-15 07:01'), $at('2026-06-16 07:05'), $tz));
});

// ---- Jobs ---------------------------------------------------------------------------

function job_input(array $over = []): array
{
    return $over + ['title' => 'Youth worker', 'organisation' => 'Harbour Trust', 'location' => 'Leeds', 'job_type' => 'part_time', 'description' => 'Run Friday clubs.', 'apply_email' => 'jobs@example.test'];
}

test('job validation and expiry (closing date or 60 days)', function (): void {
    [$d, $e] = job_validate(job_input());
    same([], $e);
    [, $e] = job_validate(job_input(['title' => '', 'job_type' => 'gig', 'apply_email' => '', 'apply_url' => 'ftp://x', 'closing_date' => '2001-01-01']));
    ok(isset($e['title'], $e['job_type'], $e['apply_url'], $e['closing_date']), json_encode($e));
    same('2026-03-02 10:00:00', job_expiry('2026-01-01 10:00:00', null), '60 days');
    $tz = site_tz();
    $close = (new DateTimeImmutable('2026-01-10', $tz))->modify('+1 day')->setTimezone(utc())->format('Y-m-d H:i:s');
    same($close, job_expiry('2026-01-01 10:00:00', '2026-01-10'), 'end of the closing day, site time');
    same('2026-03-02 10:00:00', job_expiry('2026-01-01 10:00:00', '2026-12-31'), 'closing date later than 60 days');
});

test('job lifecycle: pending, approval, visibility, report, expiry; trusted auto-approval', function (): void {
    reset_comms();
    rec_mailer();
    $m = fixture_full_member(['first_name' => 'Poster']);
    $member = q_one('SELECT * FROM users WHERE id = ?', [$m['user_id']]);
    [$d] = job_validate(job_input());
    [$id, $status] = job_create($d, $member);
    same('pending', $status);
    same(0, jobs_board([], 10, 0)['total'], 'pending jobs are not on the board');
    $admin = fixture_admin('admin', ['jobs']);
    $now = new DateTimeImmutable('now', utc());
    ok(job_approve($id, $admin, $now));
    same(1, jobs_board([], 10, 0)['total']);
    same(1, jobs_board(['type' => 'part_time', 'q' => 'Friday'], 10, 0)['total']);
    same(0, jobs_board(['remote' => '1'], 10, 0)['total']);
    // Visibility (D8): members only by default.
    ok(!jobs_visible_to(null));
    ok(jobs_visible_to(current_user_row($m['user_id'])));
    setting_set('jobs', ['public' => true]);
    ok(jobs_visible_to(null));
    setting_set('jobs', []);
    // Report once per member.
    $other = fixture_full_member();
    ok(job_report($id, $other['user_id'], 'Looks like spam'));
    ok(!job_report($id, $other['user_id'], 'again'));
    // Expiry.
    q('UPDATE jobs SET expires_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 60), $id]);
    same(0, jobs_board([], 10, 0)['total'], 'expired jobs disappear immediately');
    same(1, jobs_expire());
    same('expired', q_val('SELECT status FROM jobs WHERE id = ?', [$id]));
    // Trusted members are auto-approved only with the setting on.
    ok(job_poster_trusted($m['user_id']));
    [$id2, $st2] = job_create($d, $member);
    same('pending', $st2);
    setting_set('jobs', ['auto_approve_trusted' => true]);
    [$id3, $st3] = job_create($d, $member);
    same('published', $st3);
    ok(job_reject($id2, $admin, 'Duplicate'));
    ok(!job_poster_trusted($m['user_id']), 'a rejection removes trust');
    [, $st4] = job_create($d, q_one('SELECT * FROM users WHERE id = ?', [$other['user_id']]));
    same('pending', $st4, 'members with no approved job are not trusted');
    setting_set('jobs', []);
    $mails = array_map(static fn (Message $x): string => $x->subject, mailer() instanceof RecordingMailer ? mailer()->sent : []);
    ok(in_array('Your job post is live: Youth worker', $mails, true), json_encode($mails));
    ok(in_array('Your job post was not approved', $mails, true));
    unset($id3);
    mailer(null, true);
});

/** Users row with permissions, like current_user(). */
function current_user_row(int $id): array
{
    $u = q_one('SELECT id, email, role, status, display_name FROM users WHERE id = ?', [$id]);
    $u['permissions'] = [];
    return $u;
}

// ---- Likes and comments -----------------------------------------------------------------

function fixture_post_id(array $over = []): int
{
    return fixture_content('posts', $over + ['title' => 'Talk post ' . bin2hex(random_bytes(3)), 'status' => 'published', 'category_id' => (string) q_val("SELECT id FROM categories WHERE slug = 'news'"), 'body' => '<p>Hello</p>', 'allow_likes' => '1', 'allow_comments' => '1']);
}

test('likes: one per member per item, toggle, counts', function (): void {
    reset_comms();
    $post = fixture_post_id();
    $a = fixture_full_member();
    $b = fixture_full_member();
    same([true, 1], like_toggle($a['user_id'], 'post', $post));
    same([true, 2], like_toggle($b['user_id'], 'post', $post));
    same([false, 1], like_toggle($a['user_id'], 'post', $post));
    q('INSERT IGNORE INTO likes (user_id, item_type, item_id) VALUES (?, ?, ?)', [$b['user_id'], 'post', $post]);
    same(1, like_count('post', $post), 'the unique key blocks a duplicate like');
    throws(PDOException::class, fn () => q('INSERT INTO likes (user_id, item_type, item_id) VALUES (?, ?, ?)', [$b['user_id'], 'post', $post]));
    engagement_forget();
    same(['likes' => 1, 'comments' => 0], engagement_counts('post', $post));
    $noLikes = fixture_post_id(['allow_likes' => '0']);
    same(null, engagement_item('post', $noLikes, 'likes', current_user_row($a['user_id'])), 'likes switched off on the post');
});

test('comments: pre-moderation, one reply level, 15-minute edit window, reports, reply email', function (): void {
    reset_comms();
    $rec = rec_mailer();
    $post = fixture_post_id();
    $author = fixture_full_member(['first_name' => 'Author', 'last_name' => 'Ade']);
    $replier = fixture_full_member(['first_name' => 'Rae', 'last_name' => 'Bello']);
    $au = current_user_row($author['user_id']);
    $ru = current_user_row($replier['user_id']);
    [$c1, $err] = comment_add($au, 'post', $post, '  First!  ');
    same(null, $err);
    same('pending', q_val('SELECT status FROM comments WHERE id = ?', [$c1]), 'D11: pre-moderated by default');
    same(1, count(comment_thread('post', $post, $au)), 'the author sees their own pending comment');
    same(0, count(comment_thread('post', $post, $ru)), 'others do not');
    [, $err] = comment_add($ru, 'post', $post, 'reply', $c1);
    ok($err !== null, 'cannot reply to a pending comment');
    $admin = fixture_admin('admin', ['content']);
    ok(comment_moderate($c1, 'approve', $admin));
    [$r1] = comment_add($ru, 'post', $post, 'Welcome!', $c1);
    ok(comment_moderate($r1, 'approve', $admin));
    [$r2, $err] = comment_add($au, 'post', $post, 'Thanks', $r1);
    same(null, $err);
    same($c1, (int) q_val('SELECT parent_id FROM comments WHERE id = ?', [$r2]), 'a reply to a reply joins the thread (one level)');
    $replyMail = array_values(array_filter($rec->sent, static fn (Message $m): bool => $m->subject === 'Rae B. replied to your comment'));
    same(1, count($replyMail), 'author is told about the reply once');
    same($author['email'], $replyMail[0]->toEmail);
    ok(comment_moderate($r1, 'approve', $admin));
    same(1, count(array_filter($rec->sent, static fn (Message $m): bool => $m->subject === 'Rae B. replied to your comment')), 'not twice');
    // Edit window.
    $row = q_one('SELECT * FROM comments WHERE id = ?', [$c1]);
    $created = new DateTimeImmutable((string) $row['created_at'], utc());
    ok(comment_editable($row, $au, $created->modify('+14 minutes')));
    ok(!comment_editable($row, $au, $created->modify('+15 minutes')));
    ok(!comment_editable($row, $ru, $created->modify('+1 minute')), 'only the author');
    same(null, comment_edit($c1, $au, 'First (edited)', $created->modify('+5 minutes')));
    same('pending', q_val('SELECT status FROM comments WHERE id = ?', [$c1]), 'edits go back to the queue when pre-moderated');
    ok(comment_edit($c1, $au, 'late', $created->modify('+20 minutes')) !== null);
    // Post-moderation setting.
    setting_set('comments.moderation', 'post');
    [$c2] = comment_add($ru, 'post', $post, 'Straight in');
    same('approved', q_val('SELECT status FROM comments WHERE id = ?', [$c2]));
    ok(comment_report($c2, $author['user_id'], 'rude'));
    ok(!comment_report($c2, $author['user_id'], 'again'), 'one report per member');
    ok(comment_moderate($c2, 'reject', $admin));
    same(0, (int) q_val('SELECT COUNT(*) FROM comment_reports WHERE comment_id = ? AND resolved_at IS NULL', [$c2]), 'reports resolved');
    // Validation and deleted authors.
    [, $err] = comment_add($ru, 'post', $post, str_repeat('x', COMMENT_MAX + 1));
    ok($err !== null);
    [, $err] = comment_add($ru, 'post', fixture_post_id(['allow_comments' => '0']), 'hi');
    ok($err !== null, 'comments switched off');
    account_delete($replier['user_id']);
    same('Former member', comment_author_name(q_one('SELECT * FROM comments WHERE id = ?', [$r1])));
    engagement_forget();
    same(1, engagement_counts('post', $post)['comments'], 'cards count approved comments only (c1 is back in the queue, r2 pending, c2 rejected)');
    setting_set('comments.moderation', 'pre');
    mailer(null, true);
});
