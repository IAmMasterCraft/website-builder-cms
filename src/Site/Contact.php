<?php
declare(strict_types=1);

namespace Site;

/**
 * The contact-form section posts here (§5, F14): the message is stored for
 * the admin inbox (phase 5) and emailed to the organisation, with a copy to
 * the sender. Honeypot, time check and a per-IP rate limit guard it.
 */
final class Contact
{
    public static function send(array $p): void
    {
        csrf_check();
        $return = safe_redirect_target(input('return'), '/');
        $values = ['name' => input('name'), 'email' => strtolower(input('email')), 'phone' => input('phone'), 'subject' => input('subject'), 'message' => input('message')];
        $back = static function (array $errors) use ($values, $return): never {
            $_SESSION['contact_errors'] = $errors;
            $_SESSION['contact_old'] = $values;
            redirect($return . '#cf-name');
        };
        $problem = form_guard_problem('contact', $_POST);
        if ($problem !== null) {
            error_log('Contact form rejected: ' . $problem . ' from ' . client_ip());
            $back(['form' => $problem === 'too-fast' ? 'That was quick! Please check your message and send it again.' : 'Your message could not be sent. Please reload the page and try again.']);
        }
        $errors = [];
        if ($values['name'] === '' || mb_strlen($values['name']) > 120) {
            $errors['name'] = 'Enter your name.';
        }
        if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($values['email']) > 190) {
            $errors['email'] = 'Enter a valid email address so we can reply.';
        }
        if (mb_strlen($values['phone']) > 40) {
            $errors['phone'] = 'Keep the phone number under 40 characters.';
        }
        if (mb_strlen($values['subject']) > 200) {
            $errors['subject'] = 'Keep the subject under 200 characters.';
        }
        if ($values['message'] === '' || mb_strlen($values['message']) > 5000) {
            $errors['message'] = 'Write your message (up to 5,000 characters).';
        }
        if ($errors) {
            $back($errors);
        }
        if (form_rate_limited('contact', 5, 3600)) {
            $back(['form' => 'You have sent several messages in a short time. Please try again later or email us directly.']);
        }
        $pageId = input_int('page_id');
        db_insert('contact_messages', [
            'name' => $values['name'], 'email' => $values['email'], 'phone' => $values['phone'],
            'subject' => $values['subject'] !== '' ? $values['subject'] : 'Message from the website',
            'body' => $values['message'], 'page_id' => $pageId > 0 ? $pageId : null, 'ip' => client_ip(),
        ]);
        $to = (string) setting('contact.email', '');
        if ($to !== '') {
            send_template_mail($to, (string) setting('site.name'), 'contact_message', $values);
        }
        send_template_mail($values['email'], $values['name'], 'contact_copy', $values);
        $_SESSION['contact_sent'] = time();
        redirect($return . '#cf-name');
    }
}
