<?php
declare(strict_types=1);

namespace Admin;

/**
 * Users & roles (§7, super admin only): create admins, grant the 8
 * permission keys, promote/demote, suspend. No 2FA in v1 (D10).
 * The last active super admin can never be demoted or suspended
 * (staff_apply_change() enforces it; this screen also refuses changes to
 * your own role and status so nobody locks themselves out by accident).
 */
final class Users
{
    public static function index(array $p): void
    {
        $me = require_permission('super_admin');
        $q = mb_substr(query_param('q'), 0, 100);
        $status = query_param('status');
        render('admin/users_index', [
            'title' => 'Users and roles', 'rows' => staff_list($q, $status), 'q' => $q, 'status' => $status,
            'me' => $me, 'super_count' => active_super_admin_count(), 'perms' => admin_permissions(),
        ], 'admin');
    }

    public static function create(array $p): void
    {
        require_permission('super_admin');
        $old = take_old_input();
        $values = $old['values'] + ['display_name' => '', 'email' => '', 'role' => 'admin', 'status' => 'active', 'permissions' => [], 'password_mode' => 'invite'];
        self::form($values, $old['errors'], null, $old['errors'] ? 422 : 200);
    }

    public static function edit(array $p): void
    {
        require_permission('super_admin');
        $u = self::staff($p);
        $old = take_old_input();
        self::form($old['values'] + $u, $old['errors'], $u, $old['errors'] ? 422 : 200);
    }

    private static function form(array $values, array $errors, ?array $user, int $status = 200): void
    {
        $me = current_user();
        $recent = $user ? q_all('SELECT a.*, u.display_name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id WHERE a.user_id = ? ORDER BY a.id DESC LIMIT 8', [$user['id']]) : [];
        render('admin/user_form', [
            'title' => $user ? 'Edit ' . $user['display_name'] : 'New admin', 'values' => $values, 'errors' => $errors, 'user' => $user,
            'is_me' => $user !== null && $me !== null && (int) $me['id'] === (int) $user['id'],
            'perms' => admin_permissions(), 'roles' => STAFF_ROLES, 'recent' => $recent,
            'last_super' => $user !== null && $user['role'] === 'super_admin' && $user['status'] === 'active' && active_super_admin_count() <= 1,
        ], 'admin', $status);
    }

    public static function store(array $p): void
    {
        csrf_check();
        require_permission('super_admin');
        [$data, $errors] = staff_validate($_POST + ['permissions' => $_POST['permissions'] ?? []], null);
        if ($errors) {
            remember_input($_POST, $errors);
            redirect('/admin/users/new');
        }
        $invite = input('password_mode') !== 'set';
        $id = staff_create($data, $invite);
        audit('create', 'user', $id, $data['email'] . ' as ' . $data['role'] . ($data['permissions'] ? ' (' . implode(', ', $data['permissions']) . ')' : ''));
        flash($invite ? 'Admin created. An invitation to choose a password was emailed to ' . $data['email'] . '.' : 'Admin created.');
        redirect('/admin/users/' . $id);
    }

    public static function update(array $p): void
    {
        csrf_check();
        $me = require_permission('super_admin');
        $u = self::staff($p);
        $isMe = (int) $me['id'] === (int) $u['id'];
        $in = $_POST + ['permissions' => $_POST['permissions'] ?? []];
        if ($isMe) {
            // Your own role and status stay as they are (another super admin can change them).
            $in['role'] = $u['role'];
            $in['status'] = $u['status'];
        }
        [$data, $errors] = staff_validate($in, $u);
        if ($errors) {
            remember_input($_POST, $errors);
            redirect('/admin/users/' . $u['id']);
        }
        $changes = ['display_name' => $data['display_name'], 'email' => $data['email'], 'role' => $data['role'], 'status' => $data['status'], 'permissions' => $data['permissions']];
        try {
            staff_apply_change((int) $u['id'], $changes);
        } catch (\LastSuperAdminException $e) {
            remember_input($_POST, ['role' => $e->getMessage()]);
            flash($e->getMessage(), 'error');
            redirect('/admin/users/' . $u['id']);
        }
        session_keep_current((int) $u['id']);
        $summary = staff_change_summary($u, $changes);
        audit('update', 'user', (int) $u['id'], $u['email'] . ($summary !== '' ? ': ' . $summary : ''));
        flash('Saved.');
        redirect('/admin/users/' . $u['id']);
    }

    /** Quick suspend / reactivate from the list. */
    public static function status(array $p): void
    {
        csrf_check();
        $me = require_permission('super_admin');
        $u = self::staff($p);
        $status = input('status') === 'suspended' ? 'suspended' : 'active';
        if ((int) $me['id'] === (int) $u['id']) {
            flash('You cannot suspend your own account.', 'error');
            redirect('/admin/users');
        }
        try {
            staff_apply_change((int) $u['id'], ['status' => $status]);
        } catch (\LastSuperAdminException $e) {
            flash($e->getMessage(), 'error');
            redirect('/admin/users');
        }
        audit($status === 'suspended' ? 'suspend' : 'reactivate', 'user', (int) $u['id'], (string) $u['email']);
        flash($status === 'suspended' ? $u['display_name'] . ' is suspended and logged out.' : $u['display_name'] . ' can log in again.');
        redirect('/admin/users');
    }

    public static function resetLink(array $p): void
    {
        csrf_check();
        require_permission('super_admin');
        $u = self::staff($p);
        $token = create_password_reset((int) $u['id']);
        send_template_mail((string) $u['email'], explode(' ', (string) $u['display_name'])[0], 'password_reset', ['reset_url' => base_url('/reset/' . $token)]);
        audit('reset_link', 'user', (int) $u['id'], (string) $u['email']);
        flash('A password link was emailed to ' . $u['email'] . ' (valid for one hour).');
        redirect('/admin/users/' . $u['id']);
    }

    private static function staff(array $p): array
    {
        $u = staff_find((int) $p['id']);
        if ($u === null) {
            abort(404);
        }
        return $u;
    }
}
