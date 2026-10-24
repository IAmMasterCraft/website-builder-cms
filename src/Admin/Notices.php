<?php
declare(strict_types=1);

namespace Admin;

/** Dashboard notices to audiences with an optional email copy (§5). */
final class Notices
{
    private static function find(int $id): array
    {
        $n = q_one('SELECT * FROM notices WHERE id = ?', [$id]);
        if ($n === null) {
            abort(404);
        }
        return $n;
    }

    public static function index(array $p): void
    {
        require_permission('communications');
        $rows = q_all('SELECT n.*, u.display_name AS author, (SELECT COUNT(*) FROM notice_reads r WHERE r.notice_id = n.id) AS read_count FROM notices n LEFT JOIN users u ON u.id = n.created_by ORDER BY COALESCE(n.published_at, n.created_at) DESC LIMIT 200');
        render('admin/notices_index', ['title' => 'Notices', 'rows' => $rows], 'admin');
    }

    public static function create(array $p): void
    {
        require_permission('communications');
        $old = take_old_input();
        self::form(null, $old['values'] + ['title' => '', 'body' => '', 'email_copy' => '0'], $old['errors']);
    }

    public static function edit(array $p): void
    {
        require_permission('communications');
        $n = self::find((int) $p['id']);
        $old = take_old_input();
        self::form($n, $old['values'] + ['title' => $n['title'], 'body' => (string) $n['body'], 'email_copy' => (string) $n['email_copy']], $old['errors']);
    }

    private static function form(?array $n, array $values, array $errors): void
    {
        $aud = isset($values['audience_type']) ? audience_validate($values)[0] : ($n !== null ? audience_decode($n['audience']) : audience_default());
        render('admin/notice_form', [
            'title' => $n ? 'Edit notice' : 'New notice', 'n' => $n, 'values' => $values, 'errors' => $errors, 'audience' => $aud,
            'choices' => audience_choices(), 'reach' => audience_resolve($aud, false), 'reach_email' => audience_resolve($aud, true),
            'reads' => $n ? notice_read_count((int) $n['id']) : 0,
        ], 'admin');
    }

    /** @return array{0:array,1:array} */
    private static function validate(): array
    {
        $v = ['title' => input('title'), 'body' => \Core\Sanitizer::clean(is_string($_POST['body'] ?? null) ? mb_substr($_POST['body'], 0, 50000) : ''), 'email_copy' => input('email_copy') === '1' ? '1' : '0'];
        [$aud, $errors] = audience_validate($_POST);
        if ($v['title'] === '' || mb_strlen($v['title']) > 200) {
            $errors['title'] = 'Enter a title (up to 200 characters).';
        }
        if (trim(strip_tags($v['body'])) === '') {
            $errors['body'] = 'Write the notice.';
        }
        return [$v + ['audience' => $aud], $errors];
    }

    public static function store(array $p): void
    {
        csrf_check();
        $user = require_permission('communications');
        [$v, $errors] = self::validate();
        if ($errors) {
            remember_input($_POST, $errors);
            redirect('/admin/notices/new');
        }
        $id = db_insert('notices', ['title' => $v['title'], 'body' => $v['body'], 'audience' => json_encode($v['audience'], JSON_THROW_ON_ERROR), 'email_copy' => (int) $v['email_copy'], 'created_by' => $user['id']]);
        self::afterSave($id, $v['title']);
    }

    public static function update(array $p): void
    {
        csrf_check();
        require_permission('communications');
        $n = self::find((int) $p['id']);
        [$v, $errors] = self::validate();
        if ($errors) {
            remember_input($_POST, $errors);
            redirect('/admin/notices/' . $n['id']);
        }
        db_update('notices', (int) $n['id'], ['title' => $v['title'], 'body' => $v['body'], 'audience' => json_encode($v['audience'], JSON_THROW_ON_ERROR), 'email_copy' => (int) $v['email_copy']]);
        self::afterSave((int) $n['id'], $v['title']);
    }

    private static function afterSave(int $id, string $title): void
    {
        if (input('action') === 'publish') {
            $queued = notice_publish($id);
            audit('publish', 'notice', $id, $title);
            flash('Notice published to dashboards' . ($queued > 0 ? ' and ' . pluralise($queued, 'email copy', 'email copies') . ' queued.' : '.'));
            redirect('/admin/notices');
        }
        if (input('action') === 'unpublish') {
            q("UPDATE notices SET status = 'draft' WHERE id = ?", [$id]);
            flash('Notice taken down from dashboards.');
            redirect('/admin/notices/' . $id);
        }
        audit('update', 'notice', $id, $title);
        flash('Notice saved.');
        redirect('/admin/notices/' . $id);
    }

    public static function delete(array $p): void
    {
        csrf_check();
        require_permission('communications');
        $n = self::find((int) $p['id']);
        q('DELETE FROM notices WHERE id = ?', [$n['id']]);
        audit('delete', 'notice', (int) $n['id'], $n['title']);
        flash('Notice deleted.');
        redirect('/admin/notices');
    }
}
