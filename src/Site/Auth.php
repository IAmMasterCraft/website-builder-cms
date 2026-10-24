<?php
declare(strict_types=1);

namespace Site;

use Core\HttpException;

final class Auth
{
    public static function loginForm(array $p): void
    {
        $user = current_user();
        if ($user !== null) {
            redirect(home_for($user));
        }
        $old = take_old_input();
        render('site/login', ['title' => 'Log in', 'next' => safe_redirect_target(query_param('next'), ''), 'old' => $old['values'], 'errors' => $old['errors'], 'noindex' => true]);
    }

    public static function login(array $p): void
    {
        csrf_check();
        $email = strtolower(input('email'));
        $password = (string) ($_POST['password'] ?? '');
        $next = safe_redirect_target(input('next'), '');
        $back = '/login' . ($next !== '' ? '?next=' . rawurlencode($next) : '');
        if ($email === '' || $password === '' || !is_string($_POST['password'] ?? null)) {
            remember_input(['email' => $email], ['form' => 'Enter your email and password.']);
            redirect($back);
        }
        try {
            $userId = attempt_login($email, $password);
        } catch (HttpException $e) {
            remember_input(['email' => $email], ['form' => 'Too many failed attempts. Please wait 15 minutes or reset your password.']);
            redirect($back);
        }
        if ($userId === null) {
            remember_input(['email' => $email], ['form' => t('auth.bad_login')]);
            redirect($back);
        }
        login_user($userId);
        $user = current_user();
        if ($user !== null && $user['status'] === 'unverified') {
            flash('Please check your email and follow the link to verify your account.', 'info');
        }
        redirect($next !== '' && !str_starts_with($next, '/login') ? $next : home_for($user ?? ['role' => 'member', 'status' => 'active']));
    }

    public static function logoutConfirm(array $p): void
    {
        if (current_user() === null) {
            redirect('/');
        }
        render('site/logout', ['title' => 'Log out', 'noindex' => true]);
    }

    public static function logout(array $p): void
    {
        csrf_check();
        logout_user();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        flash('You have been logged out.');
        redirect('/');
    }

    public static function forgotForm(array $p): void
    {
        $old = take_old_input();
        render('site/forgot', ['title' => 'Reset your password', 'old' => $old['values'], 'errors' => $old['errors'], 'noindex' => true]);
    }

    public static function forgot(array $p): void
    {
        csrf_check();
        $email = strtolower(input('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            remember_input(['email' => $email], ['email' => 'Enter a valid email address.']);
            redirect('/forgot-password');
        }
        $bucketIp = 'reset-ip|' . client_ip();
        $bucketEmail = 'reset-email|' . $email;
        if (!rate_limited($bucketIp, 10, 3600) && !rate_limited($bucketEmail, 3, 3600)) {
            rate_hit($bucketIp);
            rate_hit($bucketEmail);
            $user = q_one("SELECT id, email, display_name FROM users WHERE email = ? AND status <> 'suspended'", [$email]);
            if ($user !== null) {
                $token = create_password_reset((int) $user['id']);
                send_template_mail($user['email'], explode(' ', (string) $user['display_name'])[0], 'password_reset', [
                    'reset_url' => base_url('/reset/' . $token),
                ]);
            }
        }
        // Same answer whether or not the account exists.
        flash(t('auth.reset_sent'), 'info');
        redirect('/login');
    }

    public static function resetForm(array $p): void
    {
        $valid = find_password_reset($p['token']) !== null;
        $old = take_old_input();
        render('site/reset', ['title' => 'Choose a new password', 'token' => $p['token'], 'valid' => $valid, 'errors' => $old['errors'], 'noindex' => true]);
    }

    public static function reset(array $p): void
    {
        csrf_check();
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $confirm = is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '';
        $errors = [];
        if (($problem = password_problem($password)) !== null) {
            $errors['password'] = $problem;
        } elseif (!hash_equals($password, $confirm)) {
            $errors['password_confirm'] = 'The two passwords do not match.';
        }
        if ($errors) {
            remember_input([], $errors);
            redirect('/reset/' . $p['token']);
        }
        if (!complete_password_reset($p['token'], $password)) {
            flash('That reset link has expired or was already used. Request a new one.', 'error');
            redirect('/forgot-password');
        }
        flash('Your password has been changed. You can log in now.');
        redirect('/login');
    }
}
