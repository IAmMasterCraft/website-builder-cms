<?php
declare(strict_types=1);

namespace Account;

use Core\UploadException;

/**
 * Member and group dashboard (§4.4). Unverified accounts may only see the
 * home page (with "check your email"), edit their profile and password.
 */
final class Account
{
    /** Logged-in member or group (staff go to /admin). */
    private static function user(): array
    {
        $user = require_login();
        if (is_staff($user)) {
            redirect('/admin');
        }
        return $user;
    }

    /** Pages that need a verified (active) account. */
    private static function activeUser(): array
    {
        $user = self::user();
        if ($user['status'] !== 'active') {
            flash('Please verify your email first: open the link we sent you, or send a new one below.', 'info');
            redirect('/account');
        }
        return $user;
    }

    private static function group(array $user): ?array
    {
        return $user['role'] === 'group' ? q_one('SELECT * FROM groups_orgs WHERE user_id = ?', [$user['id']]) : null;
    }

    private static function page(string $template, string $title, string $active, array $data, int $status = 200): void
    {
        $user = $data['user'];
        $data += ['title' => $title, 'active' => $active, 'noindex' => true, 'is_group' => $user['role'] === 'group', 'extra_css' => ['assets/css/card.css']];
        $data['unread_notices'] = $user['status'] === 'active' ? notices_unread_count((int) $user['id']) : 0;
        // A5: emails to this address bounce: ask the member to update it.
        $sup = email_suppression((string) $user['email']);
        $data['bounce_suppression'] = $sup !== null && in_array($sup['reason'], EMAIL_UNDELIVERABLE_REASONS, true) ? $sup : null;
        render('account/_shell', $data + ['inner' => view('account/' . $template, $data)], 'site', $status);
    }

    public static function home(array $p): void
    {
        $user = self::user();
        $member = member_for_user((int) $user['id']);
        $group = self::group($user);
        $data = ['user' => $user, 'member' => $member, 'group' => $group];
        if ($user['status'] === 'active') {
            [$cond, $params] = event_when_sql('upcoming', new \DateTimeImmutable('now', utc()), site_tz());
            $data['my_events'] = q_all(
                "SELECT e.*, r.quantity, r.status AS reg_status FROM event_registrations r JOIN events e ON e.id = r.event_id
                  WHERE (r.user_id = ? OR r.email = ?) AND r.status <> 'cancelled' AND $cond ORDER BY e.start_at LIMIT 5",
                array_merge([$user['id'], $user['email']], $params)
            );
            $data['events'] = q_all('SELECT e.* FROM events e WHERE ' . published_sql('e') . " AND $cond ORDER BY e.start_at LIMIT 3", $params);
            $data['posts'] = posts_latest(3, $user);
            $data['celebrants'] = birthday_celebrants('today');
            $data['card'] = $member !== null ? card_data($member) : null;
            $data['is_birthday'] = $member !== null && in_array([(int) $member['birth_month'], (int) $member['birth_day']], birthday_days('today'), true);
            $data['notices'] = array_values(array_filter(notices_for_user((int) $user['id'], 20), static fn (array $n): bool => $n['read_at'] === null));
            $data['upcoming_birthdays'] = birthday_celebrants('week');
            $data['optin_prompt'] = $member !== null && newsletter_prompt_due($member, (string) $user['email']);
        }
        self::page('home', 'My account', 'home', $data);
    }

    // ---- Profile ---------------------------------------------------------------

    public static function profile(array $p): void
    {
        $user = self::user();
        $old = take_old_input();
        if ($user['role'] === 'group') {
            $group = self::group($user);
            $cf = [];
            foreach ($group ? q_all('SELECT f.field_key, v.value FROM group_field_values v JOIN custom_fields f ON f.id = v.field_id WHERE v.group_id = ?', [$group['id']]) : [] as $cv) {
                $cf['cf_' . $cv['field_key']] = (string) $cv['value'];
            }
            self::page('profile_group', 'Group profile', 'profile', ['user' => $user, 'group' => $group, 'values' => $old['values'] + $cf + ($group ?? []), 'errors' => $old['errors'],
                'fields' => custom_fields('group', true), 'logo' => media_find(($group['logo_media_id'] ?? null) ? (int) $group['logo_media_id'] : null)]);
            return;
        }
        $member = member_for_user((int) $user['id']);
        if ($member === null) {
            abort(404);
        }
        $values = $member + member_location_form_values((int) $member['id']);
        foreach (custom_fields('member', true) as $f) {
            $values['cf_' . $f['field_key']] = custom_values_for_member((int) $member['id'])[(int) $f['id']] ?? '';
        }
        self::page('profile', 'My profile', 'profile', [
            'user' => $user, 'member' => $member, 'values' => $old['values'] + $values, 'errors' => $old['errors'],
            'settings' => member_settings(), 'fields' => custom_fields('member', true),
            'photo' => media_find($member['photo_media_id'] ? (int) $member['photo_media_id'] : null),
        ]);
    }

    public static function profileSave(array $p): void
    {
        csrf_check();
        $user = self::user();
        if ($user['role'] === 'group') {
            self::groupSave($user);
            return;
        }
        $member = member_for_user((int) $user['id']);
        if ($member === null) {
            abort(404);
        }
        if (isset($_POST['_loc_refresh'])) {
            // C4 without JavaScript: show the next list, nothing saved yet.
            remember_input($_POST, []);
            redirect('/account/profile');
        }
        [$profile, $errors, $locations] = member_profile_validate($_POST);
        [$custom, $cErrors, $cLocations] = custom_fields_validate(custom_fields('member', true), $_POST);
        $errors += $cErrors;
        $locations += $cLocations;
        if ($errors) {
            remember_input($_POST, $errors);
            flash('Please check the highlighted fields.', 'error');
            redirect('/account/profile');
        }
        db_tx(static function () use ($member, $profile, $custom, $user, $locations): void {
            db_update('members', (int) $member['id'], $profile);
            custom_values_save('member_field_values', 'member_id', (int) $member['id'], $custom);
            member_locations_save((int) $member['id'], $locations);
            q('UPDATE users SET display_name = ? WHERE id = ?', [mb_substr($profile['first_name'] . ' ' . $profile['last_name'], 0, 120), $user['id']]);
        });
        // While an admin views the account (B2), audit() records the admin acting on behalf of the member.
        audit('profile_update', 'member', (int) $member['id'], 'profile details');
        flash('Profile saved.');
        redirect('/account/profile');
    }

    private static function groupSave(array $user): void
    {
        $group = self::group($user);
        if ($group === null) {
            abort(404);
        }
        $g = [];
        $errors = [];
        foreach (['name' => [200, true], 'contact_person' => [160, true], 'phone' => [40, false], 'address' => [300, false], 'country' => [80, true], 'website' => [500, false]] as $k => [$max, $req]) {
            $g[$k] = input($k);
            if (($req && $g[$k] === '') || mb_strlen($g[$k]) > $max) {
                $errors[$k] = $req ? 'This is required.' : "Keep this under $max characters.";
            }
        }
        if ($g['website'] !== '' && !preg_match('#^https?://[^\s"<>]+$#i', $g['website'])) {
            $errors['website'] = 'Enter a full web address starting with https://';
        }
        $description = input('description');
        if (mb_strlen($description) > 2000) {
            $errors['description'] = 'Keep the description under 2,000 characters.';
        }
        [$custom, $cErrors] = custom_fields_validate(custom_fields('group', true), $_POST);
        $errors += $cErrors;
        $file = $_FILES['logo'] ?? null;
        $logoId = null;
        if (!$errors && is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $u = media_upload_from_files($file);
                $logoId = media_store($u['tmp'], $u['name'], $u['size'], (int) $user['id'], true, ['kind' => 'image', 'folder' => 'groups', 'alt' => $g['name'] . ' logo']);
            } catch (UploadException $e) {
                $errors['logo'] = $e->getMessage();
            }
        }
        if ($errors) {
            remember_input($_POST, $errors);
            flash('Please check the highlighted fields.', 'error');
            redirect('/account/profile');
        }
        $data = array_map(static fn (string $v): ?string => $v === '' ? null : $v, $g) + ['description' => $description !== '' ? '<p>' . nl2br(e($description), false) . '</p>' : null];
        $data['name'] = $g['name'];
        $data['contact_person'] = $g['contact_person'];
        if ($logoId !== null) {
            $data['logo_media_id'] = $logoId;
        }
        db_update('groups_orgs', (int) $group['id'], $data);
        custom_values_save('group_field_values', 'group_id', (int) $group['id'], $custom);
        q('UPDATE users SET display_name = ? WHERE id = ?', [mb_substr($g['name'], 0, 120), $user['id']]);
        audit('profile_update', 'group', (int) $group['id'], 'group profile');
        flash('Group profile saved.');
        redirect('/account/profile');
    }

    public static function photo(array $p): void
    {
        csrf_check();
        $user = self::user();
        $member = member_for_user((int) $user['id']);
        if ($member === null) {
            abort(404);
        }
        $file = $_FILES['photo'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            flash('Choose a photo to upload.', 'error');
            redirect('/account/profile');
        }
        try {
            $u = media_upload_from_files($file);
            $newId = member_photo_store($u['tmp'], $u['name'], $u['size'], (int) $user['id']);
        } catch (UploadException $e) {
            flash($e->getMessage(), 'error');
            redirect('/account/profile');
        }
        q('UPDATE members SET photo_media_id = ? WHERE id = ?', [$newId, $member['id']]);
        if ($member['photo_media_id']) {
            $old = media_find((int) $member['photo_media_id']);
            if ($old !== null) {
                media_delete_files($old);
                q('DELETE FROM media WHERE id = ?', [$old['id']]);
            }
        }
        audit('photo', 'member', (int) $member['id'], 'profile photo replaced');
        flash('Photo updated. Your member card now shows the new photo.');
        redirect('/account/profile');
    }

    public static function password(array $p): void
    {
        $user = self::user();
        $old = take_old_input();
        $pending = q_val('SELECT pending_email FROM users WHERE id = ?', [$user['id']]);
        self::page('password', 'Email and password', 'password', ['user' => $user, 'errors' => $old['errors'], 'values' => $old['values'], 'pending_email' => is_string($pending) ? $pending : null]);
    }

    public static function passwordSave(array $p): void
    {
        csrf_check();
        $user = self::user();
        $current = is_string($_POST['current'] ?? null) ? $_POST['current'] : '';
        $new = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $errors = [];
        if (!confirm_current_password((int) $user['id'], $current)) {
            $errors['current'] = 'That is not your current password (after 10 wrong tries, wait an hour).';
        }
        if (($problem = password_problem($new)) !== null) {
            $errors['password'] = $problem;
        } elseif (!hash_equals($new, is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '')) {
            $errors['password_confirm'] = 'The two passwords do not match.';
        }
        if ($errors) {
            remember_input([], $errors);
            redirect('/account/password');
        }
        q('UPDATE users SET password_hash = ? WHERE id = ?', [hash_password($new), $user['id']]);
        user_end_sessions((int) $user['id']);
        login_user((int) $user['id']);
        flash('Password changed.');
        redirect('/account/password');
    }

    // ---- ID card ---------------------------------------------------------------

    /** POST /account/email: ask to change the login email (password confirmed). */
    public static function emailRequest(array $p): void
    {
        csrf_check();
        $user = self::user();
        $errors = [];
        $current = is_string($_POST['email_password'] ?? null) ? $_POST['email_password'] : '';
        if (!confirm_current_password((int) $user['id'], $current)) {
            $errors['email_password'] = 'That is not your current password (after 10 wrong tries, wait an hour).';
        }
        $bucket = 'email-change:' . $user['id'];
        if (!$errors && rate_limited($bucket, 5, 3600)) {
            $errors['new_email'] = 'You have asked several times in the last hour. Please try again later.';
        }
        $new = strtolower(input('new_email'));
        if (!$errors && ($problem = email_change_problem((int) $user['id'], $new)) !== null) {
            $errors['new_email'] = $problem;
        }
        if ($errors) {
            remember_input(['new_email' => $new], $errors);
            redirect('/account/password#email');
        }
        rate_hit($bucket);
        $result = email_change_request($user, $new);
        session_keep_current((int) $user['id']);
        audit('email_change_request', $user['role'], (int) $user['id'], $result === 'changed' ? 'unverified account now uses a new address' : 'confirmation sent to the new address');
        flash($result === 'changed'
            ? 'Your email is now ' . $new . '. We sent a new verification link there.'
            : 'Nearly done: open the link we sent to ' . $new . ' to confirm it. Until then you keep logging in with ' . $user['email'] . '.', 'info');
        redirect('/account/password');
    }

    /** POST /account/email/cancel: drop a pending change. */
    public static function emailCancel(array $p): void
    {
        csrf_check();
        $user = self::user();
        q('UPDATE users SET pending_email = NULL WHERE id = ?', [$user['id']]);
        q("DELETE FROM user_tokens WHERE user_id = ? AND purpose = 'email_change'", [$user['id']]);
        flash('The email change was cancelled.');
        redirect('/account/password');
    }

    /** GET /account/email/confirm/{token}: a button, so mail scanners cannot confirm. */
    public static function emailConfirmForm(array $p): void
    {
        $id = email_change_find($p['token']);
        $pending = $id !== null ? (string) q_val('SELECT pending_email FROM users WHERE id = ?', [$id]) : null;
        header('Cache-Control: no-store');
        render('site/confirm_email_change', ['title' => 'Confirm your new email', 'token' => $id !== null ? $p['token'] : null, 'pending' => $pending, 'noindex' => true], 'site', $id !== null ? 200 : 404);
    }

    public static function emailConfirm(array $p): void
    {
        csrf_check();
        $done = email_change_confirm($p['token']);
        if ($done === null) {
            render('site/confirm_email_change', ['title' => 'Confirm your new email', 'token' => null, 'pending' => null, 'noindex' => true], 'site', 404);
            return;
        }
        session_keep_current($done['id']);
        $role = (string) q_val('SELECT role FROM users WHERE id = ?', [$done['id']]);
        q('INSERT INTO audit_log (user_id, action, item_type, item_id, summary, ip) VALUES (?, ?, ?, ?, ?, ?)', [$done['id'], 'email_change', $role, $done['id'], email_mask($done['old']) . ' -> ' . email_mask($done['new']), client_ip()]);
        flash('Your email address is now ' . $done['new'] . '. Use it the next time you log in.');
        $me = current_user();
        redirect($me !== null && (int) $me['id'] === $done['id'] ? (is_staff($me) ? '/admin/profile' : '/account/password') : '/login');
    }

    private static function memberWithCard(): array
    {
        $user = self::activeUser();
        $member = member_for_user((int) $user['id']);
        if ($member === null || $member['member_number'] === null) {
            abort(404);
        }
        return [$user, $member];
    }

    public static function card(array $p): void
    {
        [$user, $member] = self::memberWithCard();
        self::page('card', 'My member card', 'card', ['user' => $user, 'member' => $member, 'card' => card_data($member)]);
    }

    public static function cardPrint(array $p): void
    {
        [, $member] = self::memberWithCard();
        render('account/card_print', ['title' => 'Print my member card', 'cards' => [card_data($member)], 'mode' => 'cr80'], 'print');
    }

    // ---- Events, downloads, notices ------------------------------------------

    public static function events(array $p): void
    {
        $user = self::activeUser();
        $rows = q_all(
            'SELECT r.*, e.title, e.slug, e.start_at, e.end_at, e.venue_name, e.online_url, t.name AS ticket_name FROM event_registrations r
               JOIN events e ON e.id = r.event_id LEFT JOIN event_tickets t ON t.id = r.ticket_id
              WHERE r.user_id = ? OR r.email = ? ORDER BY e.start_at DESC',
            [$user['id'], $user['email']]
        );
        $now = utc_now();
        foreach ($rows as &$r) {
            $r['is_future'] = (string) $r['start_at'] > $now;
        }
        unset($r);
        self::page('events', 'My events', 'events', ['user' => $user, 'rows' => $rows]);
    }

    public static function cancelEvent(array $p): void
    {
        csrf_check();
        $user = self::activeUser();
        $reg = q_one('SELECT r.*, e.start_at, e.title FROM event_registrations r JOIN events e ON e.id = r.event_id WHERE r.id = ? AND (r.user_id = ? OR r.email = ?)', [(int) $p['id'], $user['id'], $user['email']]);
        if ($reg === null) {
            abort(404);
        }
        if ($reg['status'] !== 'registered' || (string) $reg['start_at'] <= utc_now()) {
            flash('This registration can no longer be cancelled.', 'error');
            redirect('/account/events');
        }
        q("UPDATE event_registrations SET status = 'cancelled' WHERE id = ?", [$reg['id']]);
        audit('cancel', 'registration', (int) $reg['id'], (string) $reg['title']);
        flash('Your place at “' . $reg['title'] . '” is cancelled. Thank you for letting us know.');
        redirect('/account/events');
    }

    public static function downloads(array $p): void
    {
        $user = self::activeUser();
        $rows = q_all("SELECT d.*, m.mime, m.size, m.path FROM downloads d JOIN media m ON m.id = d.file_media_id WHERE d.visibility = 'members' AND " . published_sql('d') . ' ORDER BY d.category, d.title');
        self::page('downloads', 'Member downloads', 'downloads', ['user' => $user, 'rows' => $rows]);
    }

    public static function notices(array $p): void
    {
        $user = self::activeUser();
        self::page('notices', 'Notices', 'notices', ['user' => $user, 'notices' => notices_for_user((int) $user['id'])]);
    }

    /** POST: mark one notice (id) or all notices read. JSON for the auto-mark from notices.js. */
    public static function noticesRead(array $p): void
    {
        csrf_check();
        $user = self::activeUser();
        $id = input_int('id');
        $n = notice_mark_read((int) $user['id'], $id > 0 ? $id : null);
        if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            json_out(['ok' => true, 'marked' => $n, 'unread' => notices_unread_count((int) $user['id'])]);
        }
        flash($id > 0 ? 'Marked as read.' : 'All notices marked as read.');
        redirect('/account/notices');
    }

    // ---- Preferences and privacy -------------------------------------------------

    public static function preferences(array $p): void
    {
        $user = self::activeUser();
        $member = member_for_user((int) $user['id']);
        self::page('preferences', 'Email preferences', 'preferences', ['user' => $user, 'prefs' => $member !== null ? member_prefs($member) : member_pref_defaults(), 'is_member' => $member !== null]);
    }

    public static function preferencesSave(array $p): void
    {
        csrf_check();
        $user = self::activeUser();
        $member = member_for_user((int) $user['id']);
        if ($member === null) {
            flash('Preferences saved.');
            redirect('/account/preferences');
        }
        $prefs = [];
        foreach (array_keys(member_pref_defaults()) as $k) {
            $prefs[$k] = input($k) === '1';
        }
        q('UPDATE members SET prefs = ? WHERE id = ?', [json_encode($prefs, JSON_THROW_ON_ERROR), $member['id']]);
        if ($prefs['newsletters']) {
            email_resubscribe((string) $user['email']);
        }
        flash('Preferences saved.');
        redirect('/account/preferences');
    }

    /** A2: one-click "switch news emails on" from the dashboard card. */
    public static function newsletterOptIn(array $p): void
    {
        csrf_check();
        $user = self::activeUser();
        $member = member_for_user((int) $user['id']);
        if ($member !== null) {
            newsletter_opt_in($member, (string) $user['email']);
            audit('consent', 'member', (int) $member['id'], 'switched news and event emails on from the dashboard');
            flash('Thank you! You will now get news and event updates by email. You can switch them off at any time in Email preferences.');
        }
        redirect('/account');
    }

    /** A2: hide the card; it may come back after NEWSLETTER_PROMPT_DAYS. */
    public static function newsletterDismiss(array $p): void
    {
        csrf_check();
        $user = self::activeUser();
        $member = member_for_user((int) $user['id']);
        if ($member !== null) {
            q('UPDATE members SET optin_prompt_dismissed_at = UTC_TIMESTAMP() WHERE id = ?', [$member['id']]);
        }
        redirect('/account');
    }

    public static function privacy(array $p): void
    {
        $user = self::user();
        $member = member_for_user((int) $user['id']);
        $old = take_old_input();
        self::page('privacy', 'Your data', 'privacy', ['user' => $user, 'member' => $member, 'errors' => $old['errors']]);
    }

    public static function export(array $p): void
    {
        $user = self::user();
        $json = json_encode(member_export((int) $user['id']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        audit('export', 'account', (int) $user['id'], 'personal data export');
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="my-data-' . gmdate('Y-m-d') . '.json"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo $json;
    }

    public static function deletionRequest(array $p): void
    {
        csrf_check();
        $user = self::user();
        if (!confirm_current_password((int) $user['id'], is_string($_POST['password'] ?? null) ? $_POST['password'] : '')) {
            remember_input([], ['password' => 'Enter your password to confirm.']);
            redirect('/account/privacy');
        }
        $member = member_for_user((int) $user['id']);
        if ($member !== null) {
            q('UPDATE members SET deletion_requested_at = COALESCE(deletion_requested_at, UTC_TIMESTAMP()) WHERE id = ?', [$member['id']]);
        }
        audit('deletion_request', 'account', (int) $user['id'], (string) $user['email']);
        $recipients = array_column(q_all("SELECT email FROM users WHERE role = 'super_admin' AND status = 'active'"), 'email');
        $orgEmail = (string) setting('contact.email', '');
        if ($orgEmail !== '') {
            $recipients[] = $orgEmail;
        }
        foreach (array_unique($recipients) as $to) {
            send_template_mail((string) $to, (string) setting('site.name'), 'deletion_request', [
                'name' => (string) $user['display_name'], 'email' => (string) $user['email'], 'member_number' => (string) ($member['member_number'] ?? ''),
                'admin_url' => base_url($member !== null ? '/admin/members/' . $member['id'] : '/admin/groups'),
            ]);
        }
        flash('Your request has been sent. An administrator will confirm the deletion and let you know. Download your data now if you want a copy.', 'info');
        redirect('/account/privacy');
    }
}
