<?php
declare(strict_types=1);

use Core\HttpException;

/*
 * "Log in as this member" (Addendum B2).
 *
 * While impersonating, the session's user is the member, and the admin's
 * identity is kept separately in $_SESSION['imp'] (admin id and the admin's
 * session version at the start). Every request passes impersonation_guard()
 * in the front controller, the one place that:
 *   - ends the impersonation after 30 minutes (hard limit, not sliding),
 *   - logs everyone out if the admin's own session was revoked meanwhile
 *     (session version changed, suspended, no longer staff),
 *   - returns to the admin when the impersonate permission was removed or the
 *     member account disappeared,
 *   - refuses everything a support visit must not do (default deny for POST).
 * The session id is regenerated at the start and the end.
 */

const IMPERSONATION_SECONDS = 1800;

/** The active impersonation, or null. */
function impersonation(): ?array
{
    $imp = $_SESSION['imp'] ?? null;
    if (!is_array($imp) || !is_int($imp['admin_id'] ?? null) || !is_int($imp['member_id'] ?? null) || !is_int($imp['expires'] ?? null)) {
        return null;
    }
    return $imp;
}

function is_impersonating(): bool
{
    return impersonation() !== null;
}

/** Account row of a possible target (member or group), with display details. */
function impersonation_target(int $userId): ?array
{
    $u = q_one('SELECT id, email, role, status, display_name, session_version FROM users WHERE id = ?', [$userId]);
    if ($u === null) {
        return null;
    }
    $u['number'] = '';
    $u['admin_url'] = null;
    $u['first_name'] = (string) $u['display_name'];
    if ($u['role'] === 'member' && ($m = member_for_user($userId)) !== null) {
        $u['name'] = member_full_name($m);
        $u['first_name'] = (string) $m['first_name'];
        $u['number'] = (string) ($m['member_number'] ?? '');
        $u['admin_url'] = '/admin/members/' . (int) $m['id'];
    } elseif ($u['role'] === 'group' && ($g = q_one('SELECT id, name, group_number, contact_person FROM groups_orgs WHERE user_id = ?', [$userId])) !== null) {
        $u['name'] = (string) $g['name'];
        $u['first_name'] = (string) ($g['contact_person'] ?: $g['name']);
        $u['number'] = (string) ($g['group_number'] ?? '');
        $u['admin_url'] = '/admin/groups/' . (int) $g['id'] . '/edit';
    } else {
        $u['name'] = (string) $u['display_name'];
    }
    return $u;
}

/** Why this account can never be impersonated (null = it can). */
function impersonation_refusal(?array $target): ?string
{
    if ($target === null) {
        return 'There is no account with this number.';
    }
    if (!in_array($target['role'], ['member', 'group'], true)) {
        return 'Staff accounts cannot be viewed as a member. Only member and group accounts can.';
    }
    return null;
}

/** Warnings the admin must confirm before starting (suspended / unverified accounts). */
function impersonation_warnings(array $target): array
{
    return match ($target['status']) {
        'suspended' => ['This account is suspended: the member cannot log in themselves. Only continue if you need to check something for them.'],
        'unverified' => ['This member has not verified their email yet, so they have no member number or card. You will see the “check your email” screen they see.'],
        default => [],
    };
}

/**
 * Start viewing as a member. The caller has checked CSRF and the
 * impersonate permission. Throws DomainException with a message for the admin.
 */
function impersonation_start(array $admin, int $targetUserId, string $reason, bool $allowChanges, bool $confirmed): array
{
    if (is_impersonating()) {
        throw new DomainException('You are already viewing the site as a member. Return to admin first.');
    }
    if (!user_can($admin, 'impersonate')) {
        throw new HttpException(403);
    }
    $target = impersonation_target($targetUserId);
    if (($refusal = impersonation_refusal($target)) !== null) {
        throw new DomainException($refusal);
    }
    assert($target !== null);
    if (impersonation_warnings($target) !== [] && !$confirmed) {
        throw new DomainException('Please tick the box to confirm you want to view this account.');
    }
    $reason = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $reason)), 0, 200);
    $now = time();
    $rowId = db_insert('impersonations', [
        'admin_id' => $admin['id'],
        'user_id' => $target['id'],
        'reason' => $reason,
        'allow_changes' => $allowChanges ? 1 : 0,
        'ip' => client_ip(),
        'started_at' => gmdate('Y-m-d H:i:s', $now),
        'expires_at' => gmdate('Y-m-d H:i:s', $now + IMPERSONATION_SECONDS),
    ]);
    audit('impersonate_start', $target['role'], (int) $target['id'], mb_substr(
        'Viewing as ' . $target['name'] . ($target['number'] !== '' ? ' (' . $target['number'] . ')' : '')
        . ($allowChanges ? '; changes allowed' : '; read only') . ($reason !== '' ? '; reason: ' . $reason : ''),
        0,
        250
    ));
    $returnTo = $target['admin_url'] !== null && user_can($admin, 'members') ? $target['admin_url'] : '/admin';
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['imp'] = [
        'id' => $rowId,
        'admin_id' => (int) $admin['id'],
        'admin_sv' => (int) ($_SESSION['sv'] ?? -1),
        'member_id' => (int) $target['id'],
        'started' => $now,
        'expires' => $now + IMPERSONATION_SECONDS,
        'allow_changes' => $allowChanges,
        'return_to' => $returnTo,
        'name' => (string) $target['name'],
        'number' => (string) $target['number'],
    ];
    $_SESSION['user_id'] = (int) $target['id'];
    $_SESSION['sv'] = (int) $target['session_version'];
    unset($_SESSION['csrf']);
    current_user(true);
    if ((bool) setting('impersonation.notify', true)) {
        send_template_mail((string) $target['email'], (string) $target['first_name'], 'impersonation_notice', [
            'date' => fmt_datetime(gmdate('Y-m-d H:i:s', $now)),
            'admin_name' => (string) $admin['display_name'],
            'account_url' => base_url('/account'),
        ]);
    }
    return $target;
}

/**
 * End the impersonation. With $restore the admin's own session continues
 * (when it is still valid); otherwise, or when it is not, everyone is
 * logged out. Returns where to send the browser.
 */
function impersonation_end(string $reason, bool $restore = true): string
{
    $imp = impersonation();
    if ($imp === null) {
        return '/';
    }
    $minutes = max(0, (int) round((time() - (int) $imp['started']) / 60));
    q('UPDATE impersonations SET ended_at = UTC_TIMESTAMP(), end_reason = ? WHERE id = ? AND ended_at IS NULL', [$reason, (int) ($imp['id'] ?? 0)]);
    $admin = q_one('SELECT id, role, status, session_version FROM users WHERE id = ?', [$imp['admin_id']]);
    $adminValid = $admin !== null && in_array($admin['role'], ['super_admin', 'admin'], true) && $admin['status'] === 'active'
        && (int) $admin['session_version'] === (int) $imp['admin_sv'];
    $labels = [
        'returned' => 'returned to admin', 'expired' => 'ended automatically after 30 minutes', 'logout' => 'logged out',
        'admin_session_ended' => 'the admin session had ended', 'permission_removed' => 'the permission was removed',
        'member_unavailable' => 'the account changed or was removed',
    ];
    $summary = 'Stopped viewing as ' . $imp['name'] . ($imp['number'] !== '' ? ' (' . $imp['number'] . ')' : '') . ' after ' . $minutes . ' min: ' . ($labels[$reason] ?? $reason);
    audit_as($imp['admin_id'], null, 'impersonate_end', 'member', $imp['member_id'], $summary);
    if (!$restore || !$adminValid) {
        logout_user();
        if (session_status() !== PHP_SESSION_ACTIVE && PHP_SAPI !== 'cli') {
            session_start();
        }
        return '/login';
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    unset($_SESSION['imp'], $_SESSION['csrf'], $_SESSION['old']);
    $_SESSION['user_id'] = (int) $imp['admin_id'];
    $_SESSION['sv'] = (int) $imp['admin_sv'];
    current_user(true);
    return (string) $imp['return_to'];
}

/** POST routes that work while impersonating only when the admin ticked "allow changes". */
function impersonation_change_routes(): array
{
    return [
        '#^/account/profile$#' => 'edit the profile',
        '#^/account/photo$#' => 'change the photo',
        '#^/account/events/\d+/cancel$#' => 'cancel an event registration',
        '#^/events/[a-z0-9][a-z0-9-]*/register$#' => 'register for an event',
    ];
}

/** Pages that stay closed while impersonating, even for reading. */
function impersonation_blocked_pages(): array
{
    return [
        '#^/admin(/|$)#' => 'The admin area is closed while you view the site as a member.',
        '#^/account/privacy/export$#' => 'Downloading the member’s data is not available while you view the site as them.',
        '#^/account/jobs/(new|\d+/edit)$#' => 'Posting or editing jobs as the member is not available.',
        '#^/account/email/confirm/#' => 'Confirming email changes is not available while you view the site as a member.',
        '#^/(join|reset|forgot-password)(/|$)#' => 'This page is not available while you view the site as a member.',
    ];
}

/**
 * Called for every request before routing. Ends a stale impersonation and
 * refuses restricted actions with a clear message (HttpException 403).
 */
function impersonation_guard(string $method, string $path): void
{
    $imp = impersonation();
    if ($imp === null) {
        return;
    }
    // The admin's own session must still be valid, or nobody stays logged in.
    $admin = q_one('SELECT id, role, status, session_version FROM users WHERE id = ?', [$imp['admin_id']]);
    if ($admin === null || !in_array($admin['role'], ['super_admin', 'admin'], true) || $admin['status'] !== 'active' || (int) $admin['session_version'] !== (int) $imp['admin_sv']) {
        $to = impersonation_end('admin_session_ended', false);
        flash('You have been logged out: your admin session ended while you were viewing the site as a member.', 'info');
        redirect($to);
    }
    if (time() >= (int) $imp['expires']) {
        $to = impersonation_end('expired');
        flash('Viewing as ' . $imp['name'] . ' ended automatically after 30 minutes.', 'info');
        redirect($to);
    }
    $adminUser = $admin + ['permissions' => array_column(q_all('SELECT permission FROM admin_permissions WHERE user_id = ?', [$admin['id']]), 'permission')];
    if (!user_can($adminUser, 'impersonate')) {
        $to = impersonation_end('permission_removed');
        flash('Viewing as a member ended: your account no longer has that permission.', 'info');
        redirect(str_starts_with($to, '/admin/members') ? '/admin' : $to);
    }
    $current = current_user();
    if ($current === null || $current['id'] !== $imp['member_id'] || !in_array($current['role'], ['member', 'group'], true)) {
        $to = impersonation_end('member_unavailable');
        flash('Viewing as a member ended: the account changed or was removed.', 'info');
        redirect($to);
    }
    if ($method === 'POST' && in_array($path, ['/impersonation/stop', '/logout'], true)) {
        return;
    }
    foreach (impersonation_blocked_pages() as $re => $message) {
        if (preg_match($re, $path)) {
            throw new HttpException(403, $message . ' Use “Return to admin” first.');
        }
    }
    if ($method !== 'GET' && $method !== 'HEAD') {
        foreach (impersonation_change_routes() as $re => $what) {
            if (preg_match($re, $path)) {
                if (!empty($imp['allow_changes'])) {
                    return;
                }
                throw new HttpException(403, 'You are viewing the site as ' . $imp['name'] . ' in read-only mode, so you cannot ' . $what . '. To make changes for the member, return to admin and start again with “Allow changes” ticked.');
            }
        }
        // Default deny: password, email, deletion, export, consent and
        // unsubscribes, likes, comments, reports, jobs, contact form...
        throw new HttpException(403, 'This is not available while you view the site as ' . $imp['name'] . ': changing the password or email, deleting the account, email preferences and consent, likes, comments, reports and job posts are blocked. Use “Return to admin” first.');
    }
}

/** What the banner shows, or null when not impersonating. */
function impersonation_banner(): ?array
{
    $imp = impersonation();
    if ($imp === null) {
        return null;
    }
    $ends = (new DateTimeImmutable('@' . $imp['expires']))->setTimezone(site_tz());
    return [
        'name' => (string) $imp['name'],
        'number' => (string) $imp['number'],
        'ends' => $ends->format('H:i'),
        'ends_iso' => $ends->format(DATE_ATOM),
        'allow_changes' => !empty($imp['allow_changes']),
    ];
}
