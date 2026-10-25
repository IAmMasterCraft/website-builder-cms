<?php
declare(strict_types=1);

namespace Admin;

/**
 * "My profile" for every staff user (super admins and admins, whatever
 * their permissions): display name, login email (confirmed through a link
 * sent to the new address, like members; the old address is told) and
 * password (current password required, re-checks rate limited by
 * confirm_current_password(), other sessions ended). Every change is
 * audited; every POST checks the CSRF token first.
 */
final class Profile
{
    public static function edit(array $p): void
    {
        $user = require_staff();
        $old = take_old_input();
        $pending = q_val('SELECT pending_email FROM users WHERE id = ?', [$user['id']]);
        render('admin/profile', ['title' => 'My profile', 'me' => $user, 'errors' => $old['errors'], 'values' => $old['values'] + ['display_name' => $user['display_name']],
            'pending_email' => is_string($pending) ? $pending : null], 'admin');
    }

    /** POST /admin/profile: display name. */
    public static function update(array $p): void
    {
        csrf_check();
        $user = require_staff();
        $name = trim(preg_replace('/\s+/u', ' ', input('display_name')) ?? '');
        if ($name === '' || mb_strlen($name) > 120) {
            remember_input(['display_name' => $name], ['display_name' => 'Enter a name (up to 120 characters).']);
            redirect('/admin/profile');
        }
        if ($name !== $user['display_name']) {
            q('UPDATE users SET display_name = ? WHERE id = ?', [$name, $user['id']]);
            audit('profile_name', 'user', (int) $user['id'], 'own name: ' . $user['display_name'] . ' -> ' . $name);
            current_user(true);
        }
        flash('Your name is saved.');
        redirect('/admin/profile');
    }

    /** POST /admin/profile/email: ask to change the login email (current password required). */
    public static function email(array $p): void
    {
        csrf_check();
        $user = require_staff();
        $new = strtolower(trim(input('new_email')));
        $errors = [];
        if (!confirm_current_password((int) $user['id'], is_string($_POST['email_password'] ?? null) ? $_POST['email_password'] : '')) {
            $errors['email_password'] = 'That is not your current password (after 10 wrong tries, wait an hour).';
        }
        $bucket = 'email-change:' . $user['id'];
        if (!$errors && rate_limited($bucket, 5, 3600)) {
            $errors['new_email'] = 'You have asked several times in the last hour. Please try again later.';
        }
        if (!$errors && ($problem = email_change_problem((int) $user['id'], $new)) !== null) {
            $errors['new_email'] = $problem;
        }
        if ($errors) {
            remember_input(['new_email' => $new], $errors);
            redirect('/admin/profile#email');
        }
        rate_hit($bucket);
        email_change_request($user, $new);
        session_keep_current((int) $user['id']);
        audit('email_change_request', 'user', (int) $user['id'], 'own email: confirmation sent to ' . email_mask($new));
        flash('Nearly done: open the link we sent to ' . $new . ' to confirm it. Until then you keep logging in with ' . $user['email'] . '.', 'info');
        redirect('/admin/profile#email');
    }

    /** POST /admin/profile/email/cancel: drop a pending change. */
    public static function emailCancel(array $p): void
    {
        csrf_check();
        $user = require_staff();
        q('UPDATE users SET pending_email = NULL WHERE id = ?', [$user['id']]);
        q("DELETE FROM user_tokens WHERE user_id = ? AND purpose = 'email_change'", [$user['id']]);
        audit('email_change_cancel', 'user', (int) $user['id'], 'own email change cancelled');
        flash('The email change was cancelled.');
        redirect('/admin/profile#email');
    }

    /** POST /admin/profile/password: new password; every other session is logged out. */
    public static function password(array $p): void
    {
        csrf_check();
        $user = require_staff();
        $new = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $errors = [];
        if (!confirm_current_password((int) $user['id'], is_string($_POST['current'] ?? null) ? $_POST['current'] : '')) {
            $errors['current'] = 'That is not your current password (after 10 wrong tries, wait an hour).';
        }
        if (($problem = password_problem($new)) !== null) {
            $errors['password'] = $problem;
        } elseif (!hash_equals($new, is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '')) {
            $errors['password_confirm'] = 'The two passwords do not match.';
        }
        if ($errors) {
            remember_input([], $errors);
            redirect('/admin/profile#password');
        }
        q('UPDATE users SET password_hash = ? WHERE id = ?', [hash_password($new), $user['id']]);
        q('DELETE FROM password_resets WHERE user_id = ?', [$user['id']]);
        user_end_sessions((int) $user['id']);
        login_user((int) $user['id']); // this browser stays logged in with a new session id
        audit('password_change', 'user', (int) $user['id'], 'own password changed; other sessions logged out');
        flash('Password changed. Any other browser where you were logged in has been logged out.');
        redirect('/admin/profile#password');
    }
}
