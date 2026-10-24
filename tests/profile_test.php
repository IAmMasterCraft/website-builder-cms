<?php
declare(strict_types=1);

// Phase 7 gaps: members change their own email (with re-verification);
// orphaned group logos are purged.

/** Capture mail into a temp folder; returns [dir, restore fn]. */
function capture_mail(): string
{
    $dir = sys_get_temp_dir() . '/gather-mail-' . bin2hex(random_bytes(4));
    mailer(new \Core\Mail\FileMailer($dir, 'no-reply@example.test', 'Gather'));
    return $dir;
}

/** @return list<string> decoded emails in order */
function captured(string $dir): array
{
    $files = glob($dir . '/*.eml') ?: [];
    sort($files);
    return array_map(static fn (string $f): string => quoted_printable_decode((string) file_get_contents($f)), $files);
}

test('verified member changes email: confirm link to the new address, notice to the old', function (): void {
    $dir = capture_mail();
    try {
        $id = fixture_member();
        $old = (string) q_val('SELECT email FROM users WHERE id = ?', [$id]);
        $new = 'moved-' . bin2hex(random_bytes(3)) . '@example.test';
        act_as($id);
        same('pending', email_change_request(current_user(), strtoupper($new)));
        same($old, q_val('SELECT email FROM users WHERE id = ?', [$id]), 'login unchanged until confirmed');
        same($new, q_val('SELECT pending_email FROM users WHERE id = ?', [$id]));
        $mails = captured($dir);
        same(2, count($mails));
        ok((bool) preg_match('/^To: .*' . preg_quote($new, '/') . '/m', $mails[0]), 'confirmation goes to the new address');
        ok((bool) preg_match('#/account/email/confirm/([a-f0-9]{64})#', $mails[0], $m), 'confirm link');
        contains($old, $mails[1], 'notice goes to the old address');
        not_contains($new, $mails[1], 'old address only sees a masked new address');
        // A reset link sent earlier to the old address stops working after the change.
        $reset = create_password_reset($id);
        $done = email_change_confirm($m[1]);
        same(['id' => $id, 'old' => $old, 'new' => $new], $done);
        same($new, q_val('SELECT email FROM users WHERE id = ?', [$id]));
        same(null, q_val('SELECT pending_email FROM users WHERE id = ?', [$id]));
        same(null, find_password_reset($reset));
        same(null, email_change_confirm($m[1]), 'link is single use');
        ok(attempt_login($new, 'correct horse battery') === $id, 'logs in with the new address');
    } finally {
        mailer(null, true);
    }
});

test('email change validation: format, same address, taken address', function (): void {
    $a = fixture_member();
    $b = fixture_member();
    $bEmail = (string) q_val('SELECT email FROM users WHERE id = ?', [$b]);
    same('Enter a valid email address.', email_change_problem($a, 'nope'));
    same('That is already your email address.', email_change_problem($a, (string) q_val('SELECT email FROM users WHERE id = ?', [$a])));
    same('Another account already uses that address.', email_change_problem($a, $bEmail));
    same(null, email_change_problem($a, 'free-' . bin2hex(random_bytes(3)) . '@example.test'));
});

test('if the new address is taken before confirming, the change is dropped', function (): void {
    $dir = capture_mail();
    try {
        $id = fixture_member();
        $new = 'race-' . bin2hex(random_bytes(3)) . '@example.test';
        act_as($id);
        email_change_request(current_user(), $new);
        preg_match('#/account/email/confirm/([a-f0-9]{64})#', captured($dir)[0], $m);
        create_user($new, 'correct horse battery', 'member', 'Other');
        same(null, email_change_confirm($m[1]));
        same(null, q_val('SELECT pending_email FROM users WHERE id = ?', [$id]));
    } finally {
        mailer(null, true);
    }
});

test('unverified account switches address at once and gets a new verification link', function (): void {
    $dir = capture_mail();
    try {
        $id = fixture_member('unverified');
        $new = 'typo-fixed-' . bin2hex(random_bytes(3)) . '@example.test';
        act_as($id);
        same('changed', email_change_request(current_user(), $new));
        same($new, q_val('SELECT email FROM users WHERE id = ?', [$id]));
        $mails = captured($dir);
        same(1, count($mails));
        ok((bool) preg_match('#/join/verify/([a-f0-9]{64})#', $mails[0], $m));
        same($id, user_token_find($m[1], 'verify'));
    } finally {
        mailer(null, true);
    }
});

test('email_mask hides most of the local part', function (): void {
    same('am*****@example.org', email_mask('amara.o@example.org'));
    same('a***@x.test', email_mask('a@x.test'));
});

test('purging an unverified group deletes its logo; orphaned logos are swept', function (): void {
    $logo = fixture_image_media();
    q("UPDATE media SET folder = 'groups' WHERE id = ?", [$logo]);
    $email = 'logo-group-' . bin2hex(random_bytes(3)) . '@example.test';
    $r = group_signup($email, 'correct horse battery', ['name' => 'Logo Group ' . bin2hex(random_bytes(2)), 'contact_person' => 'Kemi', 'phone' => null, 'address' => null, 'country' => 'United Kingdom'], [], $logo);
    q('UPDATE media SET uploaded_by = ? WHERE id = ?', [$r['user_id'], $logo]);
    $path = media_file_path(media_find($logo));
    ok($path !== null && is_file($path), 'logo file exists');
    q('UPDATE users SET created_at = UTC_TIMESTAMP() - INTERVAL 15 DAY WHERE id = ?', [$r['user_id']]);
    ok(purge_unverified() >= 1);
    media_forget($logo);
    same(null, media_find($logo), 'logo row deleted with the group');
    clearstatcache();
    ok(!is_file((string) $path), 'logo file deleted');

    // A logo left behind by a failed sign-up (no uploader, no group) is swept after a day.
    $orphan = fixture_image_media();
    q("UPDATE media SET folder = 'groups', uploaded_by = NULL, created_at = UTC_TIMESTAMP() - INTERVAL 2 DAY WHERE id = ?", [$orphan]);
    $kept = fixture_image_media(fixture_admin());
    q("UPDATE media SET folder = 'groups', created_at = UTC_TIMESTAMP() - INTERVAL 2 DAY WHERE id = ?", [$kept]);
    $fresh = fixture_image_media();
    q("UPDATE media SET folder = 'groups', uploaded_by = NULL WHERE id = ?", [$fresh]);
    ok(purge_orphan_group_logos() >= 1);
    media_forget($orphan);
    media_forget($kept);
    media_forget($fresh);
    same(null, media_find($orphan), 'orphan swept');
    ok(media_find($kept) !== null, 'admin uploads in the folder are kept');
    ok(media_find($fresh) !== null, 'recent uploads are kept (sign-up may still be running)');
});

test('confirming the current password is limited to 10 wrong guesses an hour', function (): void {
    $id = fixture_member();
    ok(confirm_current_password($id, 'correct horse battery'));
    for ($i = 0; $i < 10; $i++) {
        ok(!confirm_current_password($id, 'wrong guess ' . $i));
    }
    ok(!confirm_current_password($id, 'correct horse battery'), 'locked even with the right password');
});
