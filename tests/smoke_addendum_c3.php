<?php
declare(strict_types=1);

// Addendum C3 smoke checks through php -S: direct messages without and with
// JavaScript-style JSON requests, Team inbox, reports, blocking, the 24-hour
// rule, attachments, settings, unread badges, digests and data export.
// Included by tests/smoke.php after smoke_addendum_c.php (uses Browser,
// check(), $admin (super admin), $base, login_as(), meta_csrf(), mail_files(), newest_mail_to()).

$dmMember = static function (string $first, string $email, float $hoursOld = 72): int {
    $uid = create_user($email, 'dm-smoke-password', 'member', "$first Smoke", 'active');
    db_insert('members', ['user_id' => $uid, 'first_name' => $first, 'last_name' => 'Smoke', 'membership_type' => 'Standard', 'birth_day' => 2, 'birth_month' => 3,
        'member_number' => 'SMK-26-' . random_int(1000, 9999), 'verified_at' => gmdate('Y-m-d H:i:s', time() - (int) ($hoursOld * 3600)), 'prefs' => json_encode(['newsletters' => false])]);
    return $uid;
};
$aliceId = $dmMember('Alice', 'alice.dm@example.test');
$bobId = $dmMember('Bobby', 'bobby.dm@example.test');
$newId = $dmMember('Newt', 'newt.dm@example.test', 2);
$alice = login_as($base, 'alice.dm@example.test', 'dm-smoke-password');
$bob = login_as($base, 'bobby.dm@example.test', 'dm-smoke-password');
$newt = login_as($base, 'newt.dm@example.test', 'dm-smoke-password');
$json = static fn (Browser $b): array => ['Accept: application/json', 'X-CSRF-Token: ' . meta_csrf($b, '/account/messages')];

// ---- Member to member, without JavaScript ----------------------------------------------
$h = $alice->get('/account/messages');
check('C3: members have Messages in the account menu and an empty list', $h['status'] === 200 && str_contains($h['body'], 'href="/account/messages" aria-current="page"') && str_contains($h['body'], 'No messages yet'));
$h = $alice->get('/account/messages/new?q=Bobby');
check('C3: recipient search (no JS) finds a member by name', str_contains($h['body'], 'value="' . $bobId . '"'));
$number = (string) q_val('SELECT member_number FROM members WHERE user_id = ?', [$bobId]);
check('C3: … and by member number', str_contains($alice->get('/account/messages/new?q=' . rawurlencode($number))['body'], 'value="' . $bobId . '"'));
$r = $alice->post('/account/messages', ['_csrf' => $alice->csrf('/account/messages/new'), 'to[]' => (string) $bobId, '_find' => '1', 'q' => 'Bob', 'body' => 'kept text']);
check('C3: the no-JS "Search" button keeps the form and redirects (PRG)', $r['status'] === 303 && str_contains($r['location'], '/account/messages/new?') && str_contains($alice->get($r['location'])['body'], 'kept text'));
$xss = "Hi Bobby <script>alert(1)</script>\nSee https://example.org/meet";
$r = $alice->post('/account/messages', ['_csrf' => $alice->csrf('/account/messages/new'), 'to[]' => (string) $bobId, 'body' => $xss]);
$convId = preg_match('#/account/messages/(\d+)#', $r['location'], $m) ? (int) $m[1] : 0;
check('C3: sending a first message creates the conversation (303 to the thread)', $r['status'] === 303 && $convId > 0);
$h = $bob->get('/account/messages');
check('C3: the recipient sees it with an unread badge in the list and the menu', str_contains($h['body'], 'Alice Smoke') && str_contains($h['body'], 'data-dm-badge data-dm-src="/account/messages/unread">1<'));
$h = $bob->get('/account/messages/' . $convId);
check('C3: message text is escaped, line breaks kept, links made', $h['status'] === 200 && !str_contains($h['body'], '<script>alert(1)') && str_contains($h['body'], '&lt;script&gt;alert(1)&lt;/script&gt;<br>See <a href="https://example.org/meet" rel="nofollow noopener noreferrer ugc"'));
check('C3: viewing does not mark read (GET changes nothing); the read form does', str_contains($h['body'], 'data-dm-read-form') && (int) json_decode($bob->get('/account/messages/unread')['body'], true)['unread'] === 1);
$r = $bob->post('/account/messages/' . $convId . '/read', ['_csrf' => $bob->csrf('/account/messages/' . $convId)]);
check('C3: Mark as read (no JS) → 303, unread 0', $r['status'] === 303 && (int) json_decode($bob->get('/account/messages/unread')['body'], true)['unread'] === 0);
check('C3: someone else\'s conversation is 404', $newt->get('/account/messages/' . $convId)['status'] === 404 && $newt->get('/account/messages/' . $convId . '/poll?after=0')['status'] === 404);

// ---- With JavaScript (JSON) ------------------------------------------------------------
$last = (int) q_val('SELECT MAX(id) FROM messages WHERE conversation_id = ?', [$convId]);
$r = $bob->post('/account/messages/' . $convId, ['body' => 'Reply from Bobby'], $json($bob));
$d = json_decode($r['body'], true);
check('C3: JSON send returns the presented message', $r['status'] === 200 && ($d['ok'] ?? false) && ($d['message']['mine'] ?? false) && $d['message']['parts'] === [['text', 'Reply from Bobby']]);
$p = json_decode($alice->get('/account/messages/' . $convId . '/poll?after=' . $last . '&since=' . rawurlencode(gmdate('Y-m-d H:i:s')))['body'], true);
check('C3: polling returns only newer messages, with the unread count', count($p['messages'] ?? []) === 1 && $p['messages'][0]['sender'] === 'Bobby Smoke' && $p['unread'] === 1 && $p['last_id'] > $last);
$r = $bob->post('/account/messages/' . $convId, ['body' => str_repeat('x', 4001)], $json($bob));
check('C3: over 4,000 characters → 422 JSON error', $r['status'] === 422 && str_contains((string) json_decode($r['body'], true)['error'], '4,000'));
$r = $bob->post('/account/messages/' . $convId, ['body' => 'no token'], ['Accept: application/json']);
check('C3: JSON send without the CSRF header → 419', $r['status'] === 419);

// ---- Edit, delete ------------------------------------------------------------------------
$bobMsg = (int) $d['message']['id'];
$h = $bob->get('/account/messages/' . $convId . '?act=edit&m=' . $bobMsg);
check('C3: own message: Edit opens an inline form', str_contains($h['body'], 'id="dm-edit-' . $bobMsg . '"'));
$since = gmdate('Y-m-d H:i:s', time() - 1);
$r = $bob->post('/account/messages/' . $convId . '/m/' . $bobMsg . '/edit', ['_csrf' => $bob->csrf('/account/messages/' . $convId), 'body' => 'Reply from Bobby (edited)']);
check('C3: edit within 15 minutes saves', $r['status'] === 303 && q_val('SELECT body FROM messages WHERE id = ?', [$bobMsg]) === 'Reply from Bobby (edited)');
$p = json_decode($alice->get('/account/messages/' . $convId . '/poll?after=' . $bobMsg . '&since=' . rawurlencode($since))['body'], true);
$changed = array_column($p['changed'] ?? [], null, 'id');
check('C3: polling reports edits of earlier messages', ($changed[$bobMsg]['edited'] ?? false) === true && $changed[$bobMsg]['body'] === 'Reply from Bobby (edited)', json_encode($p));
$r = $alice->post('/account/messages/' . $convId . '/m/' . $bobMsg . '/delete', ['_csrf' => $alice->csrf('/account/messages/' . $convId)]);
check('C3: nobody can delete someone else\'s message', q_val('SELECT deleted_at FROM messages WHERE id = ?', [$bobMsg]) === null);
$r = $bob->post('/account/messages/' . $convId . '/m/' . $bobMsg . '/delete', ['_csrf' => $bob->csrf('/account/messages/' . $convId)]);
check('C3: delete own → "Message deleted" for everyone', $r['status'] === 303 && str_contains($alice->get('/account/messages/' . $convId)['body'], 'Message deleted'));

// ---- Attachments -----------------------------------------------------------------------------
$jpgFile = sys_get_temp_dir() . '/dm-' . bin2hex(random_bytes(3)) . '.jpg';
$im = imagecreatetruecolor(500, 400);
imagefilledrectangle($im, 0, 0, 500, 400, imagecolorallocate($im, 40, 120, 200));
imagejpeg($im, $jpgFile);
$r = $alice->post('/account/messages/' . $convId, ['_csrf' => $alice->csrf('/account/messages/' . $convId), 'body' => 'Photo of the hall', 'attachment' => new CURLFile($jpgFile, 'image/jpeg', 'hall.jpg')]);
$mediaId = (int) q_val("SELECT attachment_media_id FROM messages WHERE conversation_id = ? AND body = 'Photo of the hall'", [$convId]);
$mediaRow = q_one('SELECT * FROM media WHERE id = ?', [$mediaId]);
check('C3: a photo attachment is stored as private media in "messages"', $r['status'] === 303 && $mediaRow !== null && $mediaRow['visibility'] === 'private' && $mediaRow['folder'] === 'messages');
$file = '/media/' . $mediaId . '/' . rawurlencode((string) $mediaRow['original_name']);
check('C3: participants can open it; outsiders, visitors and a super admin outside the conversation get 404',
    $bob->get($file)['status'] === 200 && $bob->get('/media/' . $mediaId . '/w/400')['status'] === 200 && $newt->get($file)['status'] === 404
    && (new Browser($base))->get($file)['status'] === 404 && $admin->get($file)['status'] === 404);
check('C3: message attachments are not in the media library', $admin->get('/admin/media/' . $mediaId)['status'] === 404 && !str_contains($admin->get('/admin/media?folder=messages')['body'], 'hall.jpg'));
unlink($jpgFile);

// ---- Reports --------------------------------------------------------------------------------
$aliceMsg = (int) q_val("SELECT id FROM messages WHERE conversation_id = ? AND body = 'Photo of the hall'", [$convId]);
$r = $bob->post('/account/messages/' . $convId . '/m/' . $aliceMsg . '/report', ['_csrf' => $bob->csrf('/account/messages/' . $convId), 'reason' => 'Smoke: not appropriate']);
check('C3: reporting a message → 303 and a report row', $r['status'] === 303 && (int) q_val('SELECT COUNT(*) FROM message_reports WHERE message_id = ?', [$aliceMsg]) === 1);
$h = $admin->get('/admin/messages/reports');
check('C3: the Reports queue lists it with the reason but not the text', str_contains($h['body'], 'Smoke: not appropriate') && !str_contains($h['body'], 'Photo of the hall'));
check('C3: admins cannot open conversations they are not in', $admin->get('/admin/messages/' . $convId)['status'] === 404);
$auditBefore = (int) q_val("SELECT COUNT(*) FROM audit_log WHERE action = 'message_report_view'");
$h = $admin->get('/admin/messages/reports/' . $aliceMsg);
check('C3: reviewing shows the message in context and is audited', $h['status'] === 200 && str_contains($h['body'], 'Photo of the hall') && str_contains($h['body'], 'is-reported')
    && (int) q_val("SELECT COUNT(*) FROM audit_log WHERE action = 'message_report_view'") === $auditBefore + 1 && $admin->get($file)['status'] === 200);
check('C3: an unreported message has no review page', $admin->get('/admin/messages/reports/' . $bobMsg)['status'] === 404);
$r = $admin->post('/admin/messages/reports/' . $aliceMsg, ['_csrf' => meta_csrf($admin), 'action' => 'remove']);
check('C3: Remove → "Message removed by a moderator", attachment gone, audited', $r['status'] === 303 && str_contains($bob->get('/account/messages/' . $convId)['body'], 'Message removed by a moderator')
    && q_val('SELECT id FROM media WHERE id = ?', [$mediaId]) === null && q_val("SELECT action FROM audit_log WHERE item_type = 'message' AND item_id = ? ORDER BY id DESC LIMIT 1", [$aliceMsg]) === 'message_remove');

// ---- Team inbox --------------------------------------------------------------------------------
$r = $alice->post('/account/messages', ['_csrf' => $alice->csrf('/account/messages/new'), 'team' => '1', 'body' => 'Smoke question for the committee']);
$teamId = preg_match('#/account/messages/(\d+)#', $r['location'], $m) ? (int) $m[1] : 0;
check('C3: "Message the team" creates a Team inbox thread', $teamId > 0 && q_val('SELECT kind FROM conversations WHERE id = ?', [$teamId]) === 'team');
$h = $admin->get('/admin/messages/team?show=unread');
check('C3: the Team inbox lists it as unread; the top bar badge counts it', str_contains($h['body'], 'Alice Smoke') && preg_match('#data-dm-badge data-dm-src="/admin/messages/unread">([1-9]\d*)<#', $h['body']) === 1);
$r = $admin->post('/admin/messages/team/' . $teamId, ['_csrf' => meta_csrf($admin), 'body' => 'Smoke: the team answers']);
check('C3: an admin answers as the team (audited), the member sees it with "Admin"', $r['status'] === 303 && str_contains($alice->get('/account/messages/' . $teamId)['body'], 'Smoke: the team answers')
    && str_contains($alice->get('/account/messages/' . $teamId)['body'], 'dm-role">Admin') && q_val("SELECT action FROM audit_log WHERE item_type = 'conversation' AND item_id = ? ORDER BY id DESC LIMIT 1", [$teamId]) === 'team_reply');
check('C3: answering marks the thread read for the team', !str_contains($admin->get('/admin/messages/team?show=unread')['body'], 'Alice Smoke'));
$plain = login_as($base, 'matrix-content@example.test', 'matrix-password-1');
check('C3: admins without the messages permission: own messages yes, Team inbox and reports 403', $plain->get('/admin/messages')['status'] === 200
    && $plain->get('/admin/messages/team')['status'] === 403 && $plain->get('/admin/messages/team/' . $teamId)['status'] === 403 && $plain->get('/admin/messages/reports')['status'] === 403);

// ---- Admin messages, member page link ---------------------------------------------------------
$aliceMember = (int) q_val('SELECT id FROM members WHERE user_id = ?', [$aliceId]);
check('C3: the admin member page offers "Send a message"', str_contains($admin->get('/admin/members/' . $aliceMember)['body'], 'href="/admin/messages/new?to=' . $aliceId . '"'));
check('C3: … which preselects the member', str_contains($admin->get('/admin/messages/new?to=' . $aliceId)['body'], 'name="to[]" value="' . $aliceId . '" checked'));
$r = $admin->post('/admin/messages', ['_csrf' => meta_csrf($admin), 'to[]' => (string) $aliceId, 'body' => 'Hello from the admin']);
check('C3: admins message a member (audited as message_start)', $r['status'] === 303 && str_starts_with($r['location'], '/admin/messages/') && q_val("SELECT action FROM audit_log WHERE action = 'message_start' ORDER BY id DESC LIMIT 1") === 'message_start');

// ---- New members, setting, blocking ----------------------------------------------------------------
$r = $newt->post('/account/messages', ['_csrf' => $newt->csrf('/account/messages/new'), 'to[]' => (string) $aliceId, 'body' => 'hi']);
check('C3: a member verified < 24 h ago cannot message members', $r['status'] === 303 && str_contains($newt->get($r['location'])['body'], 'first day'));
check('C3: … and the search shows them nobody', !str_contains($newt->get('/account/messages/new?q=Alice')['body'], 'value="' . $aliceId . '"'));
$r = $admin->post('/admin/settings/messages', ['_csrf' => meta_csrf($admin), 'team_inbox' => '1', 'retention_months' => '']);
check('C3: super admin switches member-to-member off (audited)', $r['status'] === 303 && setting_fresh('messages.member_to_member') === false
    && str_contains((string) q_val("SELECT summary FROM audit_log WHERE item_type = 'settings' ORDER BY id DESC LIMIT 1"), 'member to member off'));
check('C3: then members find no members', !str_contains($bob->get('/account/messages/new?q=Alice')['body'], 'value="' . $aliceId . '"'));
$r = $admin->post('/admin/settings/messages', ['_csrf' => meta_csrf($admin), 'member_to_member' => '1', 'team_inbox' => '1', 'retention_months' => '999']);
check('C3: retention is validated', $r['status'] === 303 && setting_fresh('messages.retention_months') === 0 && str_contains($admin->get('/admin/settings/messages')['body'], 'from 1 to 120'));
$admin->post('/admin/settings/messages', ['_csrf' => meta_csrf($admin), 'member_to_member' => '1', 'team_inbox' => '1', 'retention_months' => '']);
$r = $bob->post('/account/messages/block/' . $aliceId, ['_csrf' => $bob->csrf('/account/messages')]);
check('C3: blocking hides the 1-to-1 from the blocker', $r['status'] === 303 && !str_contains($bob->get('/account/messages')['body'], 'Alice Smoke') && $bob->get('/account/messages/' . $convId)['status'] === 404);
$r = $alice->post('/account/messages/' . $convId, ['body' => 'Still there?'], $json($alice));
check('C3: the blocked member cannot send', $r['status'] === 422 && str_contains((string) json_decode($r['body'], true)['error'], 'cannot message'));
check('C3: Blocked people lists and unblocks', str_contains($bob->get('/account/messages/blocked')['body'], 'Alice Smoke')
    && $bob->post('/account/messages/unblock/' . $aliceId, ['_csrf' => $bob->csrf('/account/messages/blocked')])['status'] === 303 && $bob->get('/account/messages/' . $convId)['status'] === 200);
check('C3: admins cannot be blocked', $alice->post('/account/messages/block/' . (int) q_val("SELECT id FROM users WHERE email = 'sam@example.test'"), ['_csrf' => $alice->csrf('/account/messages')])['status'] === 303
    && (int) q_val('SELECT COUNT(*) FROM message_blocks WHERE blocker_id = ?', [$aliceId]) === 0);

// ---- Groups ---------------------------------------------------------------------------------------
$r = $alice->post('/account/messages', ['_csrf' => $alice->csrf('/account/messages/new'), 'to[0]' => (string) $bobId, 'to[1]' => (string) q_val("SELECT id FROM users WHERE email = 'member@example.test'"), 'body' => 'Group hello', 'title' => 'Smoke picnic']);
$groupId = preg_match('#/account/messages/(\d+)#', $r['location'], $m) ? (int) $m[1] : 0;
check('C3: a named group conversation', $groupId > 0 && q_val('SELECT kind FROM conversations WHERE id = ?', [$groupId]) === 'group' && str_contains($bob->get('/account/messages/' . $groupId)['body'], 'Smoke picnic'));
$r = $bob->post('/account/messages/' . $groupId . '/leave', ['_csrf' => $bob->csrf('/account/messages/' . $groupId)]);
check('C3: leaving a group → the others see a note', $r['status'] === 303 && str_contains($alice->get('/account/messages/' . $groupId)['body'], 'Bobby Smoke left the conversation.') && $bob->get('/account/messages/' . $groupId)['status'] === 404);

// ---- Email digest and preference ----------------------------------------------------------------
q('UPDATE conversation_participants SET notified_message_id = 999999999 WHERE user_id <> ?', [$bobId]);
$r = $alice->post('/account/messages/' . $convId, ['_csrf' => $alice->csrf('/account/messages/' . $convId), 'body' => 'Digest me']);
q("UPDATE messages SET created_at = ? WHERE conversation_id = ? AND body = 'Digest me'", [gmdate('Y-m-d H:i:s', time() - 16 * 60), $convId]);
$before = count(mail_files());
$queued = msg_digest_run();
mail_queue_run(time(), 100);
$mail = newest_mail_to('bobby.dm@example.test', $before);
check('C3: an unread message emails one digest after 15 minutes (queue + cron)', $queued >= 1 && $mail !== null && str_contains($mail, 'unread message') && str_contains($mail, '/account/messages/' . $convId));
$r = $bob->post('/account/preferences', ['_csrf' => $bob->csrf('/account/preferences'), 'newsletters' => '0']);
check('C3: "Message notifications" in Email preferences switches them off', $r['status'] === 303 && (int) q_val('SELECT message_emails FROM users WHERE id = ?', [$bobId]) === 0
    && str_contains($bob->get('/account/preferences')['body'], 'name="message_emails"'));

// ---- Data export -----------------------------------------------------------------------------
$export = json_decode($alice->get('/account/privacy/export')['body'], true);
check('C3: the data export includes the messages the member sent', in_array('Digest me', array_column($export['messages'] ?? [], 'text'), true) && !in_array('Smoke: the team answers', array_column($export['messages'] ?? [], 'text'), true));

// ---- Admin overview and help -----------------------------------------------------------------------
$h = $admin->get('/admin');
check('C3: overview has Team inbox and reported messages cards; sidebar has the Messages group', str_contains($h['body'], 'Team inbox (unread)') && str_contains($h['body'], 'Reported messages') && str_contains($h['body'], 'data-group="messages"'));
check('C3: the Messages screen has a contextual Help link', str_contains($admin->get('/admin/messages/team')['body'], '/admin/help/manager-guide/messages#team-inbox'));

// ---- Security review fixes ----------------------------------------------------------------------
$carlaId = $dmMember('Carla', 'carla.dm@example.test');
$carla = login_as($base, 'carla.dm@example.test', 'dm-smoke-password');
$r = json_decode($carla->get('/account/messages/recipients?q=Al')['body'], true);
check('C3 review: two letters find nobody (enumeration)', ($r['results'] ?? null) === []);
check('C3 review: three letters do', in_array($aliceId, array_column(json_decode($carla->get('/account/messages/recipients?q=Ali')['body'], true)['results'] ?? [], 'id'), true));
check('C3 review: Email preferences offer "Others can find me in messages"', str_contains($bob->get('/account/preferences')['body'], 'name="message_findable"'));
$bob->post('/account/preferences', ['_csrf' => $bob->csrf('/account/preferences'), 'message_emails' => '1']);
check('C3 review: opted out: members cannot find Bobby or prefill him; admins and his contacts still can',
    (int) q_val('SELECT message_findable FROM users WHERE id = ?', [$bobId]) === 0
    && !str_contains($carla->get('/account/messages/new?q=Bobby')['body'], 'value="' . $bobId . '"')
    && !str_contains($carla->get('/account/messages/new?to=' . $bobId)['body'], 'Bobby Smoke')
    && str_contains($alice->get('/account/messages/new?to=' . $bobId)['body'], 'name="to[]" value="' . $bobId . '" checked')
    && str_contains($admin->get('/admin/messages/new?q=Bobby')['body'], 'value="' . $bobId . '"'));
$r = $carla->post('/account/messages', ['_csrf' => $carla->csrf('/account/messages/new'), 'to[]' => (string) $bobId, 'body' => 'guessed id']);
check('C3 review: posting to an opted-out id gives the generic answer', str_contains($carla->get($r['location'])['body'], MSG_NOT_AVAILABLE));
for ($i = 0; $i < MSG_SEARCHES_PER_WINDOW; $i++) {
    rate_hit('msgsearch:' . $carlaId);
}
check('C3 review: the recipient search is rate limited (429)', $carla->get('/account/messages/recipients?q=Alice')['status'] === 429);
$admin->get('/admin/messages/team/' . $teamId);
check('C3 review: opening a Team inbox thread is audited', q_val("SELECT action FROM audit_log WHERE item_type = 'conversation' AND item_id = ? ORDER BY id DESC LIMIT 1", [$teamId]) === 'team_view');
check('C3 review: the generic digest template exists and is editable', $admin->get('/admin/email-templates/message_digest_new')['status'] === 200);
