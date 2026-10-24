<?php
declare(strict_types=1);

namespace Admin;

use Core\Mail\Message;

/** Super admin: sender, transport (file/SMTP), sending rate, birthday time, and a test email. */
final class EmailSettings
{
    public static function edit(array $p): void
    {
        require_permission('super_admin');
        self::form([], []);
    }

    private static function form(array $values, array $errors, int $status = 200): void
    {
        $smtp = mail_smtp_settings();
        [$fromEmail, $fromName] = mail_from();
        $current = [
            'driver' => mail_driver(), 'from_email' => (string) setting('mail.from_email', '') ?: $fromEmail, 'from_name' => (string) setting('mail.from_name', '') ?: $fromName,
            'host' => $smtp['host'], 'port' => (string) $smtp['port'], 'encryption' => $smtp['encryption'], 'username' => $smtp['username'],
            'per_minute' => (string) mail_per_minute(), 'birthday_time' => birthday_time(),
        ];
        render('admin/email_settings', [
            'title' => 'Email settings', 'values' => $values + $current, 'errors' => $errors, 'has_password' => $smtp['has_password'],
            'counts' => mail_queue_counts(), 'user' => current_user(),
        ], 'admin', $status);
    }

    public static function update(array $p): void
    {
        csrf_check();
        require_permission('super_admin');
        $v = [];
        foreach (['driver', 'from_email', 'from_name', 'host', 'port', 'encryption', 'username', 'per_minute', 'birthday_time'] as $k) {
            $v[$k] = input($k);
        }
        $password = is_string($_POST['password'] ?? null) ? (string) $_POST['password'] : '';
        $errors = [];
        if (!in_array($v['driver'], ['file', 'smtp'], true)) {
            $errors['driver'] = 'Choose how emails are sent.';
        }
        if (!filter_var($v['from_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['from_email'] = 'Enter the address emails come from.';
        }
        if ($v['from_name'] === '' || mb_strlen($v['from_name']) > 120 || preg_match('/[\r\n]/', $v['from_name'])) {
            $errors['from_name'] = 'Enter a sender name (up to 120 characters).';
        }
        if ($v['driver'] === 'smtp' || $v['host'] !== '') {
            if (!preg_match('/^[A-Za-z0-9.-]{1,190}$/', $v['host'])) {
                $errors['host'] = 'Enter the SMTP server name, e.g. smtp.example.org.';
            }
            if (!ctype_digit($v['port']) || (int) $v['port'] < 1 || (int) $v['port'] > 65535) {
                $errors['port'] = 'Enter a port number (usually 587 or 465).';
            }
        }
        if (!in_array($v['encryption'], ['tls', 'ssl', 'none'], true)) {
            $errors['encryption'] = 'Choose the encryption.';
        }
        if (mb_strlen($v['username']) > 190) {
            $errors['username'] = 'The user name is too long.';
        }
        if (!ctype_digit($v['per_minute']) || (int) $v['per_minute'] < 1 || (int) $v['per_minute'] > 10000) {
            $errors['per_minute'] = 'Enter a number between 1 and 10,000.';
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v['birthday_time'])) {
            $errors['birthday_time'] = 'Enter a time like 07:00.';
        }
        if ($errors) {
            self::form($v, $errors, 422);
            return;
        }
        $old = setting('mail.smtp', []);
        $old = is_array($old) ? $old : [];
        $sealed = input('password_clear') === '1' ? '' : ($password !== '' ? secret_seal($password) : (string) ($old['password'] ?? ''));
        setting_set('mail.driver', $v['driver']);
        setting_set('mail.from_email', strtolower($v['from_email']));
        setting_set('mail.from_name', $v['from_name']);
        setting_set('mail.smtp', ['host' => $v['host'], 'port' => (int) $v['port'], 'encryption' => $v['encryption'], 'username' => $v['username'], 'password' => $sealed]);
        setting_set('mail.per_minute', (int) $v['per_minute']);
        setting_set('birthdays.time', $v['birthday_time']);
        mailer(null, true);
        audit('update', 'settings', null, 'email settings');
        flash('Email settings saved.');
        redirect('/admin/settings/email');
    }

    public static function test(array $p): void
    {
        csrf_check();
        $user = require_permission('super_admin');
        $to = strtolower(input('to'));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash('Enter a valid address for the test email.', 'error');
            redirect('/admin/settings/email');
        }
        $html = email_layout('<h2 class="email-h1" style="margin:0 0 12px;font-size:24px">It works!</h2><p style="margin:0 0 16px">This test email was sent from the email settings of ' . e(setting('site.name')) . ' using the <strong>' . e(mail_driver() === 'smtp' ? 'SMTP server ' . mail_smtp_settings()['host'] : 'development file mailer') . '</strong>.</p>');
        try {
            mailer(null, true)->send(new Message($to, (string) $user['display_name'], 'Test email from ' . setting('site.name'), "It works! This test email was sent from the email settings.\n", $html));
            audit('test', 'email', null, $to);
            flash('Test email sent to ' . $to . '. Check the inbox (and spam folder).');
        } catch (\Throwable $e) {
            flash('The test email could not be sent: ' . mb_substr($e->getMessage(), 0, 300), 'error');
        }
        redirect('/admin/settings/email');
    }
}
