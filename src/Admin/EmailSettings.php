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

    private static function form(array $values, array $errors, int $status = 200, array $bounceValues = [], array $bounceErrors = []): void
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
        ] + self::bounceData($bounceValues, $bounceErrors), 'admin', $status);
    }

    /** Values for the bounce handling section (secrets are never printed, only "saved"). */
    private static function bounceData(array $values, array $errors): array
    {
        $b = bounce_settings();
        $current = [
            'bounce_address' => $b['address'], 'per_domain_minute' => (string) $b['per_domain_minute'],
            'mb_protocol' => $b['mailbox']['protocol'], 'mb_host' => $b['mailbox']['host'], 'mb_port' => (string) $b['mailbox']['port'],
            'mb_encryption' => $b['mailbox']['encryption'], 'mb_username' => $b['mailbox']['username'], 'mb_folder' => $b['mailbox']['folder'],
            'mb_aside_folder' => $b['mailbox']['aside_folder'], 'mb_max_messages' => (string) $b['mailbox']['max_messages'], 'mb_max_age_days' => (string) $b['mailbox']['max_age_days'],
            'wh_ses' => $b['webhooks']['ses'] ? '1' : '', 'wh_ses_topics' => $b['webhooks']['ses_topics'], 'wh_postmark_user' => $b['webhooks']['postmark_user'],
            'wh_sendgrid_key' => $b['webhooks']['sendgrid_key'],
        ];
        $saved = [];
        foreach (['mb_password' => $b['mailbox']['password'], 'wh_mailgun_key' => $b['webhooks']['mailgun_key'], 'wh_postmark_password' => $b['webhooks']['postmark_password'],
            'wh_postmark_token' => $b['webhooks']['postmark_token'], 'wh_brevo_token' => $b['webhooks']['brevo_token']] as $k => $v) {
            $saved[$k] = $v !== '';
        }
        return ['bounce' => $values + $current, 'bounce_errors' => $errors, 'bounce_saved' => $saved, 'bounce_state' => bounce_mailbox_state(),
            'webhook_urls' => array_combine(WEBHOOK_PROVIDERS, array_map(static fn (string $p): string => base_url('/webhooks/email/' . $p), WEBHOOK_PROVIDERS)),
            'webhook_on' => array_combine(WEBHOOK_PROVIDERS, array_map(static fn (string $p): bool => webhook_enabled($p, $b['webhooks']), WEBHOOK_PROVIDERS)),
            'unparsed_local' => count(bounce_local_aside_files())];
    }

    /** Bounce address, bounce mailbox, per-domain pace and provider webhooks (A5). */
    public static function updateBounces(array $p): void
    {
        csrf_check();
        require_permission('super_admin');
        $keys = ['bounce_address', 'per_domain_minute', 'mb_protocol', 'mb_host', 'mb_port', 'mb_encryption', 'mb_username', 'mb_folder', 'mb_aside_folder',
            'mb_max_messages', 'mb_max_age_days', 'wh_ses_topics', 'wh_postmark_user', 'wh_sendgrid_key'];
        $v = [];
        foreach ($keys as $k) {
            $v[$k] = input($k);
        }
        $v['wh_ses'] = input('wh_ses') === '1' ? '1' : '';
        $e = [];
        if ($v['bounce_address'] !== '' && (!filter_var($v['bounce_address'], FILTER_VALIDATE_EMAIL) || str_contains($v['bounce_address'], '+'))) {
            $e['bounce_address'] = 'Enter an address such as bounces@yourdomain.org (without a + tag).';
        }
        if (!ctype_digit($v['per_domain_minute']) || (int) $v['per_domain_minute'] > 10000) {
            $e['per_domain_minute'] = 'Enter 0 (no limit) or a number up to 10,000.';
        }
        if (!in_array($v['mb_protocol'], ['off', 'imap', 'pop3'], true)) {
            $e['mb_protocol'] = 'Choose how to read the bounce mailbox.';
        }
        if ($v['mb_protocol'] !== 'off') {
            if (!preg_match('/^[A-Za-z0-9.-]{1,190}$/', $v['mb_host'])) {
                $e['mb_host'] = 'Enter the mail server name, e.g. imap.example.org.';
            }
            if (!ctype_digit($v['mb_port']) || (int) $v['mb_port'] < 1 || (int) $v['mb_port'] > 65535) {
                $e['mb_port'] = 'Enter a port (IMAP 993, POP3 995).';
            }
            if ($v['mb_username'] === '' || mb_strlen($v['mb_username']) > 190 || preg_match('/[\r\n]/', $v['mb_username'])) {
                $e['mb_username'] = 'Enter the mailbox user name.';
            }
        }
        if (!in_array($v['mb_encryption'], ['ssl', 'starttls'], true)) {
            $e['mb_encryption'] = 'Choose SSL/TLS or STARTTLS: the mailbox is always read over an encrypted connection.';
        }
        foreach (['mb_folder' => 'INBOX', 'mb_aside_folder' => 'Gather-Unprocessed'] as $k => $default) {
            $v[$k] = $v[$k] === '' ? $default : $v[$k];
            if (mb_strlen($v[$k]) > 100 || preg_match('/[\r\n"\\\\]/', $v[$k])) {
                $e[$k] = 'Use a simple folder name.';
            }
        }
        if (!ctype_digit($v['mb_max_messages']) || (int) $v['mb_max_messages'] < 10 || (int) $v['mb_max_messages'] > 100000) {
            $e['mb_max_messages'] = 'Enter a number between 10 and 100,000.';
        }
        if (!ctype_digit($v['mb_max_age_days']) || (int) $v['mb_max_age_days'] < 1 || (int) $v['mb_max_age_days'] > 3650) {
            $e['mb_max_age_days'] = 'Enter a number of days between 1 and 3,650.';
        }
        $topics = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $v['wh_ses_topics']) ?: [])));
        foreach ($topics as $t) {
            if (!preg_match('/^arn:aws(-cn|-us-gov)?:sns:[a-z0-9-]+:\d{12}:[A-Za-z0-9_-]{1,256}$/', $t)) {
                $e['wh_ses_topics'] = 'Enter SNS topic ARNs such as arn:aws:sns:eu-west-2:123456789012:ses-bounces.';
            }
        }
        if ($v['wh_ses'] === '1' && $topics === []) {
            $e['wh_ses_topics'] = 'Add the SNS topic ARN(s) that may post to this site.';
        }
        if ($v['wh_sendgrid_key'] !== '' && openssl_pkey_get_public(webhook_sendgrid_pem($v['wh_sendgrid_key'])) === false) {
            $e['wh_sendgrid_key'] = 'This is not a valid verification key (copy it from SendGrid: Mail Settings, Signed Event Webhook).';
        }
        if ($e) {
            self::formWithBounces($v, $e);
            return;
        }
        $old = bounce_settings();
        $secret = static function (string $field, string $current): string {
            $new = is_string($_POST[$field] ?? null) ? (string) $_POST[$field] : '';
            if (input($field . '_clear') === '1') {
                return '';
            }
            return $new !== '' ? secret_seal($new) : $current;
        };
        setting_set('mail.bounce', [
            'address' => strtolower($v['bounce_address']),
            'per_domain_minute' => (int) $v['per_domain_minute'],
            'mailbox' => ['protocol' => $v['mb_protocol'], 'host' => $v['mb_host'], 'port' => (int) $v['mb_port'], 'encryption' => $v['mb_encryption'],
                'username' => $v['mb_username'], 'password' => $secret('mb_password', $old['mailbox']['password']), 'folder' => $v['mb_folder'],
                'aside_folder' => $v['mb_aside_folder'], 'max_messages' => (int) $v['mb_max_messages'], 'max_age_days' => (int) $v['mb_max_age_days'], 'cafile' => $old['mailbox']['cafile']],
            'webhooks' => ['ses' => $v['wh_ses'] === '1', 'ses_topics' => implode("\n", $topics), 'mailgun_key' => $secret('wh_mailgun_key', $old['webhooks']['mailgun_key']),
                'postmark_user' => $v['wh_postmark_user'], 'postmark_password' => $secret('wh_postmark_password', $old['webhooks']['postmark_password']),
                'postmark_token' => $secret('wh_postmark_token', $old['webhooks']['postmark_token']), 'sendgrid_key' => trim($v['wh_sendgrid_key']),
                'brevo_token' => $secret('wh_brevo_token', $old['webhooks']['brevo_token'])],
        ]);
        audit('update', 'settings', null, 'bounce handling settings');
        flash('Bounce handling settings saved.');
        redirect('/admin/settings/email#bounces');
    }

    private static function formWithBounces(array $bounceValues, array $bounceErrors): void
    {
        self::form([], [], 422, $bounceValues, $bounceErrors);
    }

    /** "Check the bounce mailbox now" (runs the same routine as cron). */
    public static function pollBounces(array $p): void
    {
        csrf_check();
        require_permission('super_admin');
        $s = bounce_mailbox_poll();
        audit('poll', 'settings', null, 'bounce mailbox: ' . ($s['error'] !== '' ? 'error' : $s['read'] . ' read'));
        if ($s['error'] === 'off') {
            flash('Set up the bounce mailbox first.', 'error');
        } elseif ($s['error'] !== '') {
            flash('The bounce mailbox could not be read: ' . $s['error'], 'error');
        } else {
            flash(sprintf('Bounce mailbox checked: %d read, %d recorded, %d ignored, %d set aside.', $s['read'], $s['recorded'], $s['ignored'], $s['unparsed']));
        }
        redirect('/admin/settings/email#bounces');
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
