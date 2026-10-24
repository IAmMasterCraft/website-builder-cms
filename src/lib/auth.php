<?php
declare(strict_types=1);

use Core\HttpException;

const ROLES = ['super_admin', 'admin', 'member', 'group'];
const LOGIN_MAX_FAILURES = 10;
const LOGIN_LOCK_SECONDS = 900;

/** Admin permission keys (§2) => label. */
function admin_permissions(): array
{
    return [
        'content'        => 'Content (posts, downloads, leaders, awards, FAQ, branches)',
        'pages_menus'    => 'Pages and menus',
        'members'        => 'Members and groups',
        'communications' => 'Communications',
        'radio'          => 'Radio',
        'events'         => 'Events',
        'jobs'           => 'Jobs',
        'gallery_media'  => 'Gallery and media',
    ];
}

function hash_password(string $password): string
{
    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    return password_hash($password, $algo);
}

function password_problem(string $password): ?string
{
    if (mb_strlen($password) < 10) {
        return 'Use at least 10 characters.';
    }
    if (mb_strlen($password) > 200) {
        return 'Use at most 200 characters.';
    }
    return null;
}

/** The logged-in user row (with permissions), or null. Pass false to clear the cache. */
function current_user(bool $refresh = false): ?array
{
    static $cache = [];
    $id = isset($_SESSION['user_id']) && is_int($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
    if ($id === null) {
        return null;
    }
    if ($refresh || !array_key_exists($id, $cache)) {
        $user = q_one('SELECT id, email, role, status, display_name, last_login_at FROM users WHERE id = ?', [$id]);
        if ($user !== null) {
            $user['permissions'] = array_column(q_all('SELECT permission FROM admin_permissions WHERE user_id = ?', [$id]), 'permission');
        }
        $cache = [$id => $user];
    }
    $user = $cache[$id];
    if ($user === null || $user['status'] === 'suspended') {
        unset($_SESSION['user_id']);
        return null;
    }
    return $user;
}

function is_staff(?array $user): bool
{
    return $user !== null && in_array($user['role'], ['super_admin', 'admin'], true) && $user['status'] === 'active';
}

/** Super admins have every permission; admins only what was granted. */
function user_can(?array $user, string $permission): bool
{
    if ($user === null || $user['status'] !== 'active') {
        return false;
    }
    if ($user['role'] === 'super_admin') {
        return true;
    }
    if ($permission === 'super_admin') {
        return false;
    }
    return $user['role'] === 'admin' && in_array($permission, $user['permissions'] ?? [], true);
}

/** Active account of any role: may see members-only content. */
function viewer_is_member(?array $user = null): bool
{
    $user ??= current_user();
    return $user !== null && $user['status'] === 'active';
}

/** Logged-out visitors are sent to /login and come back afterwards. */
function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        $next = request_path();
        redirect('/login' . ($next !== '/' ? '?next=' . rawurlencode($next) : ''));
    }
    return $user;
}

/** Any /admin page: staff only (403 for members and groups). */
function require_staff(): array
{
    $user = require_login();
    if (!is_staff($user)) {
        throw new HttpException(403);
    }
    return $user;
}

/** An admin page guarded by one permission key (or 'super_admin'). */
function require_permission(string $permission): array
{
    $user = require_staff();
    if (!user_can($user, $permission)) {
        throw new HttpException(403);
    }
    return $user;
}

function login_locked(string $email): bool
{
    $since = gmdate('Y-m-d H:i:s', time() - LOGIN_LOCK_SECONDS);
    $n = (int) q_val(
        'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND email = ? AND attempted_at > ?',
        [client_ip(), strtolower($email), $since]
    );
    return $n >= LOGIN_MAX_FAILURES;
}

/**
 * Check credentials. Returns the user id, or throws 429 when locked out.
 * Unknown email and wrong password fail the same way (and take the same time).
 */
function attempt_login(string $email, string $password): ?int
{
    $email = strtolower(trim($email));
    if (login_locked($email)) {
        throw new HttpException(429);
    }
    $user = q_one('SELECT id, password_hash, status FROM users WHERE email = ?', [$email]);
    static $dummy = null;
    $hash = $user['password_hash'] ?? ($dummy ??= password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT));
    $ok = password_verify($password, $hash) && $user !== null && $user['status'] !== 'suspended';
    if (!$ok) {
        q('INSERT INTO login_attempts (ip, email, attempted_at) VALUES (?, ?, UTC_TIMESTAMP())', [client_ip(), mb_substr($email, 0, 190)]);
        return null;
    }
    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    if (password_needs_rehash($hash, $algo)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [hash_password($password), $user['id']]);
    }
    q('DELETE FROM login_attempts WHERE ip = ? AND email = ?', [client_ip(), $email]);
    return (int) $user['id'];
}

function login_user(int $userId): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['user_id'] = $userId;
    unset($_SESSION['csrf']);
    q('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$userId]);
    current_user(true);
}

function logout_user(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
        setcookie(session_name(), '', ['expires' => 1, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    }
}

/** Where a user lands after login, by role. */
function home_for(array $user): string
{
    return is_staff($user) ? '/admin' : '/account';
}

/** @param list<string> $permissions */
function create_user(string $email, string $password, string $role, string $displayName, string $status = 'active', array $permissions = []): int
{
    if (!in_array($role, ROLES, true)) {
        throw new InvalidArgumentException('Unknown role');
    }
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Invalid email address');
    }
    if (($p = password_problem($password)) !== null) {
        throw new InvalidArgumentException($p);
    }
    return db_tx(static function () use ($email, $password, $role, $displayName, $status, $permissions): int {
        $id = db_insert('users', [
            'email' => $email,
            'password_hash' => hash_password($password),
            'role' => $role,
            'status' => $status,
            'display_name' => $displayName,
        ]);
        set_user_permissions($id, $permissions);
        return $id;
    });
}

/** @param list<string> $permissions */
function set_user_permissions(int $userId, array $permissions): void
{
    q('DELETE FROM admin_permissions WHERE user_id = ?', [$userId]);
    foreach (array_unique($permissions) as $p) {
        if (!array_key_exists($p, admin_permissions())) {
            throw new InvalidArgumentException('Unknown permission ' . $p);
        }
        q('INSERT INTO admin_permissions (user_id, permission) VALUES (?, ?)', [$userId, $p]);
    }
}

/** Create a reset token (returns the raw token for the email link). */
function create_password_reset(int $userId, int $hours = 1): string
{
    $token = bin2hex(random_bytes(32));
    q('DELETE FROM password_resets WHERE user_id = ? OR expires_at < UTC_TIMESTAMP()', [$userId]);
    q(
        'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL ' . max(1, min(168, $hours)) . ' HOUR)',
        [$userId, hash('sha256', $token)]
    );
    return $token;
}

/** User id for a valid, unused, unexpired token. */
function find_password_reset(string $token): ?int
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $id = q_val(
        'SELECT user_id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()',
        [hash('sha256', $token)]
    );
    return $id === null ? null : (int) $id;
}

function complete_password_reset(string $token, string $password): bool
{
    $userId = find_password_reset($token);
    if ($userId === null || password_problem($password) !== null) {
        return false;
    }
    db_tx(static function () use ($userId, $password, $token): void {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [hash_password($password), $userId]);
        q('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE token_hash = ?', [hash('sha256', $token)]);
        q('DELETE FROM login_attempts WHERE email = (SELECT email FROM users WHERE id = ?)', [$userId]);
    });
    return true;
}

function audit(string $action, string $itemType = '', ?int $itemId = null, string $summary = ''): void
{
    $user = current_user();
    q(
        'INSERT INTO audit_log (user_id, action, item_type, item_id, summary, ip) VALUES (?, ?, ?, ?, ?, ?)',
        [$user['id'] ?? null, $action, $itemType, $itemId, mb_substr($summary, 0, 250), client_ip()]
    );
}
