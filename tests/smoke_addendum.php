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
