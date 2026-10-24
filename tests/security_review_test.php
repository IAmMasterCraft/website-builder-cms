<?php
declare(strict_types=1);

// Regression tests for the security review findings (S1-S12, R13).

/** Run $fn with config values replaced, restoring the config afterwards. */
function with_config(array $overrides, callable $fn): mixed
{
    $saved = config_all();
    config_all(array_replace_recursive($saved, $overrides));
    try {
        return $fn();
    } finally {
        config_all($saved);
    }
}

// ---- S3: app_key ------------------------------------------------------------

test('S3 app_key: empty, placeholder and short keys are refused, with no fallback key', function (): void {
    ok(app_key_problem('') !== null);
    ok(app_key_problem('change-me') !== null);
    ok(app_key_problem(str_repeat('a', 31)) !== null);
    same(null, app_key_problem(str_repeat('a', 32)));
    with_config(['app_key' => 'change-me'], function (): void {
        throws(RuntimeException::class, fn () => app_sign('x'));
        throws(RuntimeException::class, fn () => secret_seal('x'));
        $php = health_checks()['PHP'];
        $item = array_values(array_filter($php, static fn (array $i): bool => str_starts_with($i['label'], 'Secret key')))[0];
        same('bad', $item['status'], 'health reports the weak key');
    });
});

test('S3 app_key: signing and secret storage use different HKDF keys', function (): void {
    ok(app_subkey('sign') !== app_subkey('secret-storage'));
    same(32, strlen(app_subkey('sign')));
    same(hash_hmac('sha256', 'data', app_subkey('sign')), app_sign('data'));
    ok(secret_key() === app_subkey('secret-storage'));
});

test('S3 app_key: an SMTP password sealed with the old key is still readable and gets re-encrypted', function (): void {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $legacy = 'sb1:' . base64_encode($nonce . sodium_crypto_secretbox('old-pass', $nonce, secret_key_legacy()));
    setting_set('mail.smtp', ['host' => 'smtp.example.test', 'password' => $legacy]);
    same('old-pass', secret_open($legacy));
    same('old-pass', mail_smtp_settings()['password']);
    ok(str_starts_with((string) setting('mail.smtp')['password'], 'sb2:'), 're-encrypted under the new key');
    same('old-pass', mail_smtp_settings()['password']);
    setting_set('mail.smtp', []);
});

test('S3 app_key: unsubscribe links signed before the key split still verify', function (): void {
    $email = 'legacy@example.test';
    $old = b64url($email) . '.' . substr(hash_hmac('sha256', 'unsubscribe|' . $email, (string) config('app_key')), 0, 32);
    same($email, unsubscribe_verify($old));
    same($email, unsubscribe_verify(unsubscribe_token($email)));
    same(null, unsubscribe_verify(b64url($email) . '.' . str_repeat('0', 32)));
});

// ---- S4/R13: sessions end on credential and status changes ------------------

/** Log $userId in the way login_user does and return the session array ("a browser"). */
function session_for(int $userId): array
{
    act_as($userId);
    return $_SESSION;
}

/** Is the saved session still logged in? */
function session_alive(array $session): bool
{
    $_SESSION = $session;
    return current_user(true) !== null;
}

test('S4 sessions: a session without the current version is not logged in', function (): void {
    $id = fixture_member();
    $s = session_for($id);
    ok(session_alive($s));
    unset($s['sv']);
    ok(!session_alive($s), 'missing version');
    $s = session_for($id);
    user_end_sessions($id);
    ok(!session_alive($s), 'older version');
    same([], array_intersect_key($_SESSION, ['user_id' => 1, 'sv' => 1]), 'login removed from the session');
});

test('S4 sessions: a password reset ends every existing session', function (): void {
    $id = fixture_member();
    $s = session_for($id);
    ok(complete_password_reset(create_password_reset($id), 'a brand new password'));
    ok(!session_alive($s));
});

test('S4 sessions: staff suspension and email change end sessions; other edits do not', function (): void {
    $boss = fixture_admin();
    $admin = fixture_admin('admin', ['content']);
    $s = session_for($admin);
    act_as($boss);
    staff_apply_change($admin, ['permissions' => ['content', 'events']]);
    ok(session_alive($s), 'a permission change keeps the session');
    act_as($boss);
    staff_apply_change($admin, ['email' => 'renamed-' . bin2hex(random_bytes(3)) . '@example.test']);
    ok(!session_alive($s), 'email change');
    $s = session_for($admin);
    act_as($boss);
    staff_apply_change($admin, ['status' => 'suspended']);
    act_as($boss);
    staff_apply_change($admin, ['status' => 'active']);
    ok(!session_alive($s), 'a reactivated account does not bring old sessions back');
});

test('S4 sessions: verifying the email ends sessions opened at sign-up (pre-hijack)', function (): void {
    $r = fixture_signup('prehijack-' . bin2hex(random_bytes(3)) . '@example.test');
    $attacker = session_for($r['user_id']);
    ok(session_alive($attacker));
    same($r['user_id'], account_verify($r['token']));
    ok(!session_alive($attacker));
});

test('S4 sessions: confirming a new email ends other sessions but keeps the confirming browser', function (): void {
    $r = fixture_signup('change-' . bin2hex(random_bytes(3)) . '@example.test');
    account_verify($r['token']);
    $other = session_for($r['user_id']);
    $mine = session_for($r['user_id']);
    email_change_request(current_user(), 'changed-' . bin2hex(random_bytes(3)) . '@example.test');
    $token = user_token_create($r['user_id'], 'email_change', EMAIL_CHANGE_DAYS);
    ok(email_change_confirm($token) !== null);
    session_keep_current($r['user_id']);
    ok(current_user(true) !== null, 'confirming browser stays logged in');
    ok(!session_alive($other));
    unset($mine);
});

// ---- S1/S2/S11: private media and members-only files ------------------------

test('S1 media: a member photo cannot be attached as a download file or cover, even by a super admin', function (): void {
    $r = fixture_signup('photo-' . bin2hex(random_bytes(3)) . '@example.test');
    $photo = (int) q_val('SELECT photo_media_id FROM members WHERE user_id = ?', [$r['user_id']]);
    act_as(fixture_admin());
    [, $errors] = content_validate(content_type('downloads'), ['title' => 'Leak', 'file_media_id' => (string) $photo, 'status' => 'published', 'visibility' => 'public']);
    ok(isset($errors['file_media_id']), 'download file refused');
    [, $errors] = content_validate(content_type('posts'), ['title' => 'Leak', 'category_id' => (string) q_val("SELECT id FROM categories WHERE slug = 'news'"), 'cover_media_id' => (string) $photo, 'status' => 'draft', 'visibility' => 'public']);
    ok(isset($errors['cover_media_id']), 'cover refused');
    ok(!media_attachable(media_find($photo)));
});

test('S1 media: the picker and library never list member photos', function (): void {
    $r = fixture_signup('picker-' . bin2hex(random_bytes(3)) . '@example.test');
    $photo = (int) q_val('SELECT photo_media_id FROM members WHERE user_id = ?', [$r['user_id']]);
    q("UPDATE media SET visibility = 'members' WHERE id = ?", [$photo]); // even if its visibility were wrong
    media_forget($photo);
    act_as(fixture_admin());
    request('GET', '/admin/media/picker', [], ['kind' => 'image']);
    $json = (string) json_encode((static function (): array {
        $method = new ReflectionMethod(Admin\Media::class, 'filters');
        return $method->invoke(null, '', 'image', '');
    })());
    contains("folder <> 'member-photos'", $json);
    q("UPDATE media SET visibility = 'private' WHERE id = ?", [$photo]);
    media_forget($photo);
});

test('S11 media: member photos are for their owner and staff with the members permission only', function (): void {
    $r = fixture_signup('s11-' . bin2hex(random_bytes(3)) . '@example.test');
    $photo = media_find((int) q_val('SELECT photo_media_id FROM members WHERE user_id = ?', [$r['user_id']]));
    $content = fixture_admin('admin', ['content']);
    $members = fixture_admin('admin', ['members']);
    act_as($content);
    ok(!media_can_view($photo, current_user()), 'content-only admin');
    act_as($members);
    ok(media_can_view($photo, current_user()), 'members admin');
    act_as(fixture_admin());
    ok(media_can_view($photo, current_user()), 'super admin');
    q("UPDATE users SET status = 'active' WHERE id = ?", [$r['user_id']]);
    act_as($r['user_id']);
    ok(media_can_view($photo, current_user()), 'owner');
    act_as(fixture_member());
    ok(!media_can_view($photo, current_user()), 'another member');
});

test('S2 media: making an item members-only moves its files out of public/uploads, and back', function (): void {
    act_as(fixture_admin());
    $img = fixture_image_media();
    $pdf = fixture_pdf_media();
    $before = media_find($img);
    $publicFile = BASE . '/public/' . reset($before['variants']);
    ok(is_file($publicFile), 'public variant exists at first');
    $type = content_type('downloads');
    $input = ['title' => 'Members doc ' . bin2hex(random_bytes(2)), 'file_media_id' => (string) $pdf, 'cover_media_id' => (string) $img, 'status' => 'published', 'visibility' => 'public'];
    $id = fixture_content('downloads', $input);
    same('public', media_find($img)['visibility']);
    [$data] = content_validate($type, ['visibility' => 'members'] + $input, content_find($type, $id));
    content_save($type, $data, $input, $id);
    $img2 = media_find($img);
    same('members', $img2['visibility'], 'cover follows the item');
    same('members', media_find($pdf)['visibility'], 'file follows the item');
    ok(!is_file($publicFile), 'no public WebP left behind');
    ok(is_file(media_file_path($img2, array_key_first($img2['variants'])) ?? ''), 'variant now in storage/variants');
    same('/media/' . $img . '/w/' . array_key_first($img2['variants']), media_variant_url($img2, array_key_first($img2['variants'])));
    [$data] = content_validate($type, $input, content_find($type, $id));
    content_save($type, $data, $input, $id);
    same('public', media_find($img)['visibility'], 'public again');
    ok(is_file(BASE . '/public/' . reset(media_find($img)['variants'])));
});

test('S2 media: a file used by a public item stays public when a members-only item also uses it', function (): void {
    act_as(fixture_admin());
    $img = fixture_image_media();
    $cat = (string) q_val("SELECT id FROM categories WHERE slug = 'news'");
    fixture_content('posts', ['title' => 'Public user', 'category_id' => $cat, 'cover_media_id' => (string) $img, 'status' => 'draft', 'visibility' => 'public']);
    fixture_content('posts', ['title' => 'Members user', 'category_id' => $cat, 'cover_media_id' => (string) $img, 'status' => 'draft', 'visibility' => 'members']);
    same('public', media_find($img)['visibility']);
    $members = fixture_image_media(null, 'members');
    fixture_content('posts', ['title' => 'Attach members file publicly', 'category_id' => $cat, 'cover_media_id' => (string) $members, 'status' => 'draft', 'visibility' => 'public']);
    same('public', media_find($members)['visibility'], 'attaching to a public item makes it public');
});

test('S2 media: album photos follow the album visibility', function (): void {
    act_as(fixture_admin());
    $album = fixture_content('albums', ['title' => 'Album vis ' . bin2hex(random_bytes(2)), 'status' => 'published', 'visibility' => 'public']);
    $photo = fixture_image_media();
    db_insert('album_photos', ['album_id' => $album, 'media_id' => $photo, 'sort' => 0]);
    $type = content_type('albums');
    $input = ['title' => 'Album vis', 'status' => 'published', 'visibility' => 'members'];
    [$data] = content_validate($type, $input, content_find($type, $album));
    content_save($type, $data, $input, $album);
    same('members', media_find($photo)['visibility']);
});

test('S2 media: visitors are not given <img> tags for members-only images', function (): void {
    act_as(null);
    $m = media_find(fixture_image_media(null, 'members'));
    same('', img_html($m));
    act_as(fixture_member());
    contains('/media/' . $m['id'] . '/w/', img_html($m));
});

// ---- S5/S6/S7/S8/S10 ----------------------------------------------------------

test('S5 limits: emails visitors can aim at one address are capped per day and globally per hour', function (): void {
    $email = 'target-' . bin2hex(random_bytes(3)) . '@example.test';
    ok(unsolicited_mail_allowed('contact_copy', $email));
    ok(unsolicited_mail_allowed('contact_copy', strtoupper($email)), 'case-insensitive, second allowed');
    ok(!unsolicited_mail_allowed('contact_copy', $email), 'third refused');
    ok(unsolicited_mail_allowed('event_guest', $email), 'kinds are counted separately');
    q("DELETE FROM rate_hits WHERE bucket = 'mailto-all'");
    for ($i = 0; $i < UNSOLICITED_PER_HOUR; $i++) {
        rate_hit('mailto-all');
    }
    ok(!unsolicited_mail_allowed('event_guest', 'fresh-' . bin2hex(random_bytes(3)) . '@example.test'), 'global hourly cap');
    q("DELETE FROM rate_hits WHERE bucket = 'mailto-all'");
});

test('S5 contact copy: the template no longer repeats the message', function (): void {
    $def = email_template_def('contact_copy');
    ok(!array_key_exists('message', $def['placeholders']));
    not_contains('{message}', $def['body']);
});

test('S6 login: failures are also limited per IP across emails and per email across IPs', function (): void {
    q('DELETE FROM login_attempts');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
    for ($i = 0; $i < LOGIN_MAX_FAILURES_IP; $i++) {
        q('INSERT INTO login_attempts (ip, email, attempted_at) VALUES (?, ?, UTC_TIMESTAMP())', ['203.0.113.7', "spray$i@example.test"]);
    }
    ok(login_locked('someone-new@example.test'), 'IP spraying many emails is locked');
    $_SERVER['REMOTE_ADDR'] = '198.51.100.1';
    ok(!login_locked('someone-new@example.test'));
    for ($i = 0; $i < LOGIN_MAX_FAILURES_EMAIL; $i++) {
        q('INSERT INTO login_attempts (ip, email, attempted_at) VALUES (?, ?, UTC_TIMESTAMP())', ["192.0.2.$i", 'victim@example.test']);
    }
    ok(login_locked('victim@example.test'), 'one email guessed from many IPs is locked');
    q('DELETE FROM login_attempts');
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
});

test('S6 login: unknown emails are checked against a hash with the real algorithm', function (): void {
    $src = (string) file_get_contents(BASE . '/src/lib/auth.php');
    contains('$dummy ??= hash_password(', $src);
    not_contains('PASSWORD_DEFAULT));', $src);
    same(null, attempt_login('nobody-' . bin2hex(random_bytes(3)) . '@example.test', 'whatever password'));
    q('DELETE FROM login_attempts');
});

test('S8 safe_url refuses relative URLs that browsers treat as other sites', function (): void {
    foreach (['/\\evil.com', "/\t/evil.com", '//evil.com', "/\n/evil.com", '/ /evil.com', "/a\\b", '#x y'] as $bad) {
        same('', safe_url($bad), var_export($bad, true));
    }
    foreach (['/news', '/events/x?y=1#z', '#top', 'https://example.org/a', 'mailto:a@example.org'] as $good) {
        same($good, safe_url($good));
    }
});

test('S10 overview: birthdays need the members permission; everyone’s activity is for super admins', function (): void {
    $boss = fixture_admin();
    $limited = fixture_admin('admin', ['events']);
    act_as($boss);
    audit('test', 'thing', 1, 'boss did it');
    act_as($limited);
    audit('test', 'thing', 2, 'limited did it');
    $d = Admin\Dashboard::overviewData(current_user());
    same(null, $d['birthdays']);
    same(false, $d['activity_all']);
    same([(int) $limited], array_values(array_unique(array_map('intval', array_column($d['activity'], 'user_id')))));
    act_as($boss);
    $d = Admin\Dashboard::overviewData(current_user());
    ok(is_array($d['birthdays']) && $d['activity_all']);
    act_as(fixture_admin('admin', ['members']));
    ok(is_array(Admin\Dashboard::overviewData(current_user())['birthdays']));
});

// ---- S9: installer ---------------------------------------------------------------

test('S9 installer: needs a one-time token from the environment or storage/install-token', function (): void {
    putenv('GATHER_INSTALL_TOKEN');
    $file = BASE . INSTALL_TOKEN_FILE;
    $had = is_file($file) ? (string) file_get_contents($file) : null;
    if ($had !== null) {
        unlink($file);
    }
    try {
        same(null, install_token_expected(), 'no token: the installer stays closed');
        ok(!install_token_ok('anything-anything'));
        putenv('GATHER_INSTALL_TOKEN=short');
        same(null, install_token_expected(), 'too short');
        putenv('GATHER_INSTALL_TOKEN=0123456789abcdef0123');
        ok(install_token_ok('0123456789abcdef0123'));
        ok(!install_token_ok('0123456789abcdef0124'));
        putenv('GATHER_INSTALL_TOKEN');
        file_put_contents($file, "fedcba9876543210fedc\n");
        ok(install_token_ok('fedcba9876543210fedc'), 'token file (trailing newline ignored)');
    } finally {
        putenv('GATHER_INSTALL_TOKEN');
        is_file($file) && unlink($file);
        if ($had !== null) {
            file_put_contents($file, $had);
        }
    }
});

test('S9 installer: the site address comes from a validated form field', function (): void {
    same('https://www.example.org', install_base_url_input('https://www.example.org/'));
    same('http://127.0.0.1:8099', install_base_url_input('http://127.0.0.1:8099'));
    same('https://example.org/club', install_base_url_input('https://example.org/club'));
    foreach (['javascript:alert(1)', 'https://evil.example"><x', 'ftp://example.org', 'https://exa mple.org', ''] as $bad) {
        same(null, install_base_url_input($bad), $bad);
    }
});

// ---- S12: proxies and HSTS ---------------------------------------------------------

test('S12 proxies: forwarded headers are ignored unless the peer is a configured trusted proxy', function (): void {
    $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9, 10.0.0.4';
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
    unset($_SERVER['HTTPS']);
    try {
        same('10.0.0.5', client_ip(), 'off by default');
        ok(!request_is_https());
        with_config(['trusted_proxies' => ['10.0.0.5', '10.0.0.4']], function (): void {
            same('198.51.100.9', client_ip(), 'right-most untrusted hop');
            ok(request_is_https());
            $_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip, 10.0.0.4';
            same('10.0.0.5', client_ip(), 'garbage falls back to the peer');
        });
        $_SERVER['REMOTE_ADDR'] = '203.0.113.50';
        with_config(['trusted_proxies' => ['10.0.0.5']], function (): void {
            same('203.0.113.50', client_ip(), 'an untrusted peer cannot spoof its IP');
        });
    } finally {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
    }
});

test('S12 HSTS includes subdomains (not preload)', function (): void {
    $src = (string) file_get_contents(BASE . '/src/bootstrap.php');
    contains('max-age=31536000; includeSubDomains', $src);
    not_contains('preload', $src);
});
