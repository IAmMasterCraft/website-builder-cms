<?php
declare(strict_types=1);

// Phases 5-6 smoke checks, included by tests/smoke.php (uses its Browser,
// check(), status_is(), $admin, $limited, $base and the open DB connection).

/** POST JSON-style (Accept: application/json + CSRF header) and decode. */
function post_json(Browser $b, string $page, string $action, array $fields): array
{
    $token = $b->csrf($page);
    $r = $b->post($action, $fields + ['_csrf' => $token], ['Accept: application/json', 'X-CSRF-Token: ' . $token]);
    return ['status' => $r['status'], 'json' => json_decode($r['body'], true), 'raw' => $r['body']];
}

function mail_files(): array
{
    return glob(BASE . '/storage/mail/*.eml') ?: [];
}

function newest_mail_to(string $email, int $after): ?string
{
    $files = array_slice(mail_files(), $after);
    foreach (array_reverse($files) as $f) {
        $raw = (string) file_get_contents($f);
        if (stripos($raw, 'To: ') !== false && str_contains(strtolower($raw), strtolower($email))) {
            return quoted_printable_decode($raw);
        }
    }
    return null;
}

// ---- Admin screens render ---------------------------------------------------------
foreach (['/admin/campaigns', '/admin/notices', '/admin/notices/new', '/admin/inbox', '/admin/email-templates', '/admin/email-templates/welcome_member',
    '/admin/email-log', '/admin/jobs', '/admin/jobs?tab=published', '/admin/jobs/new', '/admin/comments', '/admin/radio', '/admin/radio/playlists',
    '/admin/radio/schedule', '/admin/settings/email'] as $path) {
    status_is($admin, $path, 200);
}
$r = $admin->get('/admin');
check('overview links new campaign and radio upload, shows birthdays panel', str_contains($r['body'], '/admin/campaigns') && str_contains($r['body'], 'Upload to radio') && str_contains($r['body'], 'Next 7 days'));
$r = $admin->get('/admin/email-templates/welcome_member/preview');
check('email template preview is HTML with a style-only CSP', $r['status'] === 200 && str_contains($r['headers'], "style-src 'unsafe-inline'") && !str_contains($r['headers'], "script-src 'self'") && str_contains($r['body'], 'ORG-26-0042'));
foreach (['/admin/campaigns', '/admin/inbox', '/admin/jobs', '/admin/comments', '/admin/radio', '/admin/radio/schedule', '/admin/settings/email', '/admin/email-templates'] as $path) {
    status_is($limited, $path, 403);
}

// ---- Email settings ---------------------------------------------------------------------
$r = $admin->submit('/admin/settings/email', '/admin/settings/email', ['driver' => 'smtp', 'from_email' => 'news@example.test', 'from_name' => 'Smoke Society', 'host' => '', 'port' => '587', 'encryption' => 'tls', 'username' => '', 'per_minute' => '50', 'birthday_time' => '07:00']);
check('SMTP without a server name is refused', $r['status'] === 422 && str_contains($r['body'], 'SMTP server name'));
$r = $admin->submit('/admin/settings/email', '/admin/settings/email', ['driver' => 'file', 'from_email' => 'news@example.test', 'from_name' => 'Smoke Society', 'host' => 'smtp.example.test', 'port' => '587', 'encryption' => 'tls', 'username' => 'mailer', 'password' => 'smtp-secret-pass', 'per_minute' => '50', 'birthday_time' => '06:30']);
check('email settings saved (password encrypted at rest)', $r['status'] === 303 && setting_fresh('mail.per_minute') === 50 && str_starts_with((string) setting_fresh('mail.smtp')['password'], 'sb2:')
    && !str_contains((string) q_val("SELECT value FROM settings WHERE `key` = 'mail.smtp'"), 'smtp-secret-pass'));
$before = count(mail_files());
$r = $admin->submit('/admin/settings/email', '/admin/settings/email/test', ['to' => 'testbox@example.test']);
check('“send test email” delivers through the configured transport', $r['status'] === 303 && newest_mail_to('testbox@example.test', $before) !== null);

// ---- Templates ---------------------------------------------------------------------------
$r = $admin->submit('/admin/email-templates/birthday_greeting', '/admin/email-templates/birthday_greeting', ['subject' => 'Hi {nickname}', 'body' => 'x', 'button_label' => '']);
check('template with an unknown placeholder is refused', $r['status'] === 303 && str_contains($admin->get('/admin/email-templates/birthday_greeting')['body'], 'Unknown placeholder'));
$r = $admin->submit('/admin/email-templates/birthday_greeting', '/admin/email-templates/birthday_greeting', ['subject' => 'Happy birthday from us, {first_name}!', 'body' => "Dear {first_name},\n\nHave a lovely day.", 'button_label' => 'Say hello']);
check('template edit saved and used', $r['status'] === 303 && email_template_render('birthday_greeting', ['first_name' => 'Bo'])['subject'] === 'Happy birthday from us, Bo!');
$admin->submit('/admin/email-templates/birthday_greeting', '/admin/email-templates/birthday_greeting/reset', []);
check('template reset to default', q_val("SELECT COUNT(*) FROM email_templates WHERE template_key = 'birthday_greeting'") === 0);

// ---- Members for audiences --------------------------------------------------------------
$mkMember = static function (string $email, string $first, string $region, bool $news): int {
    $uid = create_user($email, 'member-password-123', 'member', $first . ' Smoke', 'active');
    db_insert('members', ['user_id' => $uid, 'first_name' => $first, 'last_name' => 'Smoke', 'region' => $region, 'membership_type' => 'Standard',
        'birth_day' => 1, 'birth_month' => 1, 'prefs' => json_encode(['newsletters' => $news, 'birthday_greeting' => true, 'celebrants' => false])]);
    return $uid;
};
$walesA = $mkMember('wales-a@example.test', 'Wyn', 'Wales', true);
$walesB = $mkMember('wales-b@example.test', 'Bryn', 'Wales', true);
$walesOff = $mkMember('wales-off@example.test', 'Nia', 'Wales', false);

// ---- Campaign: editor, autosave, preview, test, send, queue, log, unsubscribe -----------
$r = $admin->submit('/admin/campaigns', '/admin/campaigns', []);
$campId = preg_match('#^/admin/campaigns/(\d+)$#', $r['location'], $m) ? (int) $m[1] : 0;
check('new campaign starts from starter blocks', $r['status'] === 303 && $campId > 0 && str_contains($admin->get('/admin/campaigns/' . $campId)['body'], 'Latest posts'));
$editor = '/admin/campaigns/' . $campId;
$blocks = [
    ['type' => 'heading', 'text' => 'Wales picnic', 'size' => 'large'],
    ['type' => 'text', 'html' => '<p>Hello {first_name}, join us!</p>'],
    ['type' => 'button', 'label' => 'See events', 'url' => '/events'],
];
$fields = ['subject' => 'Picnic news for {first_name}', 'preheader' => 'Sunday in the park', 'audience_type' => 'region', 'audience_region' => 'Wales'];
foreach ($blocks as $i => $b) {
    foreach ($b as $k => $v) {
        $fields["blocks[$i][$k]"] = $v;
    }
}
$walesReach = audience_resolve(['type' => 'region', 'value' => 'Wales', 'field_id' => 0]);
$walesCount = count($walesReach['recipients']);
$j = post_json($admin, $editor, $editor, $fields + ['op' => 'save']);
check('campaign autosave (JSON) reports the audience size', $j['status'] === 200 && ($j['json']['ok'] ?? false) === true && $walesCount >= 2 && ($j['json']['recipients'] ?? -1) === $walesCount && ($j['json']['excluded'] ?? -1) === $walesReach['excluded'] && $walesReach['excluded'] >= 1, $j['raw']);
$r = $admin->submit($editor, $editor, $fields + ['op' => 'add', 'add_type' => 'divider']);
check('adding a block without JavaScript (PRG)', $r['status'] === 303 && str_contains($r['location'], '#block-3') && count(json_decode((string) q_val('SELECT blocks FROM campaigns WHERE id = ?', [$campId]), true)) === 4);
$r = $admin->submit($editor, $editor, $fields + ['op' => 'remove:3']);
check('removing a block', count(json_decode((string) q_val('SELECT blocks FROM campaigns WHERE id = ?', [$campId]), true)) === 3);
$r = $admin->get($editor . '/preview');
check('campaign preview personalises and uses the theme layout', $r['status'] === 200 && str_contains($r['body'], 'Hello Sam, join us!') && str_contains($r['body'], 'Unsubscribe'));
$before = count(mail_files());
$r = $admin->submit($editor, $editor . '/test', ['to' => 'proof@example.test']);
$proof = newest_mail_to('proof@example.test', $before);
check('test send arrives with [Test] subject and an unsubscribe header', $r['status'] === 303 && $proof !== null && str_contains($proof, '[Test] Picnic news for Sam') && str_contains($proof, 'List-Unsubscribe:'));
$r = $admin->submit($editor, $editor . '/send', ['mode' => 'schedule', 'send_at' => '2001-01-01T10:00']);
check('scheduling in the past is refused', q_val('SELECT status FROM campaigns WHERE id = ?', [$campId]) === 'draft');
$r = $admin->submit($editor, $editor . '/send', ['mode' => 'now']);
check('send now queues one row per recipient', $r['status'] === 303 && str_ends_with($r['location'], '/log') && (int) q_val('SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ?', [$campId]) === $walesCount);
$j = post_json($admin, $editor . '/log', $editor, $fields + ['op' => 'save']);
check('a sent campaign can no longer be edited (409)', $j['status'] === 409);
$before = count(mail_files());
setting_set('mail.per_minute', 1);
$s1 = mail_queue_run();
check('cron batch respects the per-minute limit', $s1['sent'] === 1 && (int) q_val("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND status = 'queued'", [$campId]) === $walesCount - 1);
setting_set('mail.per_minute', 50);
$s2 = mail_queue_run();
check('next batch finishes the campaign', $s2['sent'] === $walesCount - 1 && q_val('SELECT status FROM campaigns WHERE id = ?', [$campId]) === 'sent');
$got = newest_mail_to('wales-a@example.test', $before);
check('recipient email is personalised with a signed unsubscribe link', $got !== null && str_contains($got, 'Hello Wyn, join us!') && str_contains($got, '/unsubscribe/'));
check('opted-out member did not receive it', newest_mail_to('wales-off@example.test', $before) === null);
$r = $admin->get($editor . '/log');
check('delivery log lists delivered recipients', str_contains($r['body'], 'wales-b@example.test') && str_contains($r['body'], 'delivered'));
$token = unsubscribe_token('wales-a@example.test');
$anon = new Browser($base);
status_is($anon, '/unsubscribe/' . $token, 200, 'Yes, unsubscribe me');
check('opening the link alone does not unsubscribe (scanners)', q_val("SELECT 1 FROM email_suppressions WHERE email = 'wales-a@example.test'") === null);
$r = $anon->submit('/unsubscribe/' . $token, '/unsubscribe/' . $token, []);
check('confirming unsubscribes and turns newsletters off', $r['status'] === 200 && str_contains($r['body'], 'You are unsubscribed') && q_val("SELECT 1 FROM email_suppressions WHERE email = 'wales-a@example.test'") !== null
    && member_pref(member_for_user($walesA), 'newsletters') === false);
$r = (new Browser($base))->post('/unsubscribe/' . unsubscribe_token('wales-b@example.test'), ['List-Unsubscribe' => 'One-Click']);
check('RFC 8058 one-click unsubscribe works without a session', $r['status'] === 200 && trim($r['body']) === 'Unsubscribed');
status_is($anon, '/unsubscribe/' . substr($token, 0, -1) . (str_ends_with($token, '0') ? '1' : '0'), 404);

// ---- Notices -------------------------------------------------------------------------------
$noticeMember = new Browser($base);
$noticeMember->submit('/login', '/login', ['email' => 'wales-off@example.test', 'password' => 'member-password-123']);
$before = (int) q_val("SELECT COUNT(*) FROM email_queue WHERE kind = 'notice'");
$noticeReach = count(audience_resolve(['type' => 'region', 'value' => 'Wales', 'field_id' => 0])['recipients']);
$r = $admin->submit('/admin/notices/new', '/admin/notices', ['title' => 'Wales meet-up moved', 'body' => '<p>Now on <strong>Saturday</strong>.</p><script>x</script>', 'audience_type' => 'region', 'audience_region' => 'Wales', 'email_copy' => '1', 'action' => 'publish']);
$noticeId = (int) q_val("SELECT id FROM notices WHERE title = 'Wales meet-up moved'");
check('notice published; email copies queued only for opted-in members', $r['status'] === 303 && $noticeId > 0 && (int) q_val("SELECT COUNT(*) FROM email_queue WHERE kind = 'notice'") - $before === $noticeReach && q_val("SELECT 1 FROM email_queue WHERE kind = 'notice' AND to_email IN ('wales-off@example.test','wales-a@example.test')") === null
    && q_val('SELECT body FROM notices WHERE id = ?', [$noticeId]) !== null && !str_contains((string) q_val('SELECT body FROM notices WHERE id = ?', [$noticeId]), '<script'));
$r = $noticeMember->get('/account');
check('member dashboard shows the unread notice', str_contains($r['body'], 'Wales meet-up moved') && str_contains($r['body'], 'count-pill'));
$r = $noticeMember->get('/account/notices');
check('notices page renders the sanitised notice', str_contains($r['body'], '<strong>Saturday</strong>') && str_contains($r['body'], 'Mark as read'));
$r = $noticeMember->submit('/account/notices', '/account/notices/read', ['id' => (string) $noticeId]);
check('member marks the notice read', $r['status'] === 303 && (int) q_val('SELECT COUNT(*) FROM notice_reads WHERE notice_id = ? AND user_id = ?', [$noticeId, $walesOff]) === 1);
check('admin list shows the notice', str_contains($admin->get('/admin/notices')['body'], 'Wales meet-up moved'));

// ---- Inbox and reply ------------------------------------------------------------------------
$msgId = (int) q_val("SELECT id FROM contact_messages WHERE email = 'cora@example.test'");
$r = $admin->get('/admin/inbox/' . $msgId);
check('inbox message page', $r['status'] === 200 && str_contains($r['body'], 'cora@example.test') && str_contains($r['body'], 'Reply by email'));
$r = $admin->post('/admin/inbox/' . $msgId . '/read', ['_csrf' => $admin->csrf('/admin/inbox/' . $msgId)], ['Accept: application/json']);
check('mark read (JSON)', $r['status'] === 200 && q_val('SELECT read_at FROM contact_messages WHERE id = ?', [$msgId]) !== null);
$before = count(mail_files());
$r = $admin->submit('/admin/inbox/' . $msgId, '/admin/inbox/' . $msgId . '/reply', ['subject' => 'Re: Hello', 'body' => "Thanks Cora, see you on Friday."]);
$reply = newest_mail_to('cora@example.test', $before);
check('reply emailed to the sender and recorded', $r['status'] === 303 && $reply !== null && str_contains($reply, 'Thanks Cora') && q_val('SELECT replied_at FROM contact_messages WHERE id = ?', [$msgId]) !== null
    && (int) q_val('SELECT COUNT(*) FROM contact_replies WHERE message_id = ?', [$msgId]) === 1);

// ---- Jobs ----------------------------------------------------------------------------------
$jobMember = new Browser($base);
$jobMember->submit('/login', '/login', ['email' => 'wales-b@example.test', 'password' => 'member-password-123']);
$guest = new Browser($base);
$r = $guest->get('/jobs');
check('job board is members only by default (visitor sent to log in)', $r['status'] === 303 && str_starts_with($r['location'], '/login'));
status_is($jobMember, '/jobs', 200, 'Volunteer coordinator');
$r = $jobMember->submit('/account/jobs/new', '/account/jobs', ['title' => 'Bilingual tutor', 'organisation' => 'Valley Learning', 'location' => 'Cardiff', 'job_type' => 'part_time', 'description' => 'Help pupils with homework.', 'apply_email' => 'tutors@example.test']);
$jobId = (int) q_val("SELECT id FROM jobs WHERE title = 'Bilingual tutor'");
check('member job goes to approval', $r['status'] === 303 && q_val('SELECT status FROM jobs WHERE id = ?', [$jobId]) === 'pending' && !str_contains($jobMember->get('/jobs')['body'], 'Bilingual tutor'));
status_is($jobMember, '/account/jobs', 200, 'Awaiting approval');
$r = $admin->submit('/admin/jobs', '/admin/jobs/' . $jobId . '/approve', []);
check('admin approves; the job is on the board with an expiry', $r['status'] === 303 && q_val('SELECT status FROM jobs WHERE id = ?', [$jobId]) === 'published' && q_val('SELECT expires_at FROM jobs WHERE id = ?', [$jobId]) !== null
    && str_contains($jobMember->get('/jobs')['body'], 'Bilingual tutor'));
$reporter = new Browser($base);
$reporter->submit('/login', '/login', ['email' => 'wales-a@example.test', 'password' => 'member-password-123']);
$r = $reporter->submit('/jobs/' . $jobId, '/jobs/' . $jobId . '/report', ['reason' => 'Out of date']);
check('member reports a job; it shows in the reported queue', $r['status'] === 303 && str_contains($admin->get('/admin/jobs?tab=reported')['body'], 'Out of date'));
q('UPDATE jobs SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = ?', [$jobId]);
check('expired jobs leave the board', !str_contains($jobMember->get('/jobs')['body'], 'Bilingual tutor'));
setting_set('jobs', ['public' => true]);
status_is(new Browser($base), '/jobs', 200, 'Job board');
setting_set('jobs', []);

// ---- Likes and comments ----------------------------------------------------------------------
$postRow = q_one("SELECT p.id, p.slug, c.base FROM posts p JOIN categories c ON c.id = p.category_id WHERE p.status = 'published' AND p.visibility = 'public' AND p.allow_comments = 1 AND p.allow_likes = 1 ORDER BY p.publish_at DESC LIMIT 1");
$postPath = '/' . $postRow['base'] . '/' . $postRow['slug'];
$r = (new Browser($base))->get($postPath);
check('visitors see counts and a log-in prompt, not the comments', str_contains($r['body'], 'Comments are for members') && str_contains($r['body'], 'Log in to like'));
$j = post_json($jobMember, $postPath, '/like', ['item_type' => 'post', 'item_id' => (string) $postRow['id'], 'return' => $postPath]);
check('member likes a post (JSON)', $j['status'] === 200 && ($j['json']['liked'] ?? null) === true && ($j['json']['count'] ?? 0) === 1, $j['raw']);
$j = post_json($jobMember, $postPath, '/like', ['item_type' => 'post', 'item_id' => (string) $postRow['id'], 'return' => $postPath]);
check('second like toggles it off', ($j['json']['liked'] ?? null) === false && ($j['json']['count'] ?? -1) === 0);
$r = (new Browser($base))->post('/like', ['item_type' => 'post', 'item_id' => (string) $postRow['id']]);
check('like without a CSRF token is rejected (419)', $r['status'] === 419);
$r = $jobMember->submit($postPath, '/comments', ['item_type' => 'post', 'item_id' => (string) $postRow['id'], 'body' => 'Lovely <b>news</b>!', 'return' => $postPath]);
$commentId = (int) q_val('SELECT MAX(id) FROM comments');
check('member comment is held for moderation (D11)', $r['status'] === 303 && q_val('SELECT status FROM comments WHERE id = ?', [$commentId]) === 'pending');
check('author sees the pending comment; others do not', str_contains($jobMember->get($postPath)['body'], 'Awaiting approval') && !str_contains($reporter->get($postPath)['body'], 'Lovely &lt;b&gt;news'));
$r = $admin->get('/admin/comments');
check('moderation queue lists it', str_contains($r['body'], 'Lovely &lt;b&gt;news&lt;/b&gt;!'));
$admin->submit('/admin/comments', '/admin/comments/' . $commentId, ['action' => 'approve']);
$page = $reporter->get($postPath);
check('approved comment is shown escaped to members, with reply and report', str_contains($page['body'], 'Lovely &lt;b&gt;news&lt;/b&gt;!') && str_contains($page['body'], 'Reply') && str_contains($page['body'], 'Report'));
$before = count(mail_files());
$reporter->submit($postPath, '/comments', ['item_type' => 'post', 'item_id' => (string) $postRow['id'], 'parent_id' => (string) $commentId, 'body' => 'Agreed!', 'return' => $postPath]);
$replyId = (int) q_val('SELECT MAX(id) FROM comments');
$admin->submit('/admin/comments', '/admin/comments/' . $replyId, ['action' => 'approve']);
check('reply approved; the original author gets the comment-reply email', newest_mail_to('wales-b@example.test', $before) !== null && str_contains((string) newest_mail_to('wales-b@example.test', $before), 'replied to your comment'));
$r = $jobMember->submit($postPath, '/comments/' . $commentId . '/edit', ['body' => 'Lovely news (edited)', 'return' => $postPath]);
check('author edits within 15 minutes', q_val('SELECT body FROM comments WHERE id = ?', [$commentId]) === 'Lovely news (edited)');
$r = $reporter->submit($postPath, '/comments/' . $commentId . '/edit', ['body' => 'hijack', 'return' => $postPath]);
check('others cannot edit', q_val('SELECT body FROM comments WHERE id = ?', [$commentId]) === 'Lovely news (edited)');
$r = (new Browser($base))->get('/' . $postRow['base']);
check('post cards show like and comment counts', str_contains($r['body'], 'card-counts'));

// ---- Radio ---------------------------------------------------------------------------------
setting_set('header.listen_live', true);
$home = (new Browser($base))->get('/');
check('demo station is on: "Listen live" in the header and the mini player on the home page', str_contains($home['body'], 'Listen live') && str_contains($home['body'], 'data-radio-player'));
$r = (new Browser($base))->get('/radio');
check('/radio has the live player and the on-demand library', $r['status'] === 200 && str_contains($r['body'], 'data-radio-player') && str_contains($r['body'], 'Harbour Morning') && str_contains($r['body'], '<audio controls'));
status_is(new Browser($base), '/radio?category=news', 200, 'Community notices');
$marketId = (int) q_val("SELECT id FROM radio_media WHERE title = 'Market Day'");
$calm = (new Browser($base))->get('/radio?q=calm')['body'];
check('library search filters', !str_contains($calm, 'id="programme-' . $marketId . '"') && str_contains($calm, 'Evening Calm'));
$r = (new Browser($base))->get('/api/radio/now-playing');
$np = json_decode($r['body'], true);
check('now-playing JSON has the exact §6.2 shape and 1 s caching', $r['status'] === 200 && is_array($np) && array_keys($np) === ['status', 'server_time', 'media', 'offset', 'remaining', 'next', 'next_check_in']
    && in_array($np['status'], ['scheduled', 'loop'], true) && array_keys($np['media']) === ['id', 'title', 'artist', 'description', 'url', 'media_type', 'mime_type', 'duration', 'cover_image']
    && str_contains($r['headers'], 'max-age=1'), $r['body']);
$r = (new Browser($base))->get($np['media']['url'], ['Range: bytes=100-199']);
check('media is served with byte ranges (206)', $r['status'] === 206 && str_contains($r['headers'], 'Content-Range: bytes 100-199/') && strlen($r['body']) === 100);
status_is(new Browser($base), '/api/radio/duration', 404);
// Upload through the admin (duration as measured by the browser).
$tok = $admin->csrf('/admin/radio');
$file = new CURLFile(BASE . '/demo-assets/radio/station-ident.m4a', 'audio/mp4', 'smoke-ident.m4a');
$r = $admin->post('/admin/radio', ['_csrf' => $tok, 'title' => 'Smoke ident', 'artist' => 'QA', 'category_id' => '0', 'on_demand' => '1', 'duration' => '9.102', 'file' => $file]);
$rid = (int) q_val("SELECT id FROM radio_media WHERE title = 'Smoke ident'");
check('admin uploads audio with its measured duration', $r['status'] === 303 && $rid > 0 && (float) q_val('SELECT duration FROM radio_media WHERE id = ?', [$rid]) === 9.102);
$tok = $admin->csrf('/admin/radio');
$r = $admin->post('/admin/radio', ['_csrf' => $tok, 'title' => 'No length', 'category_id' => '0', 'duration' => '', 'file' => new CURLFile(BASE . '/demo-assets/radio/station-ident.m4a', 'audio/mp4', 'nolength.m4a')]);
$nolen = (int) q_val("SELECT id FROM radio_media WHERE title = 'No length'");
check('upload without a measured length is kept as "unknown"', $nolen > 0 && q_val('SELECT duration FROM radio_media WHERE id = ?', [$nolen]) === null);
$r = $admin->submit('/admin/radio/schedule', '/admin/radio/schedule', ['source' => 'media:' . $nolen, 'start' => '2030-01-07T10:00', 'repeat_rule' => 'none']);
check('unknown-length media cannot be scheduled', (int) q_val('SELECT COUNT(*) FROM radio_slots WHERE media_id = ?', [$nolen]) === 0 && str_contains($admin->get('/admin/radio/schedule')['body'], 'unknown duration'));
$r = $admin->submit('/admin/radio/schedule', '/admin/radio/schedule', ['source' => 'media:' . $rid, 'start' => '2030-01-07T10:00', 'repeat_rule' => 'weekly', 'days[0]' => '1']);
check('slot added to the schedule', (int) q_val('SELECT COUNT(*) FROM radio_slots WHERE media_id = ?', [$rid]) === 1);
$r = $admin->submit('/admin/radio/schedule', '/admin/radio/schedule', ['source' => 'media:' . $rid, 'start' => '2030-01-14T10:00', 'repeat_rule' => 'none']);
check('overlapping slot is refused', (int) q_val('SELECT COUNT(*) FROM radio_slots WHERE media_id = ?', [$rid]) === 1 && str_contains($admin->get('/admin/radio/schedule')['body'], 'must not overlap'));
status_is($admin, '/admin/radio/schedule?week=2030-01-07', 200, 'Smoke ident');
$r = $admin->submit('/admin/radio/media/' . $rid, '/admin/radio/media/' . $rid . '/delete', []);
check('media used in the schedule cannot be deleted', radio_media_find($rid) !== null);
$r = $admin->get('/admin/media/' . (int) q_val('SELECT media_id FROM radio_media WHERE id = ?', [$rid]));
check('media library shows radio usage', str_contains($r['body'], 'Radio programme'));
$r = $admin->submit('/admin/radio', '/admin/radio/station', ['enabled' => '0', 'loop_playlist_id' => '0']);
check('station switched off: API offline and no "Listen live"', json_decode((new Browser($base))->get('/api/radio/now-playing')['body'], true)['status'] === 'offline' && !str_contains((new Browser($base))->get('/')['body'], 'Listen live'));
$loopId = (int) q_val("SELECT id FROM radio_playlists WHERE name = 'All-day music'");
$admin->submit('/admin/radio', '/admin/radio/station', ['enabled' => '1', 'loop_playlist_id' => (string) $loopId]);
status_is($jobMember, '/admin/radio', 403);
