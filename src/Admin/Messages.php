<?php
declare(strict_types=1);

namespace Admin;

/**
 * Addendum C3 staff screens beyond their own messages (those are served by
 * Site\Messages under /admin/messages): the shared Team inbox and reported
 * messages (permission `messages`), and the super admin's message settings.
 */
final class Messages
{
    private static function teamCtx(): array
    {
        $user = require_permission('messages');
        return ['admin' => true, 'base' => '/admin/messages/team', 'user' => $user, 'readonly' => false];
    }

    private static function teamConversation(array $user, int $id): array
    {
        $conv = msg_team_conversation($id, $user);
        if ($conv === null) {
            abort(404);
        }
        return $conv;
    }

    // ---- Team inbox -------------------------------------------------------------------

    public static function team(array $p): void
    {
        self::teamCtx();
        $show = query_param('show') === 'unread' ? 'unread' : '';
        $q = mb_substr(query_param('q'), 0, 100);
        render('messages/team', [
            'extra_css' => ['assets/css/messages.css'], 'title' => 'Team inbox', 'show' => $show, 'q' => $q, 'rows' => msg_team_conversations($show, $q),
            'unread_count' => msg_team_unread_count(), 'enabled' => msg_settings()['team_inbox'],
        ], 'admin');
    }

    public static function teamShow(array $p): void
    {
        $ctx = self::teamCtx();
        $conv = self::teamConversation($ctx['user'], (int) $p['id']);
        $data = \Site\Messages::threadData($ctx, $conv, true);
        $member = null;
        foreach ($data['participants'] as $pp) {
            if (($pp['account']['role'] ?? '') !== 'super_admin' && ($pp['account']['role'] ?? '') !== 'admin') {
                $member = $pp['account'];
            }
        }
        $memberId = $member !== null ? (int) q_val('SELECT id FROM members WHERE user_id = ?', [$member['id']]) : 0;
        render('messages/thread', $data + ['extra_css' => ['assets/css/messages.css'], 'ctx' => $ctx, 'user' => $ctx['user'], 'title' => 'Team inbox: ' . $data['title_text'],
            'team_member' => $member, 'team_member_url' => $memberId > 0 && user_can($ctx['user'], 'members') ? '/admin/members/' . $memberId : null], 'admin');
    }

    public static function teamReply(array $p): void
    {
        csrf_check();
        $ctx = self::teamCtx();
        $convId = (int) $p['id'];
        [$msgId, $error] = msg_post($ctx['user'], $convId, is_string($_POST['body'] ?? null) ? $_POST['body'] : '', $_FILES['attachment'] ?? null, true);
        if ($error === null) {
            audit('team_reply', 'conversation', $convId, 'answered in the Team inbox');
        }
        \Site\Messages::afterSend(['admin' => false] + $ctx, '/admin/messages/team', $convId, $msgId, $error, true);
    }

    public static function teamPoll(array $p): void
    {
        $ctx = self::teamCtx();
        $conv = msg_team_conversation((int) $p['id'], $ctx['user']);
        if ($conv === null) {
            json_out(['ok' => false, 'error' => 'This conversation is not available.'], 404);
        }
        \Site\Messages::pollOut($ctx, $conv, true);
    }

    /** Shared "read" marker of a Team inbox thread (any team admin). */
    public static function teamRead(array $p): void
    {
        csrf_check();
        $ctx = self::teamCtx();
        $conv = self::teamConversation($ctx['user'], (int) $p['id']);
        $upTo = input_int('up_to');
        msg_team_mark_read((int) $conv['id'], $upTo > 0 ? $upTo : null);
        if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            json_out(['ok' => true]);
        }
        redirect('/admin/messages/team/' . (int) $conv['id']);
    }

    // ---- Reported messages ------------------------------------------------------------------

    public static function reports(array $p): void
    {
        require_permission('messages');
        $tab = query_param('tab') === 'resolved' ? 'resolved' : 'open';
        render('messages/reports', [
            'extra_css' => ['assets/css/messages.css'], 'title' => 'Reported messages', 'tab' => $tab, 'rows' => msg_reports_list($tab === 'open'),
            'open_count' => msg_reports_open_count(),
        ], 'admin');
    }

    /** The reported message and its context. Every view is audit-logged (admins never read private conversations otherwise). */
    public static function report(array $p): void
    {
        $user = require_permission('messages');
        $data = msg_report_context((int) $p['id']);
        if ($data === null) {
            abort(404);
        }
        audit('message_report_view', 'message', (int) $p['id'], 'read a reported message with ' . (count($data['rows']) - 1) . ' messages of context');
        $data['messages'] = array_map(static fn (array $m): array => msg_present($m, $user, $data['names'], false) + ['reported' => (int) $m['id'] === (int) $p['id']], $data['rows']);
        $data['sender'] = $data['names'][(int) ($data['message']['sender_id'] ?? 0)] ?? null;
        $data['sender_member_id'] = $data['sender'] !== null ? (int) q_val('SELECT id FROM members WHERE user_id = ?', [$data['sender']['id']]) : 0;
        render('messages/report', $data + ['extra_css' => ['assets/css/messages.css'], 'title' => 'Reported message', 'user' => $user, 'open' => q_val('SELECT 1 FROM message_reports WHERE message_id = ? AND resolved_at IS NULL LIMIT 1', [$p['id']]) !== null], 'admin');
    }

    public static function resolve(array $p): void
    {
        csrf_check();
        $user = require_permission('messages');
        $action = input('action');
        $ok = msg_report_resolve((int) $p['id'], $action, (int) $user['id']);
        if ($ok) {
            audit($action === 'remove' ? 'message_remove' : 'message_report_keep', 'message', (int) $p['id'], $action === 'remove' ? 'reported message removed' : 'reports dismissed, message kept');
        }
        flash($ok ? ($action === 'remove' ? 'Message removed. The conversation shows “Message removed by a moderator”.' : 'Reports dismissed; the message stays.') : 'Nothing changed.', $ok ? 'success' : 'error');
        redirect('/admin/messages/reports');
    }

    // ---- Settings (super admin) --------------------------------------------------------------

    public static function settings(array $p): void
    {
        require_permission('super_admin');
        $old = take_old_input();
        render('messages/settings', [
            'title' => 'Message settings', 's' => msg_settings(), 'errors' => $old['errors'],
            'staff' => q_all("SELECT id, display_name, email, role FROM users WHERE role IN ('super_admin','admin') AND status = 'active' ORDER BY display_name"),
            'team_staff' => msg_accounts(msg_team_staff_ids()),
        ], 'admin');
    }

    public static function saveSettings(array $p): void
    {
        csrf_check();
        require_permission('super_admin');
        $months = input('retention_months') === '' ? 0 : input_int('retention_months', -1);
        if ($months < 0 || $months > 120) {
            remember_input($_POST, ['retention_months' => 'Enter a number of months from 1 to 120, or leave it empty to keep messages forever.']);
            flash('Please check the highlighted field.', 'error');
            redirect('/admin/settings/messages');
        }
        $valid = array_map('intval', array_column(q_all("SELECT id FROM users WHERE role IN ('super_admin','admin') AND status = 'active'"), 'id'));
        $admins = array_values(array_intersect(array_map('intval', array_filter((array) ($_POST['team_admins'] ?? []), 'is_string')), $valid));
        $m2m = input('member_to_member') === '1';
        $team = input('team_inbox') === '1';
        setting_set('messages.member_to_member', $m2m);
        setting_set('messages.team_inbox', $team);
        setting_set('messages.team_admins', $admins);
        setting_set('messages.retention_months', $months);
        audit('update', 'settings', null, sprintf('message settings: member to member %s, team inbox %s, %d listed admins, keep %s',
            $m2m ? 'on' : 'off', $team ? 'on' : 'off', count($admins), $months > 0 ? $months . ' months' : 'forever'));
        flash('Message settings saved.');
        redirect('/admin/settings/messages');
    }
}
