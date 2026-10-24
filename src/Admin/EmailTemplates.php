<?php
declare(strict_types=1);

namespace Admin;

use Core\Mail\Message;

/** Editable transactional email templates (§5). */
final class EmailTemplates
{
    private static function def(string $key): array
    {
        if (!isset(email_template_defs()[$key])) {
            abort(404);
        }
        return email_template($key);
    }

    public static function index(array $p): void
    {
        require_permission('communications');
        $groups = [];
        foreach (array_keys(email_template_defs()) as $key) {
            $t = email_template($key);
            $groups[$t['group']][] = $t;
        }
        render('admin/email_templates', ['title' => 'Email templates', 'groups' => $groups], 'admin');
    }

    public static function edit(array $p): void
    {
        require_permission('communications');
        $t = self::def($p['key']);
        $old = take_old_input();
        render('admin/email_template_edit', ['title' => $t['label'], 't' => $t, 'values' => $old['values'] + ['subject' => $t['subject'], 'body' => $t['body'], 'button_label' => $t['button_label']], 'errors' => $old['errors'], 'body_class' => 'wide-mode'], 'admin');
    }

    public static function update(array $p): void
    {
        csrf_check();
        $user = require_permission('communications');
        $t = self::def($p['key']);
        $subject = input('subject');
        $body = trim(str_replace("\r\n", "\n", is_string($_POST['body'] ?? null) ? $_POST['body'] : ''));
        $button = input('button_label');
        $errors = email_template_validate($t['key'], $subject, $body, $button);
        if ($errors) {
            remember_input(['subject' => $subject, 'body' => $body, 'button_label' => $button], $errors);
            redirect('/admin/email-templates/' . $t['key']);
        }
        q('INSERT INTO email_templates (template_key, subject, body, button_label, updated_by) VALUES (?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body), button_label = VALUES(button_label), updated_by = VALUES(updated_by)',
            [$t['key'], $subject, $body, $button, $user['id']]);
        audit('update', 'email_template', null, $t['key']);
        flash('Template saved.');
        redirect('/admin/email-templates/' . $t['key']);
    }

    public static function reset(array $p): void
    {
        csrf_check();
        require_permission('communications');
        $t = self::def($p['key']);
        q('DELETE FROM email_templates WHERE template_key = ?', [$t['key']]);
        audit('reset', 'email_template', null, $t['key']);
        flash('Template reset to the default text.');
        redirect('/admin/email-templates/' . $t['key']);
    }

    /** The rendered email with sample values (iframe; inline styles allowed for this response only). */
    public static function preview(array $p): void
    {
        require_permission('communications');
        $t = self::def($p['key']);
        $parts = email_template_render($t['key'], email_template_samples($t));
        email_preview_out($parts['html']);
    }

    public static function test(array $p): void
    {
        csrf_check();
        $user = require_permission('communications');
        $t = self::def($p['key']);
        $parts = email_template_render($t['key'], email_template_samples($t));
        $ok = mail_send_now(new Message((string) $user['email'], (string) $user['display_name'], '[Test] ' . $parts['subject'], $parts['text'], $parts['html']), 'test', false);
        audit('test', 'email_template', null, $t['key'] . ($ok ? '' : ' (failed)'));
        flash($ok ? 'Test sent to ' . $user['email'] . '.' : 'The test email could not be sent. Check the email settings.', $ok ? 'success' : 'error');
        redirect('/admin/email-templates/' . $t['key']);
    }
}
