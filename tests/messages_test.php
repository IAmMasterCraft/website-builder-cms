<?php
declare(strict_types=1);

// Addendum C3: direct messages (1-to-1, groups, announcements, Team inbox).

use Core\HttpException;
use Core\Redirect;

/** An active member verified $hoursOld hours ago; returns the users row as current_user() would. */
function dm_member(string $first = 'Ada', float $hoursOld = 72, string $status = 'active'): array
{
    static $n = 0;
    $n++;
    $uid = create_user("dm{$n}-" . bin2hex(random_bytes(3)) . '@example.test', 'correct horse battery', 'member', "$first Member", $status);
    db_insert('members', ['user_id' => $uid, 'first_name' => $first, 'last_name' => 'Member' . $n, 'membership_type' => 'Standard', 'birth_day' => 1, 'birth_month' => 1,
        'member_number' => 'DM-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT) . '-' . bin2hex(random_bytes(2)), 'verified_at' => gmdate('Y-m-d H:i:s', time() - (int) ($hoursOld * 3600))]);
    return dm_user($uid);
}

function dm_user(int $id): array
{
    act_as($id);
    $u = current_user();
    ok($u !== null, 'user loads');
    return $u;
}

function dm_staff(array $perms = [], string $role = 'admin'): array
{
    return dm_user(fixture_admin($role, $perms));
}

function dm_reset_settings(): void
{
    foreach (['messages.member_to_member', 'messages.team_inbox', 'messages.team_admins', 'messages.retention_months'] as $k) {
        q('DELETE FROM settings WHERE `key` = ?', [$k]);
    }
    settings_all([]);
}

/** Start a conversation and fail the test on error. */
function dm_start(array $sender, array $to, string $body = 'Hello there', array $opts = []): int
{
    [$id, $err] = msg_start($sender, array_map(static fn (array $u): int => (int) $u['id'], $to), $body, $opts);
    same(null, $err, 'start conversation');
    return (int) $id;
}

test('C3: messages is the eleventh permission key and only opens the Team inbox and reports', function (): void {
    same(12, count(admin_permissions()), 'D2 added payments');
    ok(isset(admin_permissions()['messages']));
    $a = dm_staff(['messages']);
    ok(user_can($a, 'messages') && !user_can($a, 'members'));
    $b = dm_staff(['members']);
    $e = throws(HttpException::class, fn () => require_permission('messages'));
    same(403, $e->status);
    ok(!user_can($b, 'messages'));
});

test('C3: who may message whom (members, admins, new members, setting, blocks, status)', function (): void {
    dm_reset_settings();
    $ada = msg_account((int) dm_member('Ada')['id']);
    $ben = msg_account((int) dm_member('Ben')['id']);
    $newbie = msg_account((int) dm_member('Nia', 3)['id']);
    $admin = msg_account((int) dm_staff()['id']);
    $listed = msg_account((int) dm_staff()['id']);
    same(null, msg_can_message($ada, $ben), 'members can message members');
    same(null, msg_can_message($admin, $ada), 'admins can message members');
    same(null, msg_can_message($admin, $listed), 'admins can message staff');
    ok(msg_can_message($ada, $admin) !== null, 'members cannot pick any admin');
    setting_set('messages.team_admins', [(int) $listed['id']]);
    same(null, msg_can_message($ada, $listed), 'members can message listed team admins');
    ok(str_contains((string) msg_can_message($newbie, $ada), 'first day'), 'new member (< 24 h) cannot message members');
    same(null, msg_can_message($newbie, $listed), 'new member can message the listed admins');
    setting_set('messages.member_to_member', false);
    ok(str_contains((string) msg_can_message($ada, $ben), 'switched off'));
    same(null, msg_can_message($ada, $listed), 'members can still message the team when member-to-member is off');
    setting_set('messages.member_to_member', true);
    msg_block((int) $ben['id'], (int) $ada['id']);
    ok(msg_can_message($ada, $ben) !== null, 'blocked: cannot message the blocker');
    ok(str_contains((string) msg_can_message($ben, $ada), 'Unblock'), 'the blocker must unblock first');
    ok(msg_block((int) $ada['id'], (int) $admin['id']) !== null, 'staff cannot be blocked');
    msg_unblock((int) $ben['id'], (int) $ada['id']);
    q("UPDATE users SET status = 'suspended' WHERE id = ?", [$ben['id']]);
    ok(msg_can_message($ada, msg_account((int) $ben['id'])) !== null, 'suspended people cannot receive messages');
    $unverified = msg_account((int) dm_member('Una', 72, 'unverified')['id']);
    ok(msg_can_message($unverified, $ada) !== null, 'unverified accounts cannot send');
    dm_reset_settings();
});

test('C3: recipient search shows members only people they may message (name, member number)', function (): void {
    dm_reset_settings();
    $ada = dm_member('Searcher');
    $zed = dm_member('Zebedee');
    $hidden = dm_staff();
    $listed = dm_staff();
    setting_set('messages.team_admins', [(int) $listed['id']]);
    $ids = static fn (array $r): array => array_column($r, 'id');
    $found = msg_recipient_search($ada, 'Zebedee');
    same([(int) $zed['id']], $ids($found));
    $number = (string) q_val('SELECT member_number FROM members WHERE user_id = ?', [$zed['id']]);
    same([(int) $zed['id']], $ids(msg_recipient_search($ada, $number)), 'by member number');
    $empty = $ids(msg_recipient_search($ada, ''));
    ok(in_array((int) $listed['id'], $empty, true) && !in_array((int) $hidden['id'], $empty, true), 'no term: the listed admins only');
    ok(!in_array((int) $zed['id'], $empty, true), 'no member directory without a search term');
    ok(!in_array((int) $hidden['id'], $ids(msg_recipient_search($ada, (string) $hidden['display_name'])), true), 'unlisted admins never appear for members');
    ok(in_array((int) $hidden['id'], $ids(msg_recipient_search($listed, (string) $hidden['display_name'])), true), 'admins find other admins');
    msg_block((int) $zed['id'], (int) $ada['id']);
    same([], msg_recipient_search($ada, 'Zebedee'), 'people who blocked you are not listed');
    $newbie = dm_member('Fresh', 2);
    same([], msg_recipient_search($newbie, 'Searcher'), 'new members find no members');
    dm_reset_settings();
});

test('C3: one 1-to-1 conversation per pair; groups need a name and max 20 people', function (): void {
    dm_reset_settings();
    $ada = dm_member('Ada');
    $ben = dm_member('Ben');
    $c1 = dm_start($ada, [$ben], 'First');
    $c2 = dm_start(dm_user((int) $ben['id']), [$ada], 'Reply as a new message');
    same($c1, $c2, 'the same conversation is continued from both sides');
    same('direct', q_val('SELECT kind FROM conversations WHERE id = ?', [$c1]));
    same(2, (int) q_val('SELECT COUNT(*) FROM messages WHERE conversation_id = ?', [$c1]));
    $cara = dm_member('Cara');
    [$id, $err] = msg_start($ada, [(int) $ben['id'], (int) $cara['id']], 'Group hello');
    ok($id === null && str_contains((string) $err, 'name'), 'a group needs a name');
    $g = dm_start($ada, [$ben, $cara], 'Group hello', ['title' => 'Picnic team']);
    same('group', q_val('SELECT kind FROM conversations WHERE id = ?', [$g]));
    same('owner', q_val('SELECT part_role FROM conversation_participants WHERE conversation_id = ? AND user_id = ?', [$g, $ada['id']]));
    $staff = dm_staff();
    $many = [];
    for ($i = 0; $i < 20; $i++) {
        $many[] = (int) dm_member('Many')['id'];
    }
    [$id, $err] = msg_start($staff, $many, 'Too many', ['title' => 'Big']);
    ok($id === null && str_contains((string) $err, '20'), 'more than 20 people is refused for a normal group');
    [$id, $err] = msg_start($staff, array_slice($many, 0, 19), 'Exactly twenty', ['title' => 'Twenty']);
    ok($id !== null && $err === null, '20 including the sender is fine');
    [$id, $err] = msg_start($staff, $many, 'Announcement', ['title' => 'All hands', 'announcement' => true]);
    ok($id !== null, 'admins can make larger announcement groups');
    same('announcement', q_val('SELECT kind FROM conversations WHERE id = ?', [$id]));
    [, $err] = msg_post(dm_user($many[0]), (int) $id, 'Can I reply?');
    ok(str_contains((string) $err, 'Only admins'), 'members cannot post in announcement groups');
    [$mid, $err] = msg_post($staff, (int) $id, 'Admins can');
    ok($mid !== null && $err === null);
    // members cannot create announcements
    [$id, $err] = msg_start($ada, [(int) $ben['id'], (int) $cara['id']], 'x', ['title' => 'Mine', 'announcement' => true]);
    same('group', q_val('SELECT kind FROM conversations WHERE id = ?', [$id]), 'the flag is ignored for members');
});

test('C3: announcement to everyone adds all active members and groups except people who blocked the admin', function (): void {
    $staff = dm_staff();
    $blocker = dm_member('Blocker');
    msg_block((int) $blocker['id'], (int) $staff['id']); // refused: staff cannot be blocked
    same(0, (int) q_val('SELECT COUNT(*) FROM message_blocks WHERE blocker_id = ?', [$blocker['id']]));
    $id = dm_start($staff, [], 'Notice to all', ['title' => 'Everyone', 'announcement' => true, 'everyone' => true]);
    $active = (int) q_val("SELECT COUNT(*) FROM users WHERE role IN ('member','group') AND status = 'active'");
    same($active + 1, (int) q_val('SELECT COUNT(*) FROM conversation_participants WHERE conversation_id = ?', [$id]));
});

test('C3: Team inbox: one thread per member, any messages admin answers, shared read state', function (): void {
    dm_reset_settings();
    $ada = dm_member('Teamy');
    $c = dm_start($ada, [], 'Question for the committee', ['team' => true]);
    same('team', q_val('SELECT kind FROM conversations WHERE id = ?', [$c]));
    same($c, dm_start($ada, [], 'Another question', ['team' => true]), 'the member keeps one team thread');
    [, $err] = msg_start($ada, [(int) dm_member('Other')['id']], 'x', ['team' => true]);
    ok($err !== null, 'team messages go to the team only');
    $plain = dm_staff();
    same(null, msg_team_conversation($c, $plain), 'admins without the permission cannot open it');
    [, $err] = msg_post($plain, $c, 'hi', null, true);
    ok($err !== null);
    $team = dm_staff(['messages']);
    ok(msg_team_conversation($c, $team) !== null);
    ok(msg_team_unread_count() >= 1);
    ok(in_array($c, array_map('intval', array_column(msg_team_conversations('unread'), 'id')), true));
    [$mid, $err] = msg_post($team, $c, 'We will look into it', null, true);
    ok($mid !== null && $err === null, 'answered as the team');
    ok(!in_array($c, array_map('intval', array_column(msg_team_conversations('unread'), 'id')), true), 'a reply marks it read for the team');
    same(1, msg_unread_count((int) $ada['id']), 'the member has the reply unread');
    $dm = msg_conversation_for($c, (int) $ada['id']);
    ok($dm !== null && str_starts_with(msg_conversation_title($dm, msg_participants([$c])[$c], (int) $ada['id']), 'Team inbox'));
    setting_set('messages.team_inbox', false);
    [, $err] = msg_start(dm_member('Late'), [], 'x', ['team' => true]);
    ok(str_contains((string) $err, 'switched off'));
    [, $err] = msg_start($team, [], 'x', ['team' => true]);
    dm_reset_settings();
});

test('C3: bodies: plain text, 4,000 characters, escaped, line breaks and safe links', function (): void {
    $ada = dm_member('Texty');
    $ben = dm_member('Reader');
    [, $err] = msg_start($ada, [(int) $ben['id']], str_repeat('a', MSG_MAX + 1));
    ok(str_contains((string) $err, '4,000'));
    [, $err] = msg_start($ada, [(int) $ben['id']], "  \n ");
    same('Write a message.', $err);
    $html = msg_body_html("<script>alert(1)</script> & see https://example.org/path?a=1&b=2.\nwww.example.com, javascript:alert(1) (https://ex.org/x)");
    not_contains('<script', $html);
    contains('&lt;script&gt;', $html);
    contains('<a href="https://example.org/path?a=1&amp;b=2" rel="nofollow noopener noreferrer ugc" target="_blank">https://example.org/path?a=1&amp;b=2</a>.', $html, 'trailing full stop stays outside');
    contains('<br><a href="https://www.example.com"', $html, 'line break and www. link');
    not_contains('href="javascript', $html);
    contains('target="_blank">https://ex.org/x</a>)', $html, 'unbalanced closing bracket stays outside');
    same([['text', 'a '], ['link', 'http://x.test/(y)'], ['br', ''], ['text', 'b']], msg_body_parts("a http://x.test/(y)\nb"));
    same("a\nb", msg_clean_body("\r\n a\r\nb\x07 "));
});

test('C3: rate limits: 30 messages in 10 minutes and 10 new conversations a day for members', function (): void {
    $ada = dm_member('Rapid');
    $ben = dm_member('Target');
    $c = dm_start($ada, [$ben], 'one');
    for ($i = 2; $i <= MSG_RATE_MAX; $i++) {
        [, $err] = msg_post($ada, $c, "msg $i");
        same(null, $err, "message $i");
    }
    [, $err] = msg_post($ada, $c, 'one too many');
    ok(str_contains((string) $err, 'wait'), '31st message refused');
    $staff = dm_staff();
    $s = dm_start($staff, [$ben], 'staff one');
    for ($i = 0; $i < 35; $i++) {
        msg_post($staff, $s, "s $i");
    }
    same(36, (int) q_val('SELECT COUNT(*) FROM messages WHERE conversation_id = ?', [$s]), 'staff are not limited');
    $cara = dm_member('Starter');
    for ($i = 0; $i < MSG_NEW_PER_DAY; $i++) {
        q("DELETE FROM rate_hits WHERE bucket = ?", ['msg:' . $cara['id']]);
        dm_start($cara, [dm_member('P' . $i)], 'hi');
    }
    q("DELETE FROM rate_hits WHERE bucket = ?", ['msg:' . $cara['id']]);
    [, $err] = msg_start($cara, [(int) dm_member('Eleven')['id']], 'hi');
    ok(str_contains((string) $err, 'conversations today'), '11th new conversation refused');
    [, $err] = msg_start($cara, [(int) q_val('SELECT user_id FROM conversation_participants p JOIN conversations c ON c.id = p.conversation_id WHERE c.created_by = ? AND p.user_id <> ? LIMIT 1', [$cara['id'], $cara['id']])], 'continuing is fine');
    same(null, $err, 'continuing an existing 1-to-1 is not a new conversation');
});

test('C3: unread counts, read marks, mute, and block hides the 1-to-1', function (): void {
    $ada = dm_member('Unread');
    $ben = dm_member('Sender');
    $c = dm_start($ben, [$ada], 'one');
    msg_post($ben, $c, 'two');
    same(2, msg_unread_count((int) $ada['id']));
    same(0, msg_unread_count((int) $ben['id']), 'own messages are read');
    $list = msg_conversations((int) $ada['id']);
    same(2, (int) $list[0]['unread']);
    same('two', $list[0]['preview'], '1-to-1 previews have no name prefix');
    ok(msg_mark_read((int) $ada['id'], $c));
    same(0, msg_unread_count((int) $ada['id']));
    msg_post($ben, $c, 'three');
    msg_set_muted((int) $ada['id'], $c, true);
    same(0, msg_unread_count((int) $ada['id']), 'muted conversations do not count');
    msg_set_muted((int) $ada['id'], $c, false);
    same(1, msg_unread_count((int) $ada['id']));
    same(null, msg_block((int) $ada['id'], (int) $ben['id']));
    same(0, msg_unread_count((int) $ada['id']), 'blocked: not counted');
    same([], msg_conversations((int) $ada['id']), 'blocked: hidden from the list');
    same(null, msg_conversation_for($c, (int) $ada['id']), 'blocked: the thread is not available to the blocker');
    [, $err] = msg_post($ben, $c, 'are you there?');
    ok(str_contains((string) $err, 'cannot message'), 'the blocked person cannot send');
    same(1, count(msg_blocked_list((int) $ada['id'])));
    msg_unblock((int) $ada['id'], (int) $ben['id']);
    same(1, count(msg_conversations((int) $ada['id'])), 'visible again after unblocking');
    same(1, count(msg_conversations((int) $ada['id'], 'three')), 'search by text');
    same(0, count(msg_conversations((int) $ada['id'], 'no-such-words')));
    same(1, count(msg_conversations((int) $ada['id'], 'Sender')), 'search by name');
});

test('C3: edit within 15 minutes, delete shows "message deleted", others cannot', function (): void {
    $ada = dm_member('Editor');
    $ben = dm_member('Other');
    $c = dm_start($ada, [$ben], 'tpyo');
    $mid = (int) q_val('SELECT MAX(id) FROM messages WHERE conversation_id = ?', [$c]);
    same(null, msg_edit($ada, $mid, 'typo'));
    same('typo', q_val('SELECT body FROM messages WHERE id = ?', [$mid]));
    ok(q_val('SELECT edited_at FROM messages WHERE id = ?', [$mid]) !== null);
    ok(msg_edit($ada, $mid, 'late', time() + 16 * 60) !== null, 'after 15 minutes');
    ok(msg_edit($ben, $mid, 'not mine') !== null, 'not the sender');
    ok(!msg_delete_own($ben, $mid), 'others cannot delete');
    ok(msg_delete_own($ada, $mid));
    $row = q_one('SELECT * FROM messages WHERE id = ?', [$mid]);
    same('', $row['body']);
    $p = msg_present($row, $ben, msg_accounts([(int) $ada['id']]), true);
    ok($p['deleted'] && $p['deleted_label'] === 'Message deleted' && !$p['can_report'] && $p['parts'] === []);
    $fresh = msg_present(q_one('SELECT * FROM messages WHERE id = ?', [$mid]) ?? [], $ada, [], true);
    ok(!$fresh['can_edit'] && !$fresh['can_delete']);
});

test('C3: leave, add and remove people; the owner role moves on', function (): void {
    $owner = dm_member('Owner');
    $b = dm_member('Bea');
    $c3 = dm_member('Cal');
    $g = dm_start($owner, [$b, $c3], 'hi all', ['title' => 'Club']);
    ok(msg_add_people($b, $g, [(int) dm_member('X')['id']]) !== null, 'plain members cannot add');
    $d = dm_member('Dee');
    same(null, msg_add_people($owner, $g, [(int) $d['id']]));
    same(1, (int) q_val("SELECT COUNT(*) FROM messages WHERE conversation_id = ? AND note_kind = 'added'", [$g]));
    same(0, msg_unread_count((int) $d['id']), 'newcomers start with nothing unread');
    same(null, msg_remove_person($owner, $g, (int) $c3['id']));
    same(null, msg_conversation_for($g, (int) $c3['id']), 'removed people lose access');
    same(null, msg_leave($owner, $g));
    same('owner', q_val('SELECT part_role FROM conversation_participants WHERE conversation_id = ? AND user_id = ?', [$g, $b['id']]), 'the longest-standing member becomes owner');
    $direct = dm_start($b, [$d], 'x');
    ok(msg_leave($b, $direct) !== null, '1-to-1 conversations cannot be left');
    $staff = dm_staff();
    $ann = dm_start($staff, [$b, $d], 'News', ['title' => 'Ann', 'announcement' => true]);
    same(null, msg_leave($d, $ann), 'members can leave announcements');
});

test('C3: reports: once per person, not your own, context of 5 either side, keep or remove', function (): void {
    $ada = dm_member('Reporter');
    $ben = dm_member('Rude');
    $c = dm_start($ada, [$ben], 'm0');
    $ids = [];
    for ($i = 1; $i <= 12; $i++) {
        [$ids[$i]] = msg_post($i % 2 ? $ben : $ada, $c, "m$i");
        q("DELETE FROM rate_hits WHERE bucket LIKE 'msg:%'");
    }
    $target = (int) $ids[7]; // from Ben
    ok(msg_report($ben, $target, 'mine') !== null, 'cannot report your own message');
    same(null, msg_report($ada, $target, 'Rude words'));
    ok(str_contains((string) msg_report($ada, $target, 'again'), 'already'));
    same(1, msg_reports_open_count());
    $ctx = msg_report_context($target);
    same(11, count($ctx['rows']), 'reported message plus 5 before and 5 after');
    same(range((int) $ids[2], (int) $ids[12]), array_map('intval', array_column($ctx['rows'], 'id')));
    same(null, msg_report_context((int) $ids[3]), 'unreported messages are never shown to admins');
    $list = msg_reports_list();
    ok(!isset($list[0]['body']), 'the queue lists reports without the text');
    $staff = dm_staff(['messages']);
    ok(msg_report_resolve($target, 'remove', (int) $staff['id']));
    $row = q_one('SELECT * FROM messages WHERE id = ?', [$target]);
    same('moderator', $row['deleted_by']);
    same('Message removed by a moderator', msg_present($row, $ada, [], true)['deleted_label']);
    same(0, msg_reports_open_count());
    same('removed', q_val('SELECT outcome FROM message_reports WHERE message_id = ?', [$target]));
    ok(!msg_report_resolve($target, 'keep', (int) $staff['id']), 'already resolved');
    same(null, msg_report($ada, (int) $ids[9], ''));
    ok(msg_report_resolve((int) $ids[9], 'keep', (int) $staff['id']));
    same('m9', q_val('SELECT body FROM messages WHERE id = ?', [$ids[9]]), 'kept messages stay');
});

test('C3: attachments are private: participants only, staff only through a report', function (): void {
    $ada = dm_member('Photo');
    $ben = dm_member('Friend');
    $out = dm_member('Outsider');
    $c = dm_start($ada, [$ben], 'hi');
    $jpg = fixture_jpeg(800, 600);
    [$mid, $err] = msg_post($ada, $c, '', ['name' => 'holiday.jpg', 'tmp_name' => $jpg, 'size' => filesize($jpg), 'error' => UPLOAD_ERR_OK], false, false);
    same(null, $err, 'an image alone is a message');
    $mediaId = (int) q_val('SELECT attachment_media_id FROM messages WHERE id = ?', [$mid]);
    $m = media_find($mediaId);
    same('private', $m['visibility']);
    same('messages', $m['folder']);
    ok(media_can_view($m, dm_user((int) $ben['id'])), 'participant');
    ok(!media_can_view($m, dm_user((int) $out['id'])), 'outsider');
    ok(!media_can_view($m, null), 'visitor');
    ok(!media_can_view($m, dm_staff()), 'a super admin who is not in the conversation');
    $team = dm_staff(['messages']);
    ok(!media_can_view($m, $team), 'messages staff before a report');
    same(null, msg_report($ben, (int) $mid, 'not ok'));
    ok(media_can_view($m, $team), 'messages staff once reported');
    ok(!media_can_view($m, dm_staff(['members'])), 'other staff still not');
    $pdf = fixture_file("%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n", 'pdf');
    [$mid2, $err] = msg_post($ada, $c, 'the minutes', ['name' => 'minutes.pdf', 'tmp_name' => $pdf, 'size' => filesize($pdf), 'error' => UPLOAD_ERR_OK], false, false);
    same(null, $err, 'PDF allowed');
    $doc = fixture_file('plain words', 'txt');
    [, $err] = msg_post($ada, $c, 'x', ['name' => 'notes.txt', 'tmp_name' => $doc, 'size' => filesize($doc), 'error' => UPLOAD_ERR_OK], false, false);
    ok($err !== null, 'other file types refused');
    msg_delete_own($ada, (int) $mid2);
    $pdfMedia = (int) q_val('SELECT COUNT(*) FROM media WHERE folder = ? AND original_name = ?', ['messages', 'minutes.pdf']);
    same(0, $pdfMedia, 'deleting the message deletes its attachment');
});

test('C3: email digest after 15 minutes, once per conversation, respecting preferences, mutes and suppressions', function (): void {
    q("DELETE FROM email_queue WHERE kind = 'message'");
    q('UPDATE conversation_participants SET notified_message_id = 999999999');
    q('UPDATE conversations SET team_notified_message_id = 999999999');
    $ada = dm_member('Digest');
    $ben = dm_member('Writer');
    $c = dm_start($ben, [$ada], 'Are you coming on Saturday?');
    msg_post($ben, $c, 'Second one');
    same(0, msg_digest_run(), 'not before 15 minutes');
    q('UPDATE messages SET created_at = ? WHERE conversation_id = ?', [gmdate('Y-m-d H:i:s', time() - 16 * 60), $c]);
    same(1, msg_digest_run(), 'one email for the conversation');
    same(0, msg_digest_run(), 'not twice');
    $row = q_one("SELECT * FROM email_queue WHERE kind = 'message' ORDER BY id DESC LIMIT 1");
    same(strtolower((string) $ada['email']), strtolower((string) $row['to_email']));
    // Review: a member wrote first and Digest never replied: no sender name, no text.
    same('You have a new message on ' . setting('site.name', 'Gather'), (string) $row['subject']);
    contains('2 unread messages', (string) $row['text_body']);
    not_contains('Writer', (string) $row['text_body'] . (string) $row['html_body']);
    not_contains('Saturday', (string) $row['text_body'] . (string) $row['html_body']);
    not_contains('Second one', (string) $row['text_body'] . (string) $row['html_body']);
    contains('/account/messages/' . $c, (string) $row['text_body']);
    contains('Message notifications', (string) $row['text_body']);
    same(null, msg_digest_block_reason($row), 'still unread: send');
    msg_mark_read((int) $ada['id'], $c);
    ok(str_contains((string) msg_digest_block_reason($row), 'read'), 'read in the meantime: not sent');
    // Run the queue: the row is suppressed rather than sent.
    q("UPDATE email_queue SET status = 'suppressed' WHERE kind = 'message' AND id <> ?", [$row['id']]);
    mail_queue_run(time(), 100);
    same('suppressed', q_val('SELECT status FROM email_queue WHERE id = ?', [$row['id']]));
    // Preference off, muted, suppressed address.
    $old = gmdate('Y-m-d H:i:s', time() - 20 * 60);
    [$m2] = msg_post($ben, $c, 'third');
    q('UPDATE messages SET created_at = ? WHERE id = ?', [$old, $m2]);
    q('UPDATE users SET message_emails = 0 WHERE id = ?', [$ada['id']]);
    same(0, msg_digest_run(), 'preference off');
    q('UPDATE users SET message_emails = 1 WHERE id = ?', [$ada['id']]);
    msg_set_muted((int) $ada['id'], $c, true);
    [$m3] = msg_post($ben, $c, 'fourth');
    q('UPDATE messages SET created_at = ? WHERE id = ?', [$old, $m3]);
    same(0, msg_digest_run(), 'muted');
    msg_set_muted((int) $ada['id'], $c, false);
    q("INSERT INTO email_suppressions (email, reason) VALUES (?, 'hard_bounce')", [strtolower((string) $ada['email'])]);
    [$m4] = msg_post($ben, $c, 'fifth');
    q('UPDATE messages SET created_at = ? WHERE id = ?', [$old, $m4]);
    same(0, msg_digest_run(), 'bounced address');
    q('DELETE FROM email_suppressions WHERE email = ?', [strtolower((string) $ada['email'])]);
    // Team inbox digests go to the admins who answer it.
    $team = dm_staff(['messages']);
    q('UPDATE users SET message_emails = 0 WHERE role IN (\'super_admin\',\'admin\') AND id <> ?', [$team['id']]);
    $t = dm_start($ada, [], 'Team question', ['team' => true]);
    q('UPDATE messages SET created_at = ? WHERE conversation_id = ?', [$old, $t]);
    same(1, msg_digest_run(), 'the one team admin with notifications on');
    $tr = q_one("SELECT * FROM email_queue WHERE kind = 'message' ORDER BY id DESC LIMIT 1");
    same(strtolower((string) $team['email']), strtolower((string) $tr['to_email']));
    contains('/admin/messages/team/' . $t, (string) $tr['text_body']);
    q('UPDATE users SET message_emails = 1');
});

test('C3: retention deletes old messages (off by default)', function (): void {
    $ada = dm_member('Old');
    $ben = dm_member('Timer');
    $c = dm_start($ada, [$ben], 'ancient');
    q('UPDATE messages SET created_at = ? WHERE conversation_id = ?', [gmdate('Y-m-d H:i:s', strtotime('-14 months')), $c]);
    msg_post($ada, $c, 'recent');
    same(0, msg_retention_run(), 'keep forever by default');
    setting_set('messages.retention_months', 12);
    ok(msg_retention_run() >= 1);
    same(['recent'], array_column(q_all('SELECT body FROM messages WHERE conversation_id = ?', [$c]), 'body'));
    dm_reset_settings();
});

test('C3: data export has the person\'s own messages; deletion leaves "Former member" with no content', function (): void {
    $ada = dm_member('Leaver');
    $ben = dm_member('Stayer');
    $c = dm_start($ada, [$ben], 'my words');
    msg_post($ben, $c, 'their words');
    $export = member_export((int) $ada['id']);
    same(['my words'], array_column($export['messages'], 'text'), 'only own messages');
    ok($export['message_notifications'] === true);
    $t = dm_start($ada, [], 'team q', ['team' => true]);
    ok(account_delete((int) $ada['id']));
    $row = q_one("SELECT * FROM messages WHERE conversation_id = ? AND body = '' ORDER BY id LIMIT 1", [$c]);
    ok($row !== null && $row['sender_id'] === null && $row['deleted_by'] === 'account', 'content removed, sender gone');
    same('their words', q_val('SELECT body FROM messages WHERE conversation_id = ? AND sender_id = ?', [$c, $ben['id']]));
    $p = msg_present($row, $ben, [], true);
    ok($p['sender'] === 'Former member' && $p['deleted']);
    same(null, q_val('SELECT id FROM conversations WHERE id = ?', [$t]), 'the team thread goes with the account');
    ok(msg_post_problem(q_one('SELECT * FROM conversations WHERE id = ?', [$c]), $ben) !== null, 'cannot reply to a former member');
});

test('C3: logged in as a member, every message POST is refused (read-only even with allow changes)', function (): void {
    $member = dm_member('Viewed');
    $admin = fixture_admin('admin', ['impersonate', 'members']);
    act_as($admin);
    $_SESSION['imp'] = ['admin_id' => $admin, 'admin_sv' => (int) q_val('SELECT session_version FROM users WHERE id = ?', [$admin]), 'member_id' => (int) $member['id'],
        'expires' => time() + 600, 'name' => 'Viewed', 'allow_changes' => true, 'return' => '/admin', 'id' => 1];
    $_SESSION['user_id'] = (int) $member['id'];
    $_SESSION['sv'] = (int) q_val('SELECT session_version FROM users WHERE id = ?', [$member['id']]);
    current_user(true);
    foreach (['/account/messages', '/account/messages/5', '/account/messages/5/read', '/account/messages/5/m/9/report', '/account/messages/block/3'] as $path) {
        try {
            impersonation_guard('POST', $path);
            throw new AssertionError("POST $path passed");
        } catch (HttpException $e) {
            same(403, $e->status, $path);
            contains('read-only', $e->getMessage());
        } catch (Redirect $r) {
            throw new AssertionError("POST $path redirected to {$r->location}");
        }
    }
    impersonation_guard('GET', '/account/messages/5');
    ok(true, 'reading is allowed');
    $_SESSION = [];
});

test('C3: message attachments never appear in the media library', function (): void {
    $src = (string) file_get_contents(BASE . '/src/Admin/Media.php');
    contains("'messages'", $src);
    contains("visibility <> 'private'", $src);
});

// ---- Security review fixes (C3) ----------------------------------------------------------------

/** A tiny PNG file whose header claims $w × $h pixels (getimagesize reads only the header). */
function dm_png_header(int $w, int $h): string
{
    $ihdr = 'IHDR' . pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0);
    $idat = 'IDAT' . gzcompress("\0\0\0\0");
    $chunk = static fn (string $c): string => pack('N', strlen($c) - 4) . $c . pack('N', crc32($c));
    return fixture_file("\x89PNG\r\n\x1a\n" . $chunk($ihdr) . $chunk($idat) . $chunk('IEND'), 'png');
}

test('C3 review: rate_take is hit-first and gives hits back', function (): void {
    $b = 'take-test-' . bin2hex(random_bytes(3));
    $taken = [];
    for ($i = 0; $i < 3; $i++) {
        $taken[] = rate_take($b, 3, 60);
    }
    ok(!in_array(null, $taken, true), 'three allowed');
    same(null, rate_take($b, 3, 60), 'fourth refused');
    same(3, (int) q_val('SELECT COUNT(*) FROM rate_hits WHERE bucket = ?', [$b]), 'a refused take leaves no hit');
    rate_release($taken[0]);
    ok(rate_take($b, 3, 60) !== null, 'released hits free the budget');
    same(null, rate_take($b, 5, 60, 3), 'several at once: all or nothing');
    same(3, (int) q_val('SELECT COUNT(*) FROM rate_hits WHERE bucket = ?', [$b]));
});

test('C3 review: search needs 3 letters or an exact number; opted-out members are not findable by members', function (): void {
    dm_reset_settings();
    $ada = dm_member('Seeker');
    $hid = dm_member('Hideaway');
    $num = (string) q_val('SELECT member_number FROM members WHERE user_id = ?', [$hid['id']]);
    same([], msg_recipient_search($ada, 'Hi'), 'two letters find nobody');
    ok(count(msg_recipient_search($ada, 'Hid')) === 1);
    q('UPDATE members SET member_number = ? WHERE user_id = ?', ['Z9', $hid['id']]);
    same([(int) $hid['id']], array_column(msg_recipient_search($ada, 'Z9'), 'id'), 'a short exact member number works');
    q('UPDATE members SET member_number = ? WHERE user_id = ?', [$num, $hid['id']]);
    q('UPDATE users SET message_findable = 0 WHERE id = ?', [$hid['id']]);
    same([], msg_recipient_search($ada, 'Hideaway'), 'opted out: members do not find them');
    same([], msg_recipient_search($ada, $num), 'not by number either');
    $staff = dm_staff();
    same([(int) $hid['id']], array_column(msg_recipient_search($staff, 'Hideaway'), 'id'), 'admins still find them');
    [, $err] = msg_start($ada, [(int) $hid['id']], 'guessing the id');
    same(MSG_NOT_AVAILABLE, $err, 'members cannot message an opted-out person by id');
    same(MSG_NOT_AVAILABLE, msg_can_start_with(msg_account((int) $ada['id']), msg_account(999999999) ?? ['id' => 999999999, 'role' => 'member', 'message_findable' => 0, 'status' => 'active']), 'missing ids get the same answer');
    dm_start(dm_user((int) $hid['id']), [$ada], 'I write first');
    same(null, msg_can_start_with(msg_account((int) $ada['id']), msg_account((int) $hid['id'])), 'people who share a conversation can still message');
    $ids = [];
    $viewer = dm_member('Searchy');
    for ($i = 0; $i < MSG_SEARCHES_PER_WINDOW; $i++) {
        $ids[] = msg_recipient_search_limited($viewer, 'Seeker');
    }
    ok(!in_array(null, $ids, true));
    same(null, msg_recipient_search_limited($viewer, 'Seeker'), 'searches are rate limited per member');
    ok(msg_recipient_search_limited($staff, 'Seeker') !== null, 'staff are not');
});

test('C3 review: daily caps on new recipients and on adding people (members)', function (): void {
    dm_reset_settings();
    $ada = dm_member('Spreader');
    $known = dm_member('Known');
    dm_start($ada, [$known], 'hello');
    for ($i = 0; $i < MSG_NEW_RECIPIENTS_PER_DAY - 1; $i++) {
        rate_hit('msgnew:' . $ada['id']);
    }
    $a = dm_member('Na');
    $b = dm_member('Nb');
    [, $err] = msg_start($ada, [(int) $a['id'], (int) $b['id']], 'two new people', ['title' => 'G']);
    ok(str_contains((string) $err, 'many new people'), 'two new people over the cap');
    same(0, (int) q_val('SELECT COUNT(*) FROM conversations WHERE title = ? AND created_by = ?', ['G', $ada['id']]), 'nothing created');
    q("DELETE FROM rate_hits WHERE bucket LIKE 'msg:%'");
    [$id, $err] = msg_start($ada, [(int) $known['id']], 'people I know do not count');
    same(null, $err);
    q("DELETE FROM rate_hits WHERE bucket = ?", ['msgnew:' . $ada['id']]);
    $g = dm_start($ada, [$a, $b], 'group', ['title' => 'Adds']);
    for ($i = 0; $i < MSG_ADDS_PER_DAY; $i++) {
        rate_hit('msgadd:' . $ada['id']);
    }
    ok(str_contains((string) msg_add_people($ada, $g, [(int) dm_member('Late')['id']]), 'many times'), 'add-people is capped for members');
    $staff = dm_staff();
    for ($i = 0; $i < MSG_ADDS_PER_DAY; $i++) {
        rate_hit('msgadd:' . $staff['id']);
    }
    same(null, msg_add_people($staff, dm_start($staff, [$a], 'x', ['title' => 'Staff group']), [(int) $b['id']]), 'staff are not capped');
});

test('C3 review: digest keeps sender and preview for staff, Team and once the recipient replied', function (): void {
    $member = dm_member('Recipient');
    $staff = dm_staff();
    $c = dm_start($staff, [$member], 'From the committee: the AGM is on Friday');
    $d = msg_digest_compose($c, (int) $member['id'], false);
    contains('From the committee', $d['text'], 'staff messages keep the preview');
    contains((string) $staff['display_name'], $d['subject']);
    $other = dm_member('Stranger');
    $c2 = dm_start($other, [$member], 'Buy my stuff now');
    $d2 = msg_digest_compose($c2, (int) $member['id'], false);
    not_contains('Buy my stuff', $d2['text'] . $d2['html']);
    not_contains('Stranger', $d2['subject'] . $d2['text'] . $d2['html']);
    msg_post($member, $c2, 'Who are you?');
    msg_post($other, $c2, 'Your neighbour from number 4');
    $d3 = msg_digest_compose($c2, (int) $member['id'], false);
    contains('Your neighbour', $d3['text'], 'after a reply the preview is shown');
});

test('C3 review: digests are re-checked and re-rendered when sent', function (): void {
    q("DELETE FROM email_queue WHERE kind = 'message'");
    $a = dm_member('Gone');
    $staff = dm_staff();
    $g = dm_start($staff, [$a, dm_member('Stay')], 'Original text', ['title' => 'Recheck']);
    $mid = (int) q_val('SELECT MAX(id) FROM messages WHERE conversation_id = ?', [$g]);
    $row = ['to_email' => $a['email'], 'dedupe_key' => "msg:$g:{$a['id']}:$mid"];
    same(null, msg_digest_block_reason($row));
    q("UPDATE messages SET body = 'Edited text' WHERE id = ?", [$mid]);
    contains('Edited text', msg_digest_rebuild($row)['text'], 'preview built at send time');
    msg_leave($a, $g);
    ok(str_contains((string) msg_digest_block_reason($row), 'no longer sees'), 'left the group');
    $b = dm_member('Other');
    $c = dm_start($staff, [$b], 'Will be removed');
    $m2 = (int) q_val('SELECT MAX(id) FROM messages WHERE conversation_id = ?', [$c]);
    msg_blank($m2, 'moderator');
    ok(str_contains((string) msg_digest_block_reason(['to_email' => $b['email'], 'dedupe_key' => "msg:$c:{$b['id']}:$m2"]), 'deleted or removed'));
    $x = dm_member('Blocky');
    $y = dm_member('Blocked');
    $d = dm_start($y, [$x], 'hi');
    $m3 = (int) q_val('SELECT MAX(id) FROM messages WHERE conversation_id = ?', [$d]);
    msg_block((int) $x['id'], (int) $y['id']);
    ok(msg_digest_block_reason(['to_email' => $x['email'], 'dedupe_key' => "msg:$d:{$x['id']}:$m3"]) !== null, 'blocked since');
});

test('C3 review: people added to a group do not see earlier messages, attachments or report them', function (): void {
    $staff = dm_staff();
    $a = dm_member('Early');
    $g = dm_start($staff, [$a], 'Before you joined: secret plans', ['title' => 'History']);
    $jpg = fixture_jpeg(400, 300);
    [$photo] = msg_post($staff, $g, '', ['name' => 'old.jpg', 'tmp_name' => $jpg, 'size' => filesize($jpg), 'error' => UPLOAD_ERR_OK], false, false);
    $media = media_find((int) q_val('SELECT attachment_media_id FROM messages WHERE id = ?', [$photo]));
    $late = dm_member('Late');
    same(null, msg_add_people($staff, $g, [(int) $late['id']]));
    msg_post($staff, $g, 'Welcome!');
    $conv = msg_conversation_for($g, (int) $late['id']);
    [$rows] = msg_thread_rows($g, 0, 0, (int) $conv['p_first']);
    $bodies = array_column($rows, 'body');
    ok(!in_array('Before you joined: secret plans', $bodies, true) && in_array('Welcome!', $bodies, true), 'earlier messages hidden');
    [$all] = msg_thread_rows($g, 0, 0, (int) msg_conversation_for($g, (int) $a['id'])['p_first']);
    ok(in_array('Before you joined: secret plans', array_column($all, 'body'), true), 'first participants see everything');
    ok(!media_can_view($media, dm_user((int) $late['id'])), 'earlier attachment not visible to the newcomer');
    ok(media_can_view($media, dm_user((int) $a['id'])));
    ok(msg_report($late, (int) $photo, 'x') !== null, 'cannot report what you cannot see');
    same(0, count(msg_conversations((int) $late['id'], 'secret plans')), 'search does not match hidden text');
    same(0, (int) q_val('SELECT first_visible_id FROM conversation_participants WHERE conversation_id = ? AND user_id = ?', [$g, $a['id']]));
});

test('C3 review: members verified < 24 h post only in team threads, with staff, or as replies', function (): void {
    dm_reset_settings();
    $new = dm_member('Brandnew', 2);
    $old = dm_member('Oldtimer');
    $staff = dm_staff();
    $reply = dm_start($old, [$new], 'Welcome to the club');
    [, $err] = msg_post($new, $reply, 'Thanks!');
    same(null, $err, 'replying in a 1-to-1 someone else started');
    $sg = dm_start($staff, [$new, $old], 'Hello both', ['title' => 'Staff-led']);
    [, $err] = msg_post($new, $sg, 'Hi');
    same(null, $err, 'groups started by staff are fine');
    $mg = dm_start($old, [$new, dm_member('Third')], 'Members group', ['title' => 'Members-led']);
    [, $err] = msg_post($new, $mg, 'Hi all');
    ok(str_contains((string) $err, 'first day'), 'not in a members\' group they were added to');
    $t = dm_start($new, [], 'Question', ['team' => true]);
    [, $err] = msg_post($new, $t, 'And another');
    same(null, $err, 'team threads are fine');
    q('UPDATE members SET verified_at = ? WHERE user_id = ?', [gmdate('Y-m-d H:i:s', time() - 30 * 3600), $new['id']]);
    [, $err] = msg_post($new, $mg, 'Now I can');
    same(null, $err, 'after 24 hours');
});

test('C3 review: impersonation audits the first access to messages once per session', function (): void {
    $member = dm_member('Seen');
    $admin = fixture_admin('admin', ['impersonate']);
    act_as((int) $member['id']);
    $_SESSION['imp'] = ['admin_id' => $admin, 'admin_sv' => 0, 'member_id' => (int) $member['id'], 'expires' => time() + 600, 'name' => 'Seen'];
    $before = (int) q_val("SELECT COUNT(*) FROM audit_log WHERE action = 'message_access'");
    msg_impersonation_audit();
    msg_impersonation_audit();
    same($before + 1, (int) q_val("SELECT COUNT(*) FROM audit_log WHERE action = 'message_access'"));
    $row = q_one("SELECT * FROM audit_log WHERE action = 'message_access' ORDER BY id DESC LIMIT 1");
    same($admin, (int) $row['user_id']);
    same((int) $member['id'], (int) $row['on_behalf_of']);
    $_SESSION = [];
});

test('C3 review: block has one answer; announcement groups only pass to staff, else close', function (): void {
    $m = dm_member('Blocker');
    $staff = dm_staff();
    $msg = msg_block((int) $m['id'], (int) $staff['id']);
    same($msg, msg_block((int) $m['id'], 999999999));
    same($msg, msg_block((int) $m['id'], (int) $m['id']));
    $a = dm_member('Reader');
    $ann = dm_start($staff, [$a, $m], 'News', ['title' => 'Closing', 'announcement' => true]);
    $staff2 = dm_staff();
    $ann2 = dm_start($staff, [$a, $staff2], 'News 2', ['title' => 'Handover', 'announcement' => true]);
    same(null, msg_leave($staff, $ann2));
    same('owner', q_val('SELECT part_role FROM conversation_participants WHERE conversation_id = ? AND user_id = ?', [$ann2, $staff2['id']]), 'another admin takes over');
    same(null, msg_leave($staff, $ann));
    ok(q_val('SELECT archived_at FROM conversations WHERE id = ?', [$ann]) !== null, 'no admin left: closed');
    same('member', q_val('SELECT part_role FROM conversation_participants WHERE conversation_id = ? AND user_id = ?', [$ann, $a['id']]), 'members never become owners');
    ok(str_contains((string) msg_post_problem(msg_conversation_for($ann, (int) $a['id']), $a), 'closed'));
});

test('C3 review: favicon uploads are capped at 16 megapixels; generation is locked', function (): void {
    $big = dm_png_header(5000, 4000);
    ok(str_contains((string) favicon_upload_problem($big, 'big.png', (int) filesize($big)), '16 megapixels'));
    setting_set('site.name', 'Lock test ' . bin2hex(random_bytes(3)));
    $in = favicon_inputs();
    $dir = favicon_dir(favicon_signature($in));
    if (is_dir($dir)) {
        favicon_rmdir($dir); // the set depends on the initial and colours only: start without it
    }
    $root = dirname($dir);
    if (!is_dir($root)) {
        mkdir($root, 0755, true);
    }
    $h = fopen($root . '/.lock', 'c');
    ok(flock($h, LOCK_EX));
    // Another process holds the lock: a page view does not wait and falls back (checked in a child process).
    $code = 'putenv("GATHER_CONFIG=' . BASE . '/tests/config.test.php"); require "' . BASE . '/src/load.php"; echo favicon_ensure() === null ? "fallback" : "drawn";';
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code));
    same('fallback', trim($out));
    flock($h, LOCK_UN);
    fclose($h);
    ok(favicon_ensure(null, true) !== null, 'drawn once the lock is free');
    setting_set('site.name', 'Gather');
});
