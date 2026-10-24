<?php
declare(strict_types=1);

/*
 * Users & roles (§7, super admin): staff accounts, their role and the 8
 * permission keys. Rule (§2): at least one ACTIVE super admin must always
 * exist. Every change that could remove the last one goes through
 * staff_apply_change(), which locks the super admin rows first so two
 * super admins demoting each other at the same time cannot both succeed.
 */

const STAFF_ROLES = ['super_admin' => 'Super admin', 'admin' => 'Admin'];
const STAFF_INVITE_HOURS = 72;

final class LastSuperAdminException extends DomainException
{
    public function __construct()
    {
        parent::__construct('There must always be at least one active super admin. Make someone else a super admin first.');
    }
}

/** @return list<array> staff rows with permissions, super admins first */
function staff_list(string $q = '', string $status = ''): array
{
    $where = ["u.role IN ('super_admin','admin')"];
    $params = [];
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $where[] = '(u.display_name LIKE ? OR u.email LIKE ?)';
        array_push($params, $like, $like);
    }
    if (in_array($status, ['active', 'suspended'], true)) {
        $where[] = 'u.status = ?';
        $params[] = $status;
    }
    $rows = q_all(
        "SELECT u.id, u.email, u.display_name, u.role, u.status, u.last_login_at, u.created_at,
                (SELECT GROUP_CONCAT(p.permission ORDER BY p.permission) FROM admin_permissions p WHERE p.user_id = u.id) AS perms
           FROM users u WHERE " . implode(' AND ', $where) . "
          ORDER BY u.role = 'super_admin' DESC, u.status = 'active' DESC, u.display_name",
        $params
    );
    foreach ($rows as &$r) {
        $r['permissions'] = $r['perms'] ? explode(',', (string) $r['perms']) : [];
        unset($r['perms']);
    }
    return $rows;
}

/** @return array<string,mixed>|null a staff account by id (null for members/groups) */
function staff_find(int $id): ?array
{
    $u = q_one("SELECT id, email, display_name, role, status, last_login_at, created_at FROM users WHERE id = ? AND role IN ('super_admin','admin')", [$id]);
    if ($u !== null) {
        $u['permissions'] = array_column(q_all('SELECT permission FROM admin_permissions WHERE user_id = ? ORDER BY permission', [$id]), 'permission');
    }
    return $u;
}

function active_super_admin_count(): int
{
    return (int) q_val("SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND status = 'active'");
}

/**
 * Would changing $userId to ($role, $status) leave no active super admin?
 * Call inside a transaction: the active super admin rows are locked.
 * @throws LastSuperAdminException
 */
function staff_guard_last_super_admin(int $userId, string $newRole, string $newStatus): void
{
    $ids = array_map('intval', array_column(
        q_all("SELECT id FROM users WHERE role = 'super_admin' AND status = 'active' FOR UPDATE"),
        'id'
    ));
    $remaining = array_values(array_diff($ids, [$userId]));
    $stillSuper = $newRole === 'super_admin' && $newStatus === 'active';
    if ($remaining === [] && !$stillSuper) {
        throw new LastSuperAdminException();
    }
}

/**
 * Validate the Users & roles form.
 * @return array{0:array<string,mixed>,1:array<string,string>} [clean data, errors]
 */
function staff_validate(array $in, ?array $existing): array
{
    $errors = [];
    $name = trim((string) ($in['display_name'] ?? ''));
    $email = strtolower(trim((string) ($in['email'] ?? '')));
    $role = (string) ($in['role'] ?? 'admin');
    $status = (string) ($in['status'] ?? 'active');
    $perms = array_values(array_filter((array) ($in['permissions'] ?? []), 'is_string'));
    if ($name === '' || mb_strlen($name) > 120) {
        $errors['display_name'] = 'Enter a name (up to 120 characters).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        $errors['email'] = 'Enter a valid email address.';
    } else {
        $other = q_one('SELECT id, role FROM users WHERE email = ? AND id <> ?', [$email, $existing['id'] ?? 0]);
        if ($other !== null) {
            $errors['email'] = in_array($other['role'], ['member', 'group'], true)
                ? 'That address belongs to a member or group account. Use a different address for the admin login.'
                : 'Another admin already uses that address.';
        }
    }
    if (!array_key_exists($role, STAFF_ROLES)) {
        $errors['role'] = 'Choose a role.';
    }
    if (!in_array($status, ['active', 'suspended'], true)) {
        $errors['status'] = 'Choose a status.';
    }
    foreach ($perms as $p) {
        if (!array_key_exists($p, admin_permissions())) {
            $errors['permissions'] = 'Unknown permission.';
        }
    }
    if ($role === 'admin' && $perms === [] && !isset($errors['permissions'])) {
        $errors['permissions'] = 'Tick at least one area this admin may manage (or make them a super admin).';
    }
    $password = (string) ($in['password'] ?? '');
    if ($existing === null && ($in['password_mode'] ?? 'invite') === 'set') {
        if (($problem = password_problem($password)) !== null) {
            $errors['password'] = $problem;
        }
    }
    return [[
        'display_name' => $name,
        'email' => $email,
        'role' => $role,
        'status' => $status,
        // Super admins hold every permission implicitly; stored rows only matter for admins.
        'permissions' => $role === 'admin' ? array_values(array_unique($perms)) : [],
        'password' => $password,
    ], $errors];
}

/**
 * Create a staff account. Without a password an invite link (72 h) is emailed.
 * @return int new user id
 */
function staff_create(array $data, bool $sendInvite): int
{
    $password = $sendInvite ? bin2hex(random_bytes(24)) : (string) $data['password'];
    $id = create_user($data['email'], $password, $data['role'], $data['display_name'], 'active', $data['permissions']);
    if ($sendInvite) {
        $token = create_password_reset($id, STAFF_INVITE_HOURS);
        send_template_mail($data['email'], explode(' ', $data['display_name'])[0], 'admin_invite', [
            'reset_url' => base_url('/reset/' . $token),
            'role_label' => STAFF_ROLES[$data['role']],
            'hours' => (string) STAFF_INVITE_HOURS,
        ]);
    }
    return $id;
}

/**
 * Apply role / status / permission / identity changes to a staff account,
 * enforcing the last-super-admin rule.
 * @param array{display_name?:string,email?:string,role?:string,status?:string,permissions?:list<string>} $changes
 * @throws LastSuperAdminException
 */
function staff_apply_change(int $userId, array $changes): void
{
    db_tx(static function () use ($userId, $changes): void {
        $u = q_one("SELECT id, role, status FROM users WHERE id = ? AND role IN ('super_admin','admin') FOR UPDATE", [$userId]);
        if ($u === null) {
            throw new InvalidArgumentException('Not a staff account');
        }
        $role = $changes['role'] ?? (string) $u['role'];
        $status = $changes['status'] ?? (string) $u['status'];
        if (!array_key_exists($role, STAFF_ROLES) || !in_array($status, ['active', 'suspended'], true)) {
            throw new InvalidArgumentException('Bad role or status');
        }
        if ($u['role'] === 'super_admin' && $u['status'] === 'active') {
            staff_guard_last_super_admin($userId, $role, $status);
        }
        $row = ['role' => $role, 'status' => $status];
        foreach (['display_name', 'email'] as $k) {
            if (isset($changes[$k])) {
                $row[$k] = $changes[$k];
            }
        }
        db_update('users', $userId, $row);
        if (array_key_exists('permissions', $changes) || $role === 'super_admin') {
            set_user_permissions($userId, $role === 'admin' ? ($changes['permissions'] ?? []) : []);
        }
    });
}

/** Short human summary of what changed, for the audit log. */
function staff_change_summary(array $before, array $after): string
{
    $parts = [];
    if (($after['role'] ?? $before['role']) !== $before['role']) {
        $parts[] = 'role ' . $before['role'] . ' -> ' . $after['role'];
    }
    if (($after['status'] ?? $before['status']) !== $before['status']) {
        $parts[] = 'status ' . $before['status'] . ' -> ' . $after['status'];
    }
    if (isset($after['email']) && $after['email'] !== $before['email']) {
        $parts[] = 'email changed';
    }
    if (isset($after['permissions'])) {
        $added = array_diff($after['permissions'], $before['permissions']);
        $removed = array_diff($before['permissions'], $after['permissions']);
        if ($added) {
            $parts[] = '+' . implode(', +', $added);
        }
        if ($removed) {
            $parts[] = '-' . implode(', -', $removed);
        }
    }
    return implode('; ', $parts);
}
