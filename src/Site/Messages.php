<?php
declare(strict_types=1);

namespace Site;

/**
 * Direct messages (Addendum C3). The same handlers serve members at
 * /account/messages (inside the account shell) and staff at /admin/messages
 * (admin layout). Everything works without JavaScript (forms + reload);
 * messages.js adds live recipient search, sending without a reload, polling
 * and read marks.
 */
final class Messages
{
    /**
     * Who is asking and where: ['admin' => bool, 'base' => path, 'user' => row, 'readonly' => bool].
     * Staff use /admin/messages; members and groups need a verified account.
     */
    private static function ctx(): array
    {
        $admin = str_starts_with(request_path(), '/admin/');
        if ($admin) {
            $user = require_staff();
        } else {
            $user = require_login();
            if (is_staff($user)) {
                redirect('/admin/messages');
            }
            if ($user['status'] !== 'active') {
                flash('Please verify your email first: open the link we sent you, or send a new one below.', 'info');
                redirect('/account');
            }
        }
        return ['admin' => $admin, 'base' => $admin ? '/admin/messages' : '/account/messages', 'user' => $user, 'readonly' => is_impersonating()];
    }

    /** Audit entries for admin actions only (members' own messaging is not site administration). */
    private static function log(array $ctx, string $action, string $type, ?int $id, string $summary = ''): void
    {
        if ($ctx['admin']) {
            audit($action, $type, $id, $summary);
        }
    }

    private static function wantsJson(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    private static function page(array $ctx, string $template, string $title, array $data, int $status = 200): void
    {
        $data += ['ctx' => $ctx, 'title' => $title, 'user' => $ctx['user'], 'extra_css' => $ctx['admin'] ? ['assets/css/messages.css'] : ['assets/css/card.css', 'assets/css/messages.css']];
        if ($ctx['admin']) {
            render('messages/' . $template, $data, 'admin', $status);
            return;
        }
        \Account\Account::shell('messages/' . $template, $title, 'messages', $data + ['noindex' => true], $status);
    }

    // ---- List and new conversation ---------------------------------------------------

    public static function index(array $p): void
    {
        $ctx = self::ctx();
        $q = mb_substr(query_param('q'), 0, 100);
        $data = [
            'q' => $q,
            'rows' => msg_conversations((int) $ctx['user']['id'], $q),
            'blocked_count' => (int) q_val('SELECT COUNT(*) FROM message_blocks WHERE blocker_id = ?', [$ctx['user']['id']]),
        ];
        if ($ctx['admin']) {
            $data['message_emails'] = (int) q_val('SELECT message_emails FROM users WHERE id = ?', [$ctx['user']['id']]) === 1;
            $data['team_unread'] = user_can($ctx['user'], 'messages') ? msg_team_unread_count() : null;
            $data['reports_open'] = user_can($ctx['user'], 'messages') ? msg_reports_open_count() : null;
        }
        self::page($ctx, 'index', 'Messages', $data);
    }

    public static function create(array $p): void
    {
        $ctx = self::ctx();
        $old = take_old_input();
        $values = $old['values'];
        $q = mb_substr(query_param('q', (string) ($values['q'] ?? '')), 0, 80);
        $selectedIds = array_map('intval', is_array($_GET['to'] ?? null) ? array_filter($_GET['to'], 'is_string') : (isset($_GET['to']) && is_string($_GET['to']) ? [$_GET['to']] : []));
        $selectedIds = array_merge($selectedIds, array_map('intval', (array) ($values['to'] ?? [])));
        $viewer = msg_account((int) $ctx['user']['id']);
        $selected = [];
        foreach (msg_accounts($selectedIds) as $a) {
            if ($viewer !== null && msg_can_message($viewer, $a) === null) {
                $selected[] = msg_recipient_item($a);
            }
        }
        $selectedKeys = array_column($selected, 'id');
        $results = array_values(array_filter(msg_recipient_search($ctx['user'], $q), static fn (array $r): bool => !in_array($r['id'], $selectedKeys, true)));
        $s = msg_settings();
        self::page($ctx, 'new', 'New message', [
            'q' => $q, 'searched' => $q !== '' || isset($_GET['q']), 'results' => $results, 'selected' => $selected,
            'team_option' => !$ctx['admin'] && $s['team_inbox'],
            'team_checked' => !empty($values['team']) || query_param('team') === '1',
            'values' => $values, 'errors' => $old['errors'],
            'new_account' => !$ctx['admin'] && $viewer !== null && msg_account_age_hours($viewer) < MSG_NEW_ACCOUNT_HOURS,
            'member_to_member' => $s['member_to_member'],
        ]);
    }

    /** JSON recipient search for the picker (people the viewer may message). */
    public static function recipients(array $p): void
    {
        $ctx = self::ctx();
        json_out(['results' => msg_recipient_search($ctx['user'], query_param('q'))]);
    }

    public static function store(array $p): void
    {
        csrf_check();
        $ctx = self::ctx();
        $to = array_values(array_filter(array_map('intval', array_filter((array) ($_POST['to'] ?? []), 'is_string'))));
        $values = ['q' => input('q'), 'title' => input('title'), 'body' => is_string($_POST['body'] ?? null) ? $_POST['body'] : '', 'to' => $to,
            'team' => input('team') === '1', 'announcement' => input('announcement') === '1', 'everyone' => input('everyone') === '1'];
        $back = $ctx['base'] . '/new' . self::toQuery($to, input('q'));
        if (isset($_POST['_find'])) {
            // Without JavaScript: search for people, keeping what was typed.
            remember_input($values, []);
            redirect($back);
        }
        [$convId, $error] = msg_start($ctx['user'], $to, $values['body'], [
            'title' => $values['title'], 'team' => $values['team'],
            'announcement' => $ctx['admin'] && $values['announcement'], 'everyone' => $ctx['admin'] && $values['everyone'],
        ], $_FILES['attachment'] ?? null);
        if ($error !== null) {
            remember_input($values, ['form' => $error]);
            flash($error, 'error');
            redirect($back);
        }
        self::log($ctx, 'message_start', 'conversation', $convId, (string) q_val('SELECT kind FROM conversations WHERE id = ?', [$convId]) . ' conversation');
        flash('Message sent.');
        redirect($ctx['base'] . '/' . $convId . '#latest');
    }

    private static function toQuery(array $to, string $q = ''): string
    {
        $parts = [];
        foreach ($to as $id) {
            $parts[] = 'to[]=' . (int) $id;
        }
        if ($q !== '') {
            $parts[] = 'q=' . rawurlencode($q);
        }
        return $parts ? '?' . implode('&', $parts) : '';
    }

    // ---- Thread -----------------------------------------------------------------------

    /** The conversation for this viewer, or 404. */
    private static function conversation(array $ctx, int $id): array
    {
        $conv = msg_conversation_for($id, (int) $ctx['user']['id']);
        if ($conv === null) {
            abort(404);
        }
        return $conv;
    }

    public static function show(array $p): void
    {
        $ctx = self::ctx();
        $conv = self::conversation($ctx, (int) $p['id']);
        if ($ctx['readonly']) {
            // B2 + C3: an admin viewing as the member reads the member's messages; every view is recorded.
            audit('message_view', 'conversation', (int) $conv['id'], 'viewed while logged in as the member');
        }
        $data = self::threadData($ctx, $conv, false);
        // Members: the account shell's heading says "Messages", the thread names the conversation (h2).
        self::page($ctx, 'thread', $ctx['admin'] ? $data['title_text'] : 'Messages', $data);
    }

    /**
     * Everything the thread template needs (also used by the Team inbox).
     * @return array<string,mixed>
     */
    public static function threadData(array $ctx, array $conv, bool $teamView): array
    {
        $user = $ctx['user'];
        $convId = (int) $conv['id'];
        [$rows, $hasEarlier] = msg_thread_rows($convId, 0, max(0, input_int('before', 0, true)));
        $names = msg_accounts(array_map(static fn (array $m): int => (int) ($m['sender_id'] ?? 0), $rows));
        $canAct = !$ctx['readonly'] && !$teamView;
        $messages = array_map(static fn (array $m): array => msg_present($m, $user, $names, $canAct), $rows);
        $participants = msg_participants([$convId])[$convId] ?? [];
        $other = null;
        if ($conv['kind'] === 'direct') {
            foreach ($participants as $pp) {
                if ((int) $pp['user_id'] !== (int) $user['id']) {
                    $other = $pp['account'];
                }
            }
            $other ??= ['id' => 0, 'role' => 'member', 'name' => 'Former member'];
        }
        $old = take_old_input();
        $act = query_param('act');
        $actId = input_int('m', 0, true);
        $lastId = (int) q_val('SELECT COALESCE(MAX(id), 0) FROM messages WHERE conversation_id = ?', [$convId]);
        $readUpTo = $teamView ? (int) $conv['team_read_message_id'] : (int) ($conv['p_read'] ?? 0);
        $hasUnread = (int) q_val('SELECT COUNT(*) FROM messages WHERE conversation_id = ? AND id > ? AND deleted_at IS NULL AND note_kind IS NULL AND (sender_id IS NULL OR sender_id <> ?)', [$convId, $readUpTo, $user['id']]) > 0;
        $addQ = mb_substr(query_param('add_q'), 0, 80);
        $manage = !$teamView && !$ctx['readonly'] && msg_can_manage($conv, $user);
        $base = $teamView ? '/admin/messages/team' : $ctx['base'];
        return [
            'conv' => $conv, 'team_view' => $teamView, 'base' => $base, 'messages' => $messages, 'has_earlier' => $hasEarlier,
            'participants' => $participants, 'other' => $other, 'last_id' => $lastId, 'has_unread' => $hasUnread,
            'post_problem' => $ctx['readonly'] ? 'You are viewing the member’s messages read-only. Return to admin to send messages as yourself.' : msg_post_problem($conv, $user, $teamView),
            'act' => in_array($act, ['edit', 'delete', 'report'], true) ? $act : '', 'act_id' => $actId,
            'values' => $old['values'], 'errors' => $old['errors'],
            'manage' => $manage, 'add_q' => $addQ,
            'add_results' => $manage && $addQ !== '' ? array_values(array_filter(msg_recipient_search($user, $addQ), static fn (array $r): bool => !in_array($r['id'], array_map('intval', array_column($participants, 'user_id')), true))) : [],
            'i_blocked' => $other !== null && (int) $other['id'] > 0 && msg_blocks((int) $user['id'], (int) $other['id']),
            'title_text' => msg_conversation_title($conv, $participants, (int) $user['id'], $teamView),
            'poll_url' => $base . '/' . $convId . '/poll', 'read_url' => $base . '/' . $convId . '/read',
            'since' => gmdate('Y-m-d H:i:s', time() - 1),
        ];
    }

    public static function send(array $p): void
    {
        csrf_check();
        $ctx = self::ctx();
        $convId = (int) $p['id'];
        [$msgId, $error] = msg_post($ctx['user'], $convId, is_string($_POST['body'] ?? null) ? $_POST['body'] : '', $_FILES['attachment'] ?? null);
        self::afterSend($ctx, $ctx['base'], $convId, $msgId, $error, false);
    }

    /** Shared by member/staff replies and Team inbox replies: JSON for messages.js, otherwise PRG. */
    public static function afterSend(array $ctx, string $base, int $convId, ?int $msgId, ?string $error, bool $teamView): never
    {
        if ($error === null && $msgId !== null) {
            if ($ctx['admin']) {
                audit($teamView ? 'team_reply' : 'message_send', 'conversation', $convId, $teamView ? 'answered in the Team inbox' : 'message sent');
            }
        }
        if (self::wantsJson()) {
            if ($error !== null) {
                json_out(['ok' => false, 'error' => $error], 422);
            }
            $row = q_one('SELECT * FROM messages WHERE id = ?', [$msgId]);
            json_out(['ok' => true, 'message' => msg_present((array) $row, $ctx['user'], msg_accounts([(int) $ctx['user']['id']]), !$teamView)]);
        }
        if ($error !== null) {
            remember_input(['body' => is_string($_POST['body'] ?? null) ? $_POST['body'] : ''], ['body' => $error]);
            flash($error, 'error');
            redirect($base . '/' . $convId . '#composer');
        }
        redirect($base . '/' . $convId . '#m-' . $msgId);
    }

    /** JSON: messages newer than ?after, and earlier ones changed since ?since (edits, deletions). */
    public static function poll(array $p): void
    {
        $ctx = self::ctx();
        $conv = msg_conversation_for((int) $p['id'], (int) $ctx['user']['id']);
        if ($conv === null) {
            json_out(['ok' => false, 'error' => 'This conversation is not available.'], 404);
        }
        self::pollOut($ctx, $conv, false);
    }

    public static function pollOut(array $ctx, array $conv, bool $teamView): never
    {
        $convId = (int) $conv['id'];
        $after = max(0, input_int('after', 0, true));
        $since = query_param('since');
        $new = $after > 0 ? msg_thread_rows($convId, $after)[0] : [];
        $changed = [];
        if ($after > 0 && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $since)) {
            $changed = q_all('SELECT * FROM messages WHERE conversation_id = ? AND id <= ? AND updated_at >= ? ORDER BY id LIMIT 200', [$convId, $after, $since]);
        }
        $all = array_merge($new, $changed);
        $names = msg_accounts(array_map(static fn (array $m): int => (int) ($m['sender_id'] ?? 0), $all));
        $canAct = !$ctx['readonly'] && !$teamView;
        $present = static fn (array $m): array => msg_present($m, $ctx['user'], $names, $canAct);
        header('Cache-Control: no-store');
        json_out([
            'ok' => true,
            'messages' => array_map($present, $new),
            'changed' => array_map($present, $changed),
            'last_id' => (int) q_val('SELECT COALESCE(MAX(id), 0) FROM messages WHERE conversation_id = ?', [$convId]),
            // Database time, minus a second so a change in the same second is seen again rather than missed.
            'since' => gmdate('Y-m-d H:i:s', time() - 1),
            'unread' => $teamView ? null : msg_unread_count((int) $ctx['user']['id']),
        ]);
    }

    /** POST: mark the conversation read (JS after showing new messages; a button without JS). */
    public static function read(array $p): void
    {
        csrf_check();
        $ctx = self::ctx();
        $conv = self::conversation($ctx, (int) $p['id']);
        $upTo = input_int('up_to');
        msg_mark_read((int) $ctx['user']['id'], (int) $conv['id'], $upTo > 0 ? $upTo : null);
        if (self::wantsJson()) {
            json_out(['ok' => true, 'unread' => msg_unread_count((int) $ctx['user']['id'])]);
        }
        redirect($ctx['base'] . '/' . (int) $conv['id']);
    }

    public static function unread(array $p): void
    {
        $ctx = self::ctx();
        header('Cache-Control: no-store');
        json_out(['unread' => $ctx['admin'] ? msg_admin_badge($ctx['user']) : msg_unread_count((int) $ctx['user']['id'])]);
    }

    // ---- Message actions ----------------------------------------------------------------

    public static function edit(array $p): void
    {
        csrf_check();
        $ctx = self::ctx();
        $conv = self::conversation($ctx, (int) $p['id']);
        $msgId = (int) $p['item'];
        $error = q_val('SELECT 1 FROM messages WHERE id = ? AND conversation_id = ?', [$msgId, $conv['id']]) === null
            ? 'This message is not available.'
            : msg_edit($ctx['user'], $msgId, is_string($_POST['body'] ?? null) ? $_POST['body'] : '');
        if ($error === null) {
            self::log($ctx, 'message_edit', 'conversation', (int) $conv['id'], 'own message edited');
        }
        if (self::wantsJson()) {
            $error === null ? json_out(['ok' => true]) : json_out(['ok' => false, 'error' => $error], 422);
        }
        flash($error ?? 'Message updated.', $error === null ? 'success' : 'error');
        redirect($ctx['base'] . '/' . (int) $conv['id'] . ($error === null ? '#m-' . $msgId : '?act=edit&m=' . $msgId . '#m-' . $msgId));
    }

    public static function delete(array $p): void
    {
        csrf_check();
        $ctx = self::ctx();
        $conv = self::conversation($ctx, (int) $p['id']);
        $msgId = (int) $p['item'];
        $ok = q_val('SELECT 1 FROM messages WHERE id = ? AND conversation_id = ?', [$msgId, $conv['id']]) !== null && msg_delete_own($ctx['user'], $msgId);
        if ($ok) {
            self::log($ctx, 'message_delete', 'conversation', (int) $conv['id'], 'own message deleted');
        }
        flash($ok ? 'Message deleted.' : 'This message cannot be deleted.', $ok ? 'success' : 'error');
        redirect($ctx['base'] . '/' . (int) $conv['id'] . '#m-' . $msgId);
    }

    public static function report(array $p): void
    {
        csrf_check();
        $ctx = self::ctx();
        $conv = self::conversation($ctx, (int) $p['id']);
        $msgId = (int) $p['item'];
        $error = q_val('SELECT 1 FROM messages WHERE id = ? AND conversation_id = ?', [$msgId, $conv['id']]) === null
            ? 'This message cannot be reported.'
            : msg_report($ctx['user'], $msgId, input('reason'));
        if ($error === null) {
            self::log($ctx, 'message_report', 'message', $msgId, 'reported a message');
        }
        flash($error ?? 'Thank you. The message was reported; an admin will review it.', $error === null ? 'success' : 'error');
        redirect($ctx['base'] . '/' . (int) $conv['id'] . '#m-' . $msgId);
    }

    // ---- Conversation actions -------------------------------------------------------------

    public static function mute(array $p): void
    {
        csrf_check();
        $ctx = self::ctx();
        $conv = self::conversation($ctx, (int) $p['id']);
        $mute = input('muted') === '1';
        msg_set_muted((int) $ctx['user']['id'], (int) $conv['id'], $mute);
        self::log($ctx, $mute ? 'message_mute' : 'message_unmute', 'conversation', (int) $conv['id']);
        flash($mute ? 'Conversation muted: no badge count or emails for it.' : 'Conversation unmuted.');
        redirect($ctx['base'] . '/' . (int) $conv['id']);
    }

    public static function leave(array $p): void
    {
        csrf_check();
        $ctx = self::ctx();
        $error = msg_leave($ctx['user'], (int) $p['id']);
        if ($error !== null) {
            flash($error, 'error');
            redirect($ctx['base'] . '/' . (int) $p['id']);
        }
        self::log($ctx, 'message_leave', 'conversation', (int) $p['id'], 'left the conversation');
        flash('You left the conversation.');
        redirect($ctx['base']);
    }

    public static function addPeople(array $p): void
    {
        csrf_check();
        $ctx = self::ctx();
        $ids = array_map('intval', array_filter((array) ($_POST['to'] ?? []), 'is_string'));
        $error = msg_add_people($ctx['user'], (int) $p['id'], $ids);
        if ($error === null) {
            self::log($ctx, 'message_people_add', 'conversation', (int) $p['id'], count($ids) . ' added');
        }
        flash($error ?? 'Added to the conversation.', $error === null ? 'success' : 'error');
        redirect($ctx['base'] . '/' . (int) $p['id'] . ($error === null ? '#latest' : '#people'));
    }

    public static function removePerson(array $p): void
    {
        csrf_check();
        $ctx = self::ctx();
        $error = msg_remove_person($ctx['user'], (int) $p['id'], (int) $p['item']);
        if ($error === null) {
            self::log($ctx, 'message_people_remove', 'conversation', (int) $p['id'], 'removed user #' . (int) $p['item']);
        }
        flash($error ?? 'Removed from the conversation.', $error === null ? 'success' : 'error');
        redirect($ctx['base'] . '/' . (int) $p['id'] . '#people');
    }

    // ---- Blocking ------------------------------------------------------------------------

    public static function blocked(array $p): void
    {
        $ctx = self::ctx();
        self::page($ctx, 'blocked', 'Blocked people', ['rows' => msg_blocked_list((int) $ctx['user']['id'])]);
    }

    public static function block(array $p): void
    {
        csrf_check();
        $ctx = self::ctx();
        $error = msg_block((int) $ctx['user']['id'], (int) $p['item']);
        if ($error === null) {
            self::log($ctx, 'message_block', 'account', (int) $p['item'], 'blocked in messages');
        }
        flash($error ?? 'Blocked. They cannot message you, and your conversation with them is hidden. You can unblock them under Blocked people.', $error === null ? 'success' : 'error');
        redirect($error === null ? $ctx['base'] : safe_redirect_target(input('back'), $ctx['base']));
    }

    public static function unblock(array $p): void
    {
        csrf_check();
        $ctx = self::ctx();
        if (msg_unblock((int) $ctx['user']['id'], (int) $p['item'])) {
            self::log($ctx, 'message_unblock', 'account', (int) $p['item'], 'unblocked in messages');
            flash('Unblocked.');
        }
        redirect($ctx['base'] . '/blocked');
    }

    /** Staff: "Message notifications" for their own messages and the Team inbox. */
    public static function preferences(array $p): void
    {
        csrf_check();
        $user = require_staff();
        $on = input('message_emails') === '1';
        q('UPDATE users SET message_emails = ? WHERE id = ?', [$on ? 1 : 0, $user['id']]);
        audit('preferences', 'account', (int) $user['id'], 'message notifications ' . ($on ? 'on' : 'off'));
        flash('Saved: message notification emails are ' . ($on ? 'on.' : 'off.'));
        redirect('/admin/messages');
    }
}
