<?php
declare(strict_types=1);

namespace Admin;

/** Contact-form inbox with replies by email (§5, F14). */
final class Inbox
{
    private static function find(int $id): array
    {
        $m = q_one('SELECT * FROM contact_messages WHERE id = ?', [$id]);
        if ($m === null) {
            abort(404);
        }
        return $m;
    }

    public static function index(array $p): void
    {
        require_permission('communications');
        $filter = query_param('show');
        $filter = in_array($filter, ['unread', 'replied'], true) ? $filter : '';
        $where = match ($filter) { 'unread' => 'WHERE read_at IS NULL', 'replied' => 'WHERE replied_at IS NOT NULL', default => '' };
        $q = mb_substr(query_param('q'), 0, 100);
        $params = [];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where .= ($where === '' ? 'WHERE ' : ' AND ') . '(name LIKE ? OR email LIKE ? OR subject LIKE ? OR body LIKE ?)';
            $params = [$like, $like, $like, $like];
        }
        $pg = paging(30);
        $rows = q_all("SELECT * FROM contact_messages $where ORDER BY created_at DESC, id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);
        $total = (int) q_val("SELECT COUNT(*) FROM contact_messages $where", $params);
        $counts = q_one('SELECT COUNT(*) AS total, SUM(read_at IS NULL) AS unread, SUM(replied_at IS NOT NULL) AS replied FROM contact_messages');
        render('admin/inbox_index', ['title' => 'Inbox', 'rows' => $rows, 'filter' => $filter, 'q' => $q, 'total' => $total, 'counts' => $counts, 'page' => $pg['page'], 'per' => $pg['per']], 'admin');
    }

    public static function show(array $p): void
    {
        $user = require_permission('communications');
        $m = self::find((int) $p['id']);
        $old = take_old_input();
        $replies = q_all('SELECT r.*, u.display_name FROM contact_replies r LEFT JOIN users u ON u.id = r.user_id WHERE r.message_id = ? ORDER BY r.created_at', [$m['id']]);
        render('admin/inbox_show', ['title' => $m['subject'], 'm' => $m, 'replies' => $replies, 'values' => $old['values'], 'errors' => $old['errors'], 'user' => $user], 'admin');
    }

    /** POST: mark read/unread (the message page marks itself read through this with JavaScript). */
    public static function read(array $p): void
    {
        csrf_check();
        require_permission('communications');
        $m = self::find((int) $p['id']);
        $unread = input('unread') === '1';
        q('UPDATE contact_messages SET read_at = ' . ($unread ? 'NULL' : 'COALESCE(read_at, UTC_TIMESTAMP())') . ' WHERE id = ?', [$m['id']]);
        if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            json_out(['ok' => true]);
        }
        flash($unread ? 'Marked as unread.' : 'Marked as read.');
        redirect($unread ? '/admin/inbox' : '/admin/inbox/' . $m['id']);
    }

    public static function reply(array $p): void
    {
        csrf_check();
        $user = require_permission('communications');
        $m = self::find((int) $p['id']);
        $subject = input('subject');
        $body = trim(str_replace("\r\n", "\n", is_string($_POST['body'] ?? null) ? $_POST['body'] : ''));
        $errors = [];
        if ($subject === '' || mb_strlen($subject) > 200 || preg_match('/[\r\n]/', $subject)) {
            $errors['subject'] = 'Enter a subject (one line, up to 200 characters).';
        }
        if ($body === '' || mb_strlen($body) > 10000) {
            $errors['body'] = 'Write your reply (up to 10,000 characters).';
        }
        if ($errors) {
            remember_input(['subject' => $subject, 'body' => $body], $errors);
            redirect('/admin/inbox/' . $m['id'] . '#reply');
        }
        $orgEmail = (string) setting('contact.email', '');
        $parts = email_template_render('contact_reply', [
            'first_name' => $m['name'], 'reply' => $body, 'original' => $m['body'], 'sender' => (string) $user['display_name'], 'subject' => preg_replace('/^(Re:\s*)+/i', '', $subject),
        ]);
        try {
            $msg = new \Core\Mail\Message((string) $m['email'], (string) $m['name'], $subject, $parts['text'], $parts['html'], filter_var($orgEmail, FILTER_VALIDATE_EMAIL) ? $orgEmail : null);
            $ok = mail_send_now($msg, 'reply');
        } catch (\InvalidArgumentException) {
            $ok = false;
        }
        db_insert('contact_replies', ['message_id' => $m['id'], 'user_id' => $user['id'], 'subject' => $subject, 'body' => $body, 'sent_ok' => $ok ? 1 : 0]);
        q('UPDATE contact_messages SET replied_at = UTC_TIMESTAMP(), read_at = COALESCE(read_at, UTC_TIMESTAMP()) WHERE id = ?', [$m['id']]);
        audit('reply', 'contact_message', (int) $m['id'], (string) $m['email']);
        flash($ok ? 'Reply sent to ' . $m['email'] . '.' : 'The reply is saved but could not be sent right away; it will be retried automatically.', $ok ? 'success' : 'info');
        redirect('/admin/inbox/' . $m['id']);
    }

    public static function delete(array $p): void
    {
        csrf_check();
        require_permission('communications');
        $m = self::find((int) $p['id']);
        q('DELETE FROM contact_messages WHERE id = ?', [$m['id']]);
        audit('delete', 'contact_message', (int) $m['id'], (string) $m['email']);
        flash('Message deleted.');
        redirect('/admin/inbox');
    }
}
