<?php
declare(strict_types=1);

// Addendum B smoke checks through php -S: B1 sidebar groups, B2 log in as a
// member (impersonation), B3 help centre and /help. Included by
// tests/smoke.php after smoke_design.php (uses its Browser, check(),
// status_is(), $admin (super admin), $base, login_as() and meta_csrf()).

/** The gather_sid value a response set, or '' (a new value = the session id was regenerated). */
function set_session_cookie(array $r): string
{
    return preg_match('/^Set-Cookie:\s*gather_sid=([^;]+)/mi', $r['headers'] ?? '', $m) ? $m[1] : '';
}

// ---- B1: sidebar groups ------------------------------------------------------------
$h = $admin->get('/admin/members')['body'];
check('B1: each sidebar group header is a button with aria-expanded and aria-controls', (bool) preg_match('#<button type="button" class="nav-group-toggle" aria-expanded="(true|false)" aria-controls="nav-g-site">#', $h)
    && substr_count($h, 'class="nav-group-toggle"') === 7);
check('B1: the group of the current page is the active parent, open, with the current link marked', (bool) preg_match('#<li class="nav-group is-active-parent" data-group="members">\s*<p class="nav-section"[^>]*>Members</p>\s*<button type="button" class="nav-group-toggle" aria-expanded="true" aria-controls="nav-g-members">#', $h)
    && str_contains($h, '<a href="/admin/members" aria-current="page">') && substr_count($h, 'is-active-parent') === 1);
check('B1: other groups start closed; the no-JS label and the list ids are in the markup', str_contains($h, 'aria-expanded="false" aria-controls="nav-g-radio"') && str_contains($h, '<ul class="nav-items" id="nav-g-radio"') && str_contains($h, 'class="nav-section" id="nav-g-radio-label"'));
check('B1: Expand all / Collapse all control (hidden until JS) and the Help centre link', str_contains($h, 'data-nav-all') && str_contains($h, 'data-nav-tools hidden') && str_contains($h, 'href="/admin/help"'));
$h = $admin->get('/admin/radio/playlists')['body'];
check('B1: the most specific link is current and its group is active (Radio > Playlists)', str_contains($h, '<a href="/admin/radio/playlists" aria-current="page">') && !str_contains($h, '<a href="/admin/radio" aria-current') && str_contains($h, 'class="nav-group is-active-parent" data-group="radio"'));
create_user('b1-radio@example.test', 'b1-radio-password', 'admin', 'Radio Only', 'active', ['radio']);
$radioB = login_as($base, 'b1-radio@example.test', 'b1-radio-password');
$h = $radioB->get('/admin/radio')['body'];
preg_match_all('#data-group="([a-z]+)"#', $h, $gm);
check('B1: groups without permitted screens are not shown (radio-only admin: Site and Radio)', $gm[1] === ['site', 'radio'] && !str_contains($h, '/admin/members') && !str_contains($h, '/admin/settings'));

// ---- B3: help centre ---------------------------------------------------------------
$r = $admin->get('/admin/help');
check('B3: /admin/help shows the documentation start page with the contents', $r['status'] === 200 && str_contains($r['body'], 'id="gather-documentation"') && str_contains($r['body'], 'aria-label="Help contents"') && str_contains($r['body'], 'href="/admin/help/manager-guide/how-do-i"'));
$r = $admin->get('/admin/help/manager-guide/members');
check('B3: a guide page renders with in-page headings, prev/next and rewritten links', $r['status'] === 200 && str_contains($r['body'], 'id="log-in-as-a-member"') && str_contains($r['body'], 'aria-label="On this page"')
    && str_contains($r['body'], 'class="help-next"') && str_contains($r['body'], 'href="/admin/help/member-guide#joining"') && !str_contains($r['body'], '.md"'));
check('B3: guide screenshots come from the docs image route', str_contains($r['body'], 'src="/admin/help/images/admin-members.webp"') && str_contains($r['body'], 'aria-current="page">Members, ID cards'));
$img = $admin->get('/admin/help/images/admin-members.webp');
check('B3: docs images are served (image/webp, nosniff)', $img['status'] === 200 && $img['type'] === 'image/webp' && str_contains($img['headers'], 'nosniff'));
foreach (['/admin/help/images/..%2F..%2Fconfig.php', '/admin/help/images/x.svg', '/admin/help/images/nope.webp', '/admin/help/manager-guide/nope', '/admin/help/../config'] as $bad) {
    check("B3: refused: $bad", in_array($admin->get($bad)['status'], [404, 400], true));
}
$r = $admin->get('/admin/help/search?q=' . rawurlencode('member card'));
check('B3: server-side search lists matching sections with highlighted words', $r['status'] === 200 && str_contains($r['body'], 'class="help-results"') && str_contains($r['body'], '<mark>'));
$r = $admin->get('/admin/help/search?q=' . rawurlencode('<script>alert(1)</script>'));
check('B3: search terms are escaped in the page', $r['status'] === 200 && !str_contains($r['body'], '<script>alert(1)') && str_contains($r['body'], '&lt;script&gt;'));
check('B3: each admin screen header links to its guide page', str_contains($admin->get('/admin/members')['body'], '<a class="help-link" href="/admin/help/manager-guide/members">')
    && str_contains($admin->get('/admin/radio/schedule')['body'], 'class="help-link" href="/admin/help/manager-guide/radio"')
    && str_contains($admin->get('/admin/settings/membership')['body'], 'href="/admin/help/manager-guide/members#membership-settings"'));
check('B3: staff without any permission can read the help centre', $radioB->get('/admin/help/manager-guide/backups')['status'] === 200);
$memberB = login_as($base, 'member@example.test', 'member-password-123');
check('B3: members get 403 on the admin help; visitors are sent to log in', $memberB->get('/admin/help')['status'] === 403 && (new Browser($base))->get('/admin/help')['status'] === 303);
$r = (new Browser($base))->get('/help');
check('B3: /help shows the member guide in the site theme (public by default)', $r['status'] === 200 && str_contains($r['body'], 'class="md"') && str_contains($r['body'], 'id="joining"') && str_contains($r['body'], 'class="page-hero"') && str_contains($r['body'], 'src="/help/images/'));
check('B3: /help links only to itself and keeps the strict CSP', !str_contains($r['body'], '/admin/help') && !str_contains($r['headers'], 'unsafe-inline'));
check('B3: /help images are public while the guide is public', (new Browser($base))->get('/help/images/member-card.webp')['status'] === 200);
check('B3 review: /help never serves admin screenshots (staff route still does)', (new Browser($base))->get('/help/images/admin-members.webp')['status'] === 404
    && $memberB->get('/help/images/admin-health-paths.webp')['status'] === 404 && $admin->get('/help/images/admin-audit.webp')['status'] === 404
    && $admin->get('/admin/help/images/admin-audit.webp')['status'] === 200);
check('B3: the footer links to /help', str_contains($r['body'], '<a href="/help">Help</a>'));
$r = $admin->submit('/admin/settings', '/admin/settings', ['site_name' => 'Smoke Test Society', 'timezone' => 'Europe/London', 'support_panel' => '1', 'help_member_guide' => 'members', 'impersonation_notify' => '1']);
check('B3 review: changing the support settings is audited', str_contains((string) q_val("SELECT summary FROM audit_log WHERE action = 'update' AND item_type = 'settings' ORDER BY id DESC LIMIT 1"), 'member guide: members'));
check('B3: super admin makes the member guide members-only', $r['status'] === 303 && setting_fresh('help.member_guide') === 'members' && setting_fresh('impersonation.notify') === true);
$v = (new Browser($base))->get('/help');
check('B3: members-only guide sends visitors to log in, members can read it', $v['status'] === 303 && str_starts_with($v['location'], '/login') && $memberB->get('/help')['status'] === 200
    && (new Browser($base))->get('/help/images/member-card.webp')['status'] === 303);
$admin->submit('/admin/settings', '/admin/settings', ['site_name' => 'Smoke Test Society', 'timezone' => 'Europe/London', 'support_panel' => '1', 'help_member_guide' => 'public', 'impersonation_notify' => '1']);
$admin->submit('/admin/settings', '/admin/settings', ['site_name' => 'Smoke Test Society', 'timezone' => 'Europe/London', 'support_panel' => '1', 'help_member_guide' => 'public']);
check('B3 review: switching the member notice off is audited', str_contains((string) q_val("SELECT summary FROM audit_log WHERE action = 'update' AND item_type = 'settings' ORDER BY id DESC LIMIT 1"), 'notice when an admin views an account: off'));
$admin->submit('/admin/settings', '/admin/settings', ['site_name' => 'Smoke Test Society', 'timezone' => 'Europe/London', 'support_panel' => '1', 'help_member_guide' => 'public', 'impersonation_notify' => '1']);
$admin->submit('/admin/settings', '/admin/settings', ['site_name' => 'Smoke Test Society', 'timezone' => 'Europe/London']);
check('B3: a settings form without the support panel leaves those settings alone', setting_fresh('help.member_guide') === 'public' && setting_fresh('impersonation.notify') === true);

// ---- B2: log in as a member -------------------------------------------------------
$impUid = create_user('imani.view@example.test', 'imani-password-1', 'member', 'Imani View', 'active');
$impMid = db_insert('members', ['user_id' => $impUid, 'first_name' => 'Imani', 'last_name' => 'View', 'membership_type' => 'Standard', 'birth_day' => 3, 'birth_month' => 4, 'member_number' => 'SMK-26-0900', 'prefs' => json_encode(['newsletters' => false])]);
$impMember = login_as($base, 'imani.view@example.test', 'imani-password-1');
$h = $admin->get('/admin/members/' . $impMid)['body'];
check('B2: the member page offers "Log in as this member"', str_contains($h, 'href="/admin/impersonate/' . $impUid . '"'));
check('B2: the members list has a "Log in as" row action', str_contains($admin->get('/admin/members?q=imani')['body'], 'href="/admin/impersonate/' . $impUid . '"'));
check('B2: the Users & roles form lists the impersonate permission (10 keys)', str_contains($admin->get('/admin/users/new')['body'], 'value="impersonate"') && substr_count($admin->get('/admin/users/new')['body'], 'name="permissions[]"') === 10);
status_is($admin, '/admin/impersonate/' . $impUid, 200, 'Log in as this member');
$staffId = (int) q_val("SELECT id FROM users WHERE role = 'super_admin' ORDER BY id LIMIT 1");
$r = $admin->get('/admin/impersonate/' . $staffId);
// Another staff account (not the viewer, whose own email is in the profile menu).
$otherStaff = create_user('quiet.staffer@example.test', 'quiet-staffer-pass', 'admin', 'Quiet Staffer', 'active', ['radio']);
$refusal = $admin->get('/admin/impersonate/' . $otherStaff);
check('B2: staff accounts cannot be impersonated (generic refusal, no start button)', $r['status'] === 422 && str_contains($r['body'], 'cannot be viewed as a member') && !str_contains($r['body'], 'action="/admin/impersonate/'));
check('B2 review: the refusal shows nothing about the account', $refusal['status'] === 422 && !str_contains($refusal['body'], 'quiet.staffer@example.test') && !str_contains($refusal['body'], 'Quiet Staffer') && !str_contains($refusal['body'], 'badge active'));
check('B2 review: the refusal for an unknown id is a 404', $admin->get('/admin/impersonate/987654')['status'] === 404);
$r = $admin->submit('/admin/impersonate/' . $impUid, '/admin/impersonate/' . $staffId, []);
check('B2: posting a staff account is refused and nothing changes', $r['status'] === 303 && $admin->get('/admin')['status'] === 200 && (int) q_val('SELECT COUNT(*) FROM impersonations WHERE user_id = ?', [$staffId]) === 0);
check('B2: start without a CSRF token is refused (419)', $admin->post('/admin/impersonate/' . $impUid, ['reason' => 'x'])['status'] === 419);
$noImp = login_as($base, 'matrix-members@example.test', 'matrix-password-1');
$impOnly = login_as($base, 'matrix-impersonate@example.test', 'matrix-password-1');
$r = $impOnly->get('/admin/impersonate/' . $impUid);
check('B2 review: without the members permission the confirm page hides the email', $r['status'] === 200 && str_contains($r['body'], 'Log in as Imani View') && !str_contains($r['body'], 'imani.view@example.test'));
check('B2 review: with the members permission the email is shown', str_contains($admin->get('/admin/impersonate/' . $impUid)['body'], 'imani.view@example.test'));
check('B2: an admin without the permission gets 403', $noImp->get('/admin/impersonate/' . $impUid)['status'] === 403 && !str_contains($noImp->get('/admin/members/' . $impMid)['body'], '/admin/impersonate/'));

$supporter = login_as($base, 'matrix-impersonate@example.test', 'matrix-password-1');
q("INSERT INTO admin_permissions (user_id, permission) SELECT id, 'members' FROM users WHERE email = 'matrix-impersonate@example.test'");
$supporterId = (int) q_val("SELECT id FROM users WHERE email = 'matrix-impersonate@example.test'");
$mailsBefore = count(mail_files());
$token = $supporter->csrf('/admin/impersonate/' . $impUid);
$r = $supporter->post('/admin/impersonate/' . $impUid, ['_csrf' => $token, 'reason' => 'Smoke: cannot find card']);
$newSid = set_session_cookie($r);
check('B2: starting redirects to the member dashboard with a new session id', $r['status'] === 303 && $r['location'] === '/account' && $newSid !== '');
$r = $supporter->get('/account');
check('B2: the banner shows who is being viewed, with Return to admin (CSRF form)', $r['status'] === 200 && str_contains($r['body'], 'class="imp-banner"') && str_contains($r['body'], 'You are viewing as <strong>Imani View</strong>')
    && str_contains($r['body'], 'SMK-26-0900') && str_contains($r['body'], 'action="/impersonation/stop"'));
check('B2: the banner is on other pages too (event list, card print)', str_contains($supporter->get('/events')['body'], 'class="imp-banner"') && str_contains($supporter->get('/account/card/print')['body'], 'class="imp-banner"'));
$notice = newest_mail_to('imani.view@example.test', $mailsBefore);
check('B2: the member is emailed a short notice', $notice !== null && str_contains($notice, 'administrator') && str_contains($notice, 'viewed your account'));
$audit = q_one("SELECT * FROM audit_log WHERE action = 'impersonate_start' ORDER BY id DESC LIMIT 1");
check('B2: the start is audited (admin, member, reason, IP)', (int) $audit['user_id'] === $supporterId && $audit['item_type'] === 'member' && (int) $audit['item_id'] === $impMid && str_contains($audit['summary'], 'member notice: sent') && str_contains($audit['summary'], 'Smoke: cannot find card') && $audit['ip'] !== '');
$t = meta_csrf($supporter, '/account');
$blocked = [];
foreach ([['GET', '/admin'], ['GET', '/admin/members'], ['GET', '/account/privacy/export'], ['GET', '/account/jobs/new'],
    ['POST', '/account/password'], ['POST', '/account/email'], ['POST', '/account/privacy/delete'], ['POST', '/account/preferences'],
    ['POST', '/account/newsletter/opt-in'], ['POST', '/like'], ['POST', '/comments'], ['POST', '/account/jobs'], ['POST', '/account/profile'],
    ['POST', '/admin/impersonate/' . $impUid]] as [$m, $path]) {
    $s = $m === 'GET' ? $supporter->get($path)['status'] : $supporter->post($path, ['_csrf' => $t, 'current' => 'x', 'password' => 'yyyyyyyyyyyy', 'new_email' => 'evil@example.test', 'first_name' => 'Hacked'])['status'];
    if ($s !== 403) {
        $blocked[] = "$m $path -> $s";
    }
}
check('B2: restricted routes answer 403 while impersonating (read only)', $blocked === [], implode('; ', $blocked));
check('B2: blocked pages explain why', str_contains($supporter->get('/admin')['body'], 'Return to admin'));
check('B2: nothing was changed by the blocked requests', q_val('SELECT email FROM users WHERE id = ?', [$impUid]) === 'imani.view@example.test' && q_val('SELECT first_name FROM members WHERE id = ?', [$impMid]) === 'Imani');
check('B2: reading works (profile, card, notices, jobs)', $supporter->get('/account/profile')['status'] === 200 && $supporter->get('/account/card')['status'] === 200 && $supporter->get('/account/notices')['status'] === 200 && $supporter->get('/jobs')['status'] === 200);
check('B2: stop without a CSRF token is refused (419)', $supporter->post('/impersonation/stop', [])['status'] === 419);
$r = $supporter->post('/impersonation/stop', ['_csrf' => meta_csrf($supporter, '/account')]);
check('B2: Return to admin goes back to the member admin page with a new session id', $r['status'] === 303 && $r['location'] === '/admin/members/' . $impMid && set_session_cookie($r) !== '' && set_session_cookie($r) !== $newSid);
check('B2: the admin session is back; no banner', $supporter->get('/admin/members/' . $impMid)['status'] === 200 && !str_contains($supporter->get('/')['body'], 'imp-banner'));
$end = q_one("SELECT * FROM audit_log WHERE action = 'impersonate_end' ORDER BY id DESC LIMIT 1");
check('B2: the end is audited with the duration', (int) $end['user_id'] === $supporterId && str_contains($end['summary'], 'Stopped viewing as Imani View (SMK-26-0900) after 0 min: returned to admin')
    && q_val('SELECT end_reason FROM impersonations WHERE admin_id = ? ORDER BY id DESC LIMIT 1', [$supporterId]) === 'returned');
check('B2: the member\'s own session was never touched', $impMember->get('/account')['status'] === 200);

// Allow changes: a profile edit is made and logged as the admin on behalf of the member.
$supporter->post('/admin/impersonate/' . $impUid, ['_csrf' => $supporter->csrf('/admin/impersonate/' . $impUid), 'allow_changes' => '1']);
$prof = $supporter->get('/account/profile')['body'];
preg_match_all('/<input[^>]+name="([a-z_]+)"[^>]*value="([^"]*)"/', $prof, $pm, PREG_SET_ORDER);
$fields = [];
foreach ($pm as [, $n, $val]) {
    $fields[$n] = html_entity_decode($val, ENT_QUOTES);
}
foreach (['birth_day' => '3', 'birth_month' => '4', 'res_country' => 'GB'] as $k => $d) {
    $fields[$k] = ($fields[$k] ?? '') !== '' ? $fields[$k] : $d;
}
$fields['res_city'] = 'Changed by support';
$fields['_csrf'] = meta_csrf($supporter, '/account/profile');
$r = $supporter->post('/account/profile', $fields);
$change = q_one("SELECT * FROM audit_log WHERE action = 'profile_update' ORDER BY id DESC LIMIT 1");
check('B2: with "allow changes" a profile edit works and is logged as the admin on behalf of the member', $r['status'] === 303 && q_val('SELECT city FROM members WHERE id = ?', [$impMid]) === 'Changed by support'
    && (int) $change['user_id'] === $supporterId && (int) $change['on_behalf_of'] === $impUid);
check('B2: password changes stay blocked even with "allow changes"', $supporter->post('/account/password', ['_csrf' => meta_csrf($supporter, '/account')])['status'] === 403);
check('B2: the audit log shows the change "as" the member', str_contains($admin->get('/admin/audit?action=profile_update')['body'], 'as Imani View'));

// Revoking the admin's session ends the impersonation and logs everyone out.
user_end_sessions($supporterId);
$r = $supporter->get('/account');
check('B2: a revoked admin session ends the impersonation and logs out', $r['status'] === 303 && $r['location'] === '/login' && $supporter->get('/admin')['status'] === 303
    && q_val('SELECT end_reason FROM impersonations WHERE admin_id = ? ORDER BY id DESC LIMIT 1', [$supporterId]) === 'admin_session_ended');

// Logging out while impersonating ends both sessions.
$supporter = login_as($base, 'matrix-impersonate@example.test', 'matrix-password-1');
$supporter->post('/admin/impersonate/' . $impUid, ['_csrf' => $supporter->csrf('/admin/impersonate/' . $impUid)]);
$r = $supporter->post('/logout', ['_csrf' => meta_csrf($supporter, '/account')]);
check('B2: logging out while impersonating ends both sessions', $r['status'] === 303 && $supporter->get('/account')['status'] === 303 && $supporter->get('/admin')['status'] === 303
    && q_val('SELECT end_reason FROM impersonations WHERE admin_id = ? ORDER BY id DESC LIMIT 1', [$supporterId]) === 'logout');

// Group accounts can be viewed from the group screen.
$gUid = create_user('smoke-group-imp@example.test', 'group-password-1', 'group', 'Smoke Imp Group', 'active');
$gid = db_insert('groups_orgs', ['user_id' => $gUid, 'name' => 'Smoke Imp Group', 'slug' => 'smoke-imp-group', 'status' => 'active', 'group_number' => 'GRP-26-0900', 'contact_person' => 'Gina']);
check('B2: a group page offers "Log in as this group"', str_contains($admin->get('/admin/groups/' . $gid . '/edit')['body'], 'href="/admin/impersonate/' . $gUid . '"'));
