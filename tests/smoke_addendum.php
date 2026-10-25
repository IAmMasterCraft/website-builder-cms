<?php
declare(strict_types=1);

// Addendum A smoke checks (A2 newsletter opt-in and essential notices, A5
// bounce handling), included by tests/smoke.php after smoke_admin.php.

// ---- A2: dashboard opt-in card -------------------------------------------------------
$optUid = create_user('optin@example.test', 'optin-password-123', 'member', 'Opal Tin', 'active');
$optMid = db_insert('members', ['user_id' => $optUid, 'first_name' => 'Opal', 'last_name' => 'Tin', 'membership_type' => 'Standard', 'region' => 'England',
    'birth_day' => 3, 'birth_month' => 4, 'member_number' => 'ORG-26-9001', 'prefs' => json_encode(['newsletters' => false, 'birthday_greeting' => true, 'celebrants' => false])]);
$opt = login_as($base, 'optin@example.test', 'optin-password-123');
$home = $opt->get('/account');
check('A2: a member without news emails sees the opt-in card', str_contains($home['body'], 'Get news and event updates by email') && str_contains($home['body'], 'action="/account/newsletter/opt-in"'));
$r = $opt->post('/account/newsletter/opt-in', []);
check('A2: opt-in without a CSRF token is refused (419)', $r['status'] === 419 && !member_pref(member_for_user($optUid), 'newsletters'));
$r = $opt->submit('/account', '/account/newsletter/dismiss', []);
check('A2: "No thanks" hides the card', $r['status'] === 303 && !str_contains($opt->get('/account')['body'], 'Get news and event updates by email'));
q('UPDATE members SET optin_prompt_dismissed_at = UTC_TIMESTAMP() - INTERVAL 91 DAY WHERE id = ?', [$optMid]);
check('A2: the card comes back after 90 days', str_contains($opt->get('/account')['body'], 'Get news and event updates by email'));
$r = $opt->submit('/account', '/account/newsletter/opt-in', []);
check('A2: one click switches news emails on (and is audited)', $r['status'] === 303 && member_pref(member_for_user($optUid), 'newsletters')
    && q_val("SELECT id FROM audit_log WHERE action = 'consent' AND item_id = ?", [$optMid]) !== null);
check('A2: no card once opted in', !str_contains($opt->get('/account')['body'], 'Get news and event updates by email'));
$r = (new Browser($base))->get('/account/preferences');
check('A2: the preferences link in emails asks visitors to log in and returns there', $r['status'] === 303 && $r['location'] === '/login?next=%2Faccount%2Fpreferences');

// ---- A2: sign-up default and essential notices ---------------------------------------
status_is($admin, '/admin/settings/membership', 200, 'pre-ticked box is not valid consent');
check('A2: the sign-up box is unticked by default', (bool) preg_match('/name="newsletters" value="1"(?! checked)/', (new Browser($base))->get('/join')['body']));
$r = $admin->post('/admin/campaigns', ['_csrf' => $admin->csrf('/admin/campaigns'), 'subject' => 'AGM notice']);
$essId = (int) q_val("SELECT MAX(id) FROM campaigns WHERE subject = 'AGM notice'");
$edit = $admin->get('/admin/campaigns/' . $essId);
check('A2: the campaign editor offers "Essential membership notice" with a warning', str_contains($edit['body'], 'name="essential"') && str_contains($edit['body'], 'Only for essential membership business'));
$r = $admin->submit('/admin/campaigns/' . $essId, '/admin/campaigns/' . $essId, ['subject' => 'AGM notice', 'preheader' => '', 'essential' => '1', 'audience_type' => 'all_members',
    'blocks[0][type]' => 'heading', 'blocks[0][text]' => 'Annual general meeting', 'blocks[0][size]' => 'large', 'op' => 'save']);
check('A2: marking a campaign essential is saved and audit-logged', (int) q_val('SELECT essential FROM campaigns WHERE id = ?', [$essId]) === 1
    && q_val("SELECT id FROM audit_log WHERE action = 'essential_on' AND item_id = ?", [$essId]) !== null);
$edit = $admin->get('/admin/campaigns/' . $essId);
check('A2: the editor shows the essential badge and reach ignores news preferences', str_contains($edit['body'], 'Essential notice') && str_contains($edit['body'], 'whatever their news email preference'));

// ---- A5: bounce handling screens, webhooks, list hygiene -----------------------------
$r = $admin->get('/admin/settings/email');
check('A5: email settings have the bounce section (address, mailbox, webhooks)', $r['status'] === 200 && str_contains($r['body'], 'Bounces and complaints')
    && str_contains($r['body'], 'name="mb_protocol"') && str_contains($r['body'], '/webhooks/email/mailgun'));
$r = $admin->post('/admin/settings/email/bounces', ['bounce_address' => 'bounces@example.test']);
check('A5: bounce settings refuse a request without a CSRF token', $r['status'] === 419);
$r = $admin->submit('/admin/settings/email', '/admin/settings/email/bounces', ['bounce_address' => 'bounces+x@example.test', 'per_domain_minute' => '30', 'mb_protocol' => 'off',
    'mb_encryption' => 'ssl', 'mb_port' => '993', 'mb_max_messages' => '500', 'mb_max_age_days' => '30']);
check('A5: a bounce address with a + tag is refused (422)', $r['status'] === 422 && str_contains($r['body'], 'without a + tag'));
$r = $admin->submit('/admin/settings/email', '/admin/settings/email/bounces', ['bounce_address' => 'bounces@example.test', 'per_domain_minute' => '30', 'mb_protocol' => 'off',
    'mb_encryption' => 'ssl', 'mb_port' => '993', 'mb_max_messages' => '500', 'mb_max_age_days' => '30', 'wh_mailgun_key' => 'smoke-mailgun-key']);
settings_all([]);
$saved = bounce_settings();
check('A5: bounce settings saved; the webhook key is stored encrypted and audited', $r['status'] === 303 && $saved['address'] === 'bounces@example.test'
    && str_starts_with($saved['webhooks']['mailgun_key'], 'sb2:') && !str_contains(json_encode(setting_fresh('mail.bounce')), 'smoke-mailgun-key')
    && q_val("SELECT id FROM audit_log WHERE summary = 'bounce handling settings'") !== null);
check('A5: the settings page never prints the saved key', !str_contains($admin->get('/admin/settings/email')['body'], 'smoke-mailgun-key'));

// Mailgun webhook over HTTP: no session, no CSRF token, the signature is the credential.
$mgRow = db_insert('campaign_recipients', ['campaign_id' => $essId, 'email' => 'mg-smoke@recipient.example.test', 'name' => 'M', 'first_name' => 'M', 'status' => 'sent', 'sent_at' => utc_now()]);
$ts = (string) time();
$tok = bin2hex(random_bytes(20));
$payload = json_encode(['signature' => ['timestamp' => $ts, 'token' => $tok, 'signature' => hash_hmac('sha256', $ts . $tok, 'smoke-mailgun-key')],
    'event-data' => ['id' => 'smoke-' . $tok, 'event' => 'failed', 'severity' => 'permanent', 'recipient' => 'mg-smoke@recipient.example.test',
        'delivery-status' => ['code' => 550, 'message' => '5.1.1 user unknown', 'enhanced-code' => '5.1.1'], 'user-variables' => ['gather_id' => verp_tag('c', $mgRow, (string) q_val('SELECT email FROM campaign_recipients WHERE id = ?', [$mgRow]))]]]);
$hook = new Browser($base);
$r = $hook->post('/webhooks/email/mailgun', $payload, ['Content-Type: application/json']);
check('A5: a signed Mailgun webhook is accepted without a CSRF token and records the hard bounce', $r['status'] === 200
    && (email_suppression('mg-smoke@recipient.example.test')['reason'] ?? null) === 'hard_bounce' && q_val('SELECT bounce FROM campaign_recipients WHERE id = ?', [$mgRow]) === 'hard');
$r = $hook->post('/webhooks/email/mailgun', str_replace(hash_hmac('sha256', $ts . $tok, 'smoke-mailgun-key'), str_repeat('a', 64), $payload), ['Content-Type: application/json']);
check('A5: a forged Mailgun signature is refused (401)', $r['status'] === 401);
$failsBefore = (int) q_val("SELECT COUNT(*) FROM rate_hits WHERE bucket LIKE 'webhook-fail:%'");
$ts2 = (string) time();
$tok2 = bin2hex(random_bytes(20));
$typed = json_encode(['signature' => ['timestamp' => $ts2, 'token' => $tok2, 'signature' => hash_hmac('sha256', $ts2 . $tok2, 'smoke-mailgun-key')],
    'event-data' => ['event' => 'failed', 'severity' => 'permanent', 'recipient' => ['mg-smoke@recipient.example.test']]]);
$r = $hook->post('/webhooks/email/mailgun', $typed, ['Content-Type: application/json']);
check('A5 M5: an array where text is expected answers 400 (not 500) and counts towards the failure limit', $r['status'] === 400
    && (int) q_val("SELECT COUNT(*) FROM rate_hits WHERE bucket LIKE 'webhook-fail:%'") === $failsBefore + 1);
check('A5: an unconfigured provider answers 404', $hook->post('/webhooks/email/brevo', '{}', ['Content-Type: application/json'])['status'] === 404);
check('A5: unknown providers do not match a route (404)', $hook->post('/webhooks/email/other', '{}')['status'] === 404);

// Suppression list, member banner, delivery logs, health.
$r = $admin->get('/admin/email-suppressions');
check('A5: the suppression list shows the bounced address with a Clear button', $r['status'] === 200 && str_contains($r['body'], 'mg-smoke@recipient.example.test') && str_contains($r['body'], 'Hard bounce'));
email_suppress('optin@example.test', 'hard_bounce', '550 5.1.1 user unknown', 'dsn');
check('A5: a member whose address bounced sees a banner asking to update it', str_contains($opt->get('/account')['body'], 'are not getting through') && str_contains($opt->get('/account/preferences')['body'], 'update your email address'));
$r = $admin->submit('/admin/email-suppressions', '/admin/email-suppressions/clear', ['email' => 'optin@example.test']);
check('A5: an admin clears the bounce once the address is fixed (audited)', $r['status'] === 303 && !email_suppressed('optin@example.test')
    && q_val("SELECT id FROM audit_log WHERE action = 'clear' AND summary LIKE 'optin@example.test%'") !== null);
check('A5: the banner is gone', !str_contains($opt->get('/account')['body'], 'are not getting through'));
$r = $admin->get('/admin/campaigns/' . $essId . '/log');
check('A5: the campaign delivery log shows delivered / soft / hard / complaint / suppressed', $r['status'] === 200 && str_contains($r['body'], 'Soft bounces')
    && str_contains($r['body'], 'Hard bounces') && str_contains($r['body'], 'Complaints') && str_contains($r['body'], 'Suppressed') && str_contains($r['body'], 'hard bounce'));
$r = $admin->get('/admin/email-log');
check('A5: the global delivery log has bounce columns', $r['status'] === 200 && str_contains($r['body'], 'Bounce / complaint'));
$r = $admin->get('/admin/health');
check('A5: health shows bounce and complaint rates', str_contains($r['body'], 'Bounce rate (7 days)') && str_contains($r['body'], 'Complaint rate (30 days)') && str_contains($r['body'], 'Bounce mailbox'));
q("UPDATE campaigns SET status = 'paused', pause_reason = 'Smoke block' WHERE id = ?", [$essId]);
$r = $admin->get('/admin/campaigns/' . $essId . '/log');
check('A5: a paused campaign shows why, with a Resume button', str_contains($r['body'], 'Smoke block') && str_contains($r['body'], '/resume'));
$r = $admin->submit('/admin/campaigns/' . $essId . '/log', '/admin/campaigns/' . $essId . '/resume', []);
check('A5: resume puts the campaign back to sending (audited)', $r['status'] === 303 && q_val('SELECT status FROM campaigns WHERE id = ?', [$essId]) === 'sending'
    && q_val("SELECT id FROM audit_log WHERE action = 'resume' AND item_id = ?", [$essId]) !== null);
q("UPDATE campaign_recipients SET status = 'sent', sent_at = UTC_TIMESTAMP() WHERE campaign_id = ? AND status = 'queued'", [$essId]);
mail_queue_run();

// Sign-up list hygiene: no mail server (.invalid never exists) and typo suggestions.
$typo = new Browser($base);
$tokens = guard_tokens($typo, '/join/group');
sleep(3);
$groupBase = ['name' => 'Typo Club', 'contact_person' => 'Ty Po', 'country' => 'United Kingdom', 'password' => 'group-password-12', 'password_confirm' => 'group-password-12', 'consent' => '1'];
$r = $typo->post('/join/group', $groupBase + ['email' => 'club@nomail.invalid'] + $tokens);
check('A5: sign-up refuses a domain without a mail server', $r['status'] === 422 && str_contains($r['body'], 'could not find a mail server'));
$r = $typo->post('/join/group', $groupBase + ['email' => 'typoclub@gmial.com'] + $tokens);
check('A5: sign-up suggests a correction for an obvious typo', $r['status'] === 422 && str_contains($r['body'], 'Did you mean typoclub@gmail.com?') && str_contains($r['body'], 'name="email_checked" value="typoclub@gmial.com"'));
$r = $typo->post('/join/group', $groupBase + ['email' => 'typoclub@gmial.com', 'email_checked' => 'typoclub@gmial.com'] + $tokens);
check('A5: sending it again keeps the address as typed', $r['status'] === 303 && q_val("SELECT id FROM users WHERE email = 'typoclub@gmial.com'") !== null);
