<?php
declare(strict_types=1);

namespace Site;

use Core\UploadException;

/**
 * Public sign-up (§4.1, §4.2). D7: no admin approval: verifying the email
 * activates the account and assigns the member or group number.
 */
final class Join
{
    private const MAX_PER_HOUR = 10;

    public static function form(array $p): void
    {
        if (($u = current_user()) !== null) {
            redirect(home_for($u));
        }
        self::render([], [], 200);
    }

    private static function render(array $values, array $errors, int $status): void
    {
        render('site/join', [
            'title' => 'Join us', 'values' => $values, 'errors' => $errors, 'settings' => member_settings(),
            'fields' => custom_fields('member', true), 'free_left' => free_places_left(), 'noindex' => false,
            'meta_description' => 'Become a member: sign up online in a few minutes.',
        ], 'site', $status);
    }

    /** Shared account fields: email, password, consent. */
    private static function accountErrors(array $in): array
    {
        $errors = [];
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif (q_val('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
            $errors['email'] = 'An account already uses this email. Log in, or reset your password if you have forgotten it.';
        }
        $password = is_string($in['password'] ?? null) ? $in['password'] : '';
        if (($problem = password_problem($password)) !== null) {
            $errors['password'] = $problem;
        } elseif (!hash_equals($password, is_string($in['password_confirm'] ?? null) ? $in['password_confirm'] : '')) {
            $errors['password_confirm'] = 'The two passwords do not match.';
        }
        if (($in['consent'] ?? '') !== '1') {
            $errors['consent'] = 'Please agree to the terms and privacy policy to continue.';
        }
        return $errors;
    }

    /**
     * Honeypot + time check + per-IP rate limit.
     * @return array{0:array<string,string>,1:int}|null [errors, status] when tripped
     */
    private static function guard(string $form): ?array
    {
        $problem = form_guard_problem($form, $_POST);
        if ($problem !== null) {
            error_log("Sign-up ($form) rejected: $problem from " . client_ip());
            return [['form' => $problem === 'too-fast' ? 'That was very quick. Please check the form and send it again.' : 'The form could not be sent. Please try again.'], 422];
        }
        if (form_rate_limited($form, self::MAX_PER_HOUR, 3600)) {
            return [['form' => 'Too many sign-ups from your connection in the last hour. Please try again later.'], 429];
        }
        return null;
    }

    public static function submit(array $p): void
    {
        csrf_check();
        $values = array_map(static fn ($v) => is_string($v) ? trim($v) : '', $_POST);
        unset($values['password'], $values['password_confirm'], $values['_csrf']);
        if (($tripped = self::guard('join')) !== null) {
            self::render($values, ...$tripped);
            return;
        }
        $errors = self::accountErrors($_POST);
        [$profile, $pErrors] = member_profile_validate($_POST);
        [$custom, $cErrors] = custom_fields_validate(custom_fields('member', true), $_POST);
        $errors += $pErrors + $cErrors;
        $photo = null;
        $file = $_FILES['photo'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $errors['photo'] = 'Add a photo of yourself for your member card.';
        } elseif (!$errors) {
            try {
                $photo = media_upload_from_files($file);
            } catch (UploadException $e) {
                $errors['photo'] = $e->getMessage();
            }
        }
        if ($errors) {
            self::render($values, $errors, 422);
            return;
        }
        try {
            $r = member_signup(strtolower((string) $_POST['email']), (string) $_POST['password'], $profile, $custom, ($_POST['newsletters'] ?? '') === '1', $photo);
        } catch (UploadException $e) {
            self::render($values, ['photo' => $e->getMessage()], 422);
            return;
        }
        send_template_mail(strtolower((string) $_POST['email']), $profile['first_name'], 'verify_email', [
            'name' => $profile['first_name'], 'verify_url' => base_url('/join/verify/' . $r['token']), 'days' => VERIFY_DAYS,
        ]);
        login_user($r['user_id']);
        flash('Almost done! We have emailed you a link: open it to verify your address and get your member number.', 'info');
        redirect('/account');
    }

    public static function groupForm(array $p): void
    {
        if (($u = current_user()) !== null) {
            redirect(home_for($u));
        }
        self::renderGroup([], [], 200);
    }

    private static function renderGroup(array $values, array $errors, int $status): void
    {
        render('site/join_group', [
            'title' => 'Register a group', 'values' => $values, 'errors' => $errors, 'settings' => member_settings(),
            'fields' => custom_fields('group', true), 'meta_description' => 'Register your association or community group.',
        ], 'site', $status);
    }

    public static function groupSubmit(array $p): void
    {
        csrf_check();
        $values = array_map(static fn ($v) => is_string($v) ? trim($v) : '', $_POST);
        unset($values['password'], $values['password_confirm'], $values['_csrf']);
        if (($tripped = self::guard('join_group')) !== null) {
            self::renderGroup($values, ...$tripped);
            return;
        }
        $errors = self::accountErrors($_POST);
        $g = [];
        foreach (['name' => [200, true], 'contact_person' => [160, true], 'phone' => [40, false], 'address' => [300, false], 'country' => [80, true]] as $k => [$max, $req]) {
            $g[$k] = (string) ($values[$k] ?? '');
            if (($req && $g[$k] === '') || mb_strlen($g[$k]) > $max) {
                $errors[$k] = $req ? 'This is required (up to ' . $max . ' characters).' : "Keep this under $max characters.";
            }
            if (!$req && $g[$k] === '') {
                $g[$k] = null;
            }
        }
        [$custom, $cErrors] = custom_fields_validate(custom_fields('group', true), $_POST);
        $errors += $cErrors;
        $logoId = null;
        $file = $_FILES['logo'] ?? null;
        if (!$errors && is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $u = media_upload_from_files($file);
                $logoId = media_store($u['tmp'], $u['name'], $u['size'], null, true, ['kind' => 'image', 'folder' => 'groups', 'alt' => $g['name'] . ' logo']);
            } catch (UploadException $e) {
                $errors['logo'] = $e->getMessage();
            }
        }
        if ($errors) {
            self::renderGroup($values, $errors, 422);
            return;
        }
        $r = group_signup(strtolower((string) $_POST['email']), (string) $_POST['password'], $g, $custom, $logoId);
        if ($logoId !== null) {
            q('UPDATE media SET uploaded_by = ? WHERE id = ?', [$r['user_id'], $logoId]);
        }
        send_template_mail(strtolower((string) $_POST['email']), $g['contact_person'], 'verify_email', [
            'name' => $g['contact_person'], 'verify_url' => base_url('/join/verify/' . $r['token']), 'days' => VERIFY_DAYS, 'group' => $g['name'],
        ]);
        login_user($r['user_id']);
        flash('Almost done! We have emailed a link to verify the address. Once verified, your group gets its ID and appears in the directory.', 'info');
        redirect('/account');
    }

    /** GET: confirm button (mail scanners that open links do not verify anyone). */
    public static function verifyForm(array $p): void
    {
        $userId = user_token_find($p['token'], 'verify');
        render('site/verify_email', ['title' => 'Verify your email', 'token' => $userId !== null ? $p['token'] : null, 'noindex' => true], 'site', $userId !== null ? 200 : 404);
    }

    public static function verify(array $p): void
    {
        csrf_check();
        $userId = account_verify($p['token']);
        if ($userId === null) {
            render('site/verify_email', ['title' => 'Verify your email', 'token' => null, 'noindex' => true], 'site', 404);
            return;
        }
        $user = q_one('SELECT * FROM users WHERE id = ?', [$userId]);
        login_user($userId);
        audit('verify', $user['role'], $userId, (string) $user['email']);
        if ($user['role'] === 'group') {
            $g = q_one('SELECT * FROM groups_orgs WHERE user_id = ?', [$userId]);
            send_template_mail((string) $user['email'], (string) $user['display_name'], 'welcome_group', ['name' => (string) ($g['contact_person'] ?? ''), 'group' => (string) ($g['name'] ?? ''), 'number' => (string) ($g['group_number'] ?? '')]);
            flash('Email verified. Your group ID is ' . ($g['group_number'] ?? '') . ' and your group is now listed in the directory.');
        } else {
            $m = member_for_user($userId);
            send_template_mail((string) $user['email'], (string) ($m['first_name'] ?? ''), 'welcome_member', [
                'name' => (string) ($m['first_name'] ?? ''), 'member_number' => (string) ($m['member_number'] ?? ''), 'card_url' => base_url('/account/card'),
            ]);
            flash('Email verified: welcome! Your member number is ' . ($m['member_number'] ?? '') . '. Your digital member card is ready.');
        }
        redirect('/account');
    }

    /** POST /account/verify/resend for logged-in unverified accounts. */
    public static function resend(array $p): void
    {
        csrf_check();
        $user = require_login();
        if ($user['status'] !== 'unverified') {
            redirect('/account');
        }
        $bucket = 'resend:' . $user['id'];
        if (rate_limited($bucket, 3, 3600)) {
            flash('We have already sent a few links. Please check your spam folder or try again in an hour.', 'error');
            redirect('/account');
        }
        rate_hit($bucket);
        $name = $user['role'] === 'group' ? (string) q_val('SELECT contact_person FROM groups_orgs WHERE user_id = ?', [$user['id']]) : (string) (member_for_user((int) $user['id'])['first_name'] ?? '');
        send_template_mail((string) $user['email'], $name, 'verify_email', ['name' => $name, 'verify_url' => base_url('/join/verify/' . user_token_create((int) $user['id'], 'verify')), 'days' => VERIFY_DAYS]);
        flash('A new verification link is on its way to ' . $user['email'] . '.', 'info');
        redirect('/account');
    }
}
