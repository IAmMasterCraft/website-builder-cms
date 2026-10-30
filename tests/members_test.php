<?php
declare(strict_types=1);

// Phase 4: sign-up validation, verification and numbers, purge, ID card
// checks, access control on member photos, deletion, export, CSV import.

function member_profile_input(array $over = []): array
{
    return $over + ['first_name' => 'Ngozi', 'last_name' => 'Adeyemi', 'birth_day' => '14', 'birth_month' => '3', 'birth_year' => '', 'res_country' => 'GB', 'res_state' => 'ENG', 'gender' => '', 'phone' => '07700 900123'];
}

/** Sign up a member through the model (photo from a generated JPEG). */
function fixture_signup(string $email, array $profileOver = [], array $custom = []): array
{
    [$profile, $errors, $locations] = member_profile_validate(member_profile_input($profileOver));
    if ($errors) {
        throw new RuntimeException('bad fixture profile: ' . json_encode($errors));
    }
    $jpg = fixture_jpeg(700, 500);
    return member_signup($email, 'correct horse battery', $profile, $custom, true, ['tmp' => $jpg, 'name' => 'me.jpg', 'size' => (int) filesize($jpg)], false, $locations);
}

function reset_member_settings(array $over = []): void
{
    setting_set('members', $over);
    settings_all([]);
}

test('join profile validation', function (): void {
    reset_member_settings();
    [$d, $e] = member_profile_validate(member_profile_input());
    same([], $e);
    same(null, $d['birth_year']);
    same(14, $d['birth_day']);
    [, $e] = member_profile_validate(member_profile_input(['first_name' => '', 'birth_day' => '31', 'birth_month' => '2', 'gender' => 'robot', 'res_country' => '']));
    ok(isset($e['first_name'], $e['birth_day'], $e['gender'], $e['res_country']), json_encode($e));
    [, $e] = member_profile_validate(member_profile_input(['res_state' => 'Atlantis']));
    ok(isset($e['res_state']), 'a state code that is not in the list is refused');
    [, $e] = member_profile_validate(member_profile_input(['res_country' => 'ZZ']));
    ok(isset($e['res_country']), 'unknown country code refused');
    [, $e] = member_profile_validate(member_profile_input(['birth_day' => '29', 'birth_month' => '2', 'birth_year' => '2023']));
    ok(isset($e['birth_day']), '29 Feb 2023 does not exist');
    [, $e] = member_profile_validate(member_profile_input(['birth_day' => '29', 'birth_month' => '2', 'birth_year' => '']));
    same([], $e, '29 Feb without a year is fine');
    [, $e] = member_profile_validate(member_profile_input(['birth_year' => (string) ((int) date('Y') + 1)]));
    ok(isset($e['birth_year']));
});

test('custom profile fields: required, select, date and checkbox rules', function (): void {
    q('DELETE FROM custom_fields');
    $sel = db_insert('custom_fields', ['field_key' => 'district', 'label' => 'District', 'type' => 'select', 'options' => json_encode(['North', 'South']), 'is_required' => 1, 'visible_to_members' => 1, 'show_on_card' => 1]);
    db_insert('custom_fields', ['field_key' => 'since', 'label' => 'Member since', 'type' => 'date', 'visible_to_members' => 1]);
    db_insert('custom_fields', ['field_key' => 'volunteer', 'label' => 'Happy to volunteer', 'type' => 'checkbox', 'is_required' => 1, 'visible_to_members' => 1]);
    db_insert('custom_fields', ['field_key' => 'committee_note', 'label' => 'Committee note', 'type' => 'text', 'visible_to_members' => 0]);
    same(3, count(custom_fields('member', true)), 'admin-only fields are not on the join form');
    [$v, $e] = custom_fields_validate(custom_fields('member', true), ['cf_district' => 'West', 'cf_since' => '2024-02-30']);
    ok(isset($e['cf_district'], $e['cf_since'], $e['cf_volunteer']), json_encode($e));
    [$v, $e] = custom_fields_validate(custom_fields('member', true), ['cf_district' => 'North', 'cf_since' => '2024-02-29', 'cf_volunteer' => '1']);
    same([], $e);
    same('North', $v[$sel]);
    same('Yes', custom_value_display(['type' => 'checkbox'], '1'));
});

test('member number formats and building', function (): void {
    same('ORG-26-0042', member_number_build('{PREFIX}-{YY}-{0000}', 'ORG', 2026, 42));
    same('M2026/000007', member_number_build('M{YYYY}/{000000}', 'X', 2026, 7));
    same(null, member_number_format_problem('{PREFIX}-{YY}-{0000}'));
    ok(member_number_format_problem('{PREFIX}-{YY}') !== null, 'needs a sequence');
    ok(member_number_format_problem('{0000}{000}') !== null, 'only one sequence');
    ok(member_number_format_problem('{PREFIX} {0000}') !== null, 'no spaces');
    same(null, member_prefix_problem('HCA'));
    ok(member_prefix_problem('hca!') !== null);
});

test('sign-up creates an unverified account; verifying activates it and assigns the number (D7)', function (): void {
    q('DELETE FROM custom_fields');
    reset_member_settings(['prefix' => 'TST', 'card_months' => 12]);
    q("DELETE FROM number_sequences WHERE scope = 'member'");
    $before = (int) q_val("SELECT COUNT(*) FROM issued_numbers WHERE number LIKE 'TST-%'");
    $r = fixture_signup('new-' . bin2hex(random_bytes(3)) . '@example.test');
    $user = q_one('SELECT * FROM users WHERE id = ?', [$r['user_id']]);
    $m = member_for_user($r['user_id']);
    same('unverified', $user['status']);
    same(null, $m['member_number'], 'no number before verification');
    same(true, member_pref($m, 'newsletters'));
    same(false, member_pref($m, 'celebrants'), 'celebrants list is opt-in');
    $photo = media_find((int) $m['photo_media_id']);
    same('private', $photo['visibility']);
    same($photo['width'], $photo['height'], 'photo cropped to a square');
    same(null, account_verify(str_repeat('a', 64)), 'unknown token');
    $now = new DateTimeImmutable('2026-10-24 12:00', new DateTimeZone('Europe/London'));
    same($r['user_id'], account_verify($r['token'], $now));
    $m = member_for_user($r['user_id']);
    same('active', q_val('SELECT status FROM users WHERE id = ?', [$r['user_id']]));
    ok((bool) preg_match('/^TST-26-\d{4}$/', (string) $m['member_number']), (string) $m['member_number']);
    same($before + 1, (int) q_val("SELECT COUNT(*) FROM issued_numbers WHERE number LIKE 'TST-%'"));
    ok(strlen((string) $m['card_code']) >= 10);
    same('2027-10-24', $m['card_valid_until']);
    ok($m['verified_at'] !== null);
    same(null, account_verify($r['token'], $now), 'tokens are single use');
});

test('member numbers are sequential, per year, and never reused', function (): void {
    reset_member_settings(['prefix' => 'SEQ']);
    q("DELETE FROM number_sequences WHERE scope = 'member'");
    q("DELETE FROM issued_numbers WHERE number LIKE 'SEQ-%'");
    $issue = static fn (string $when): string => db_tx(static fn (): string => member_issue_number('member', new DateTimeImmutable($when, site_tz())));
    same('SEQ-26-0001', $issue('2026-05-01'));
    same('SEQ-26-0002', $issue('2026-12-31 23:00'));
    same('SEQ-27-0001', $issue('2027-01-01 09:00'), 'sequence restarts with the year');
    // Someone resets the counter: issued numbers are still skipped.
    q("UPDATE number_sequences SET `last_value` = 0 WHERE scope = 'member' AND period = '2026'");
    same('SEQ-26-0003', $issue('2026-06-01'));
    // Delete a member who holds a number: it stays retired.
    $r = fixture_signup('retire-' . bin2hex(random_bytes(3)) . '@example.test');
    account_verify($r['token'], new DateTimeImmutable('2026-07-01', site_tz()));
    $number = (string) member_for_user($r['user_id'])['member_number'];
    same('SEQ-26-0004', $number);
    account_delete($r['user_id']);
    q("UPDATE number_sequences SET `last_value` = 3 WHERE scope = 'member' AND period = '2026'");
    same('SEQ-26-0005', $issue('2026-08-01'), 'a deleted member\'s number is never handed out again');
    // Groups use their own prefix and counter.
    reset_member_settings(['prefix' => 'SEQ', 'group_prefix' => 'GRS']);
    ok(str_starts_with(db_tx(static fn (): string => member_issue_number('group')), 'GRS-'));
    throws(LogicException::class, fn () => member_issue_number('member'));
    // The UNIQUE key backs it up.
    throws(PDOException::class, fn () => q('INSERT INTO issued_numbers (number, scope) VALUES (?, ?)', ['SEQ-26-0001', 'member']));
});

test('group sign-up: verifying lists the group with a group ID', function (): void {
    reset_member_settings(['group_prefix' => 'GRP']);
    $email = 'group-' . bin2hex(random_bytes(3)) . '@example.test';
    $r = group_signup($email, 'correct horse battery', ['name' => 'Riverside Elders', 'contact_person' => 'Kemi', 'phone' => null, 'address' => null, 'country' => 'United Kingdom'], [], null);
    same('hidden', q_val('SELECT status FROM groups_orgs WHERE id = ?', [$r['group_id']]), 'not listed before verification');
    account_verify($r['token']);
    $g = q_one('SELECT * FROM groups_orgs WHERE id = ?', [$r['group_id']]);
    same('active', $g['status']);
    ok(str_starts_with((string) $g['group_number'], 'GRP-'), (string) $g['group_number']);
    same('active', q_val('SELECT status FROM users WHERE id = ?', [$r['user_id']]));
    // Suspending the group in the admin suspends its login.
    $type = content_type('groups');
    $row = content_find($type, (int) $g['id']);
    [$data] = content_validate($type, array_map(static fn ($v) => (string) $v, $row) + [] , $row);
    $data['status'] = 'suspended';
    content_save($type, $data, ['status' => 'suspended'], (int) $g['id']);
    same('suspended', q_val('SELECT status FROM users WHERE id = ?', [$r['user_id']]));
});

test('unverified accounts are purged after 14 days; verified ones are kept', function (): void {
    $old = fixture_signup('old-' . bin2hex(random_bytes(3)) . '@example.test');
    $recent = fixture_signup('recent-' . bin2hex(random_bytes(3)) . '@example.test');
    $verified = fixture_signup('kept-' . bin2hex(random_bytes(3)) . '@example.test');
    account_verify($verified['token']);
    q('UPDATE users SET created_at = UTC_TIMESTAMP() - INTERVAL 15 DAY WHERE id IN (?, ?)', [$old['user_id'], $verified['user_id']]);
    q('UPDATE user_tokens SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 DAY WHERE user_id = ?', [$old['user_id']]); // its link expired too
    q('UPDATE users SET created_at = UTC_TIMESTAMP() - INTERVAL 13 DAY WHERE id = ?', [$recent['user_id']]);
    $oldPhoto = media_find((int) member_for_user($old['user_id'])['photo_media_id']);
    $oldFile = media_file_path($oldPhoto);
    ok($oldFile !== null && is_file($oldFile));
    $n = purge_unverified();
    ok($n >= 1);
    same(null, q_val('SELECT id FROM users WHERE id = ?', [$old['user_id']]), 'old unverified removed');
    same(null, q_val('SELECT id FROM media WHERE id = ?', [$oldPhoto['id']]), 'their photo removed');
    ok(!is_file((string) $oldFile), 'photo file deleted');
    ok(q_val('SELECT id FROM users WHERE id = ?', [$recent['user_id']]) !== null, '13-day-old account kept');
    ok(q_val('SELECT id FROM users WHERE id = ?', [$verified['user_id']]) !== null, 'verified account kept');
    ok(isset(cron_jobs()['purge_unverified']), 'runs from cron');
});

test('ID card check: valid, expired, and invalid after regenerating the code', function (): void {
    $r = fixture_signup('card-' . bin2hex(random_bytes(3)) . '@example.test');
    account_verify($r['token']);
    $m = member_for_user($r['user_id']);
    same('valid', card_lookup($m['member_number'], $m['card_code'])['state']);
    same('Ngozi', card_lookup($m['member_number'], $m['card_code'])['member']['first_name']);
    same('expired', card_lookup($m['member_number'], $m['card_code'], '2099-01-01')['state']);
    same('invalid', card_lookup($m['member_number'], 'wrongcode1234')['state']);
    same('invalid', card_lookup('NOPE-00-0000', $m['card_code'])['state']);
    $oldCode = $m['card_code'];
    $new = card_regenerate((int) $m['id']);
    ok($new !== $oldCode);
    same('invalid', card_lookup($m['member_number'], $oldCode)['state'], 'old QR stops working');
    same('valid', card_lookup($m['member_number'], $new)['state']);
    q("UPDATE users SET status = 'suspended' WHERE id = ?", [$r['user_id']]);
    same('invalid', card_lookup($m['member_number'], $new)['state'], 'suspended members are not valid');
    q("UPDATE users SET status = 'active' WHERE id = ?", [$r['user_id']]);
    $card = card_data(member_for_user($r['user_id']));
    contains('/verify/' . $m['member_number'] . '/' . $new, $card['verify_url']);
    same('Ngozi Adeyemi', $card['name']);
    ok(str_starts_with((string) $card['photo'], '/media/'), 'private photo served through the PHP handler');
});

test('member photos are private: owner and staff only', function (): void {
    $a = fixture_signup('owner-' . bin2hex(random_bytes(3)) . '@example.test');
    $b = fixture_signup('other-' . bin2hex(random_bytes(3)) . '@example.test');
    account_verify($a['token']);
    account_verify($b['token']);
    $photo = media_find((int) member_for_user($a['user_id'])['photo_media_id']);
    act_as(null);
    same(false, media_can_view($photo, current_user()), 'visitor');
    act_as($b['user_id']);
    same(false, media_can_view($photo, current_user()), 'another member');
    act_as($a['user_id']);
    same(true, media_can_view($photo, current_user()), 'owner');
    act_as(fixture_admin('admin', ['members']));
    same(true, media_can_view($photo, current_user()), 'staff');
    act_as(null);
});

test('deleting an account anonymises registrations and keeps the number retired', function (): void {
    $r = fixture_signup('leaver-' . bin2hex(random_bytes(3)) . '@example.test');
    account_verify($r['token']);
    $m = member_for_user($r['user_id']);
    $eventId = fixture_content('events', ['title' => 'Leaver event', 'start_at' => local_in('+5 days'), 'timezone' => 'Europe/London', 'status' => 'published', 'visibility' => 'public', 'registration_open' => '1']);
    $reg = db_insert('event_registrations', ['event_id' => $eventId, 'user_id' => $r['user_id'], 'name' => 'Ngozi Adeyemi', 'email' => 'leaver@example.test', 'quantity' => 1]);
    $comment = db_insert('comments', ['item_type' => 'post', 'item_id' => 1, 'user_id' => $r['user_id'], 'body' => 'Hello', 'status' => 'approved']);
    $export = member_export($r['user_id']);
    same('Ngozi', $export['member']['first_name']);
    ok(!isset($export['member']['card_code']), 'export leaves out the card secret');
    same(1, count($export['event_registrations']));
    account_delete($r['user_id']);
    same(null, member_for_user($r['user_id']));
    $row = q_one('SELECT * FROM event_registrations WHERE id = ?', [$reg]);
    same('Former member', $row['name']);
    same('', $row['email']);
    same(null, $row['user_id']);
    same(null, q_val('SELECT user_id FROM comments WHERE id = ?', [$comment]), 'comment shown as former member');
    ok(q_val('SELECT number FROM issued_numbers WHERE number = ?', [$m['member_number']]) !== null);
});

test('"free for the first N members" sets the fee status', function (): void {
    q('UPDATE members SET verified_at = NULL');
    reset_member_settings(['free_first_n' => 1]);
    same(1, free_places_left());
    $a = fixture_signup('free1-' . bin2hex(random_bytes(3)) . '@example.test');
    $b = fixture_signup('free2-' . bin2hex(random_bytes(3)) . '@example.test');
    account_verify($a['token']);
    account_verify($b['token']);
    same('free', member_for_user($a['user_id'])['membership_fee_status']);
    same('due', member_for_user($b['user_id'])['membership_fee_status']);
    same(0, free_places_left());
    reset_member_settings();
    same(null, free_places_left());
});

test('CSV import creates, skips, updates and reports bad rows', function (): void {
    reset_member_settings(['prefix' => 'IMP']);
    q('DELETE FROM custom_fields');
    db_insert('custom_fields', ['field_key' => 'district', 'label' => 'District', 'type' => 'select', 'options' => json_encode(['North', 'South']), 'visible_to_members' => 1]);
    $existing = 'dup-' . bin2hex(random_bytes(3)) . '@example.test';
    $r = fixture_signup($existing);
    account_verify($r['token']);
    $u1 = 'imp1-' . bin2hex(random_bytes(3)) . '@example.test';
    $u2 = 'imp2-' . bin2hex(random_bytes(3)) . '@example.test';
    $fixed = 'LEGACY-' . random_int(1000, 9999);
    $csv = "\xEF\xBB\xBFemail,first_name,last_name,birth_day,birth_month,region,membership_type,member_number,cf_district\n"
        . "$u1,Ada,Import,5,6,England,Life,,North\n"
        . "$u2,Ben,Import,,,Wales,,$fixed,South\n"
        . "$existing,Changed,Name,1,1,England,,,North\n"
        . "not-an-email,Bad,Row,1,1,,,,\n"
        . "imp3@example.test,Cat,Import,1,1,,Platinum,,\n"
        . "imp4@example.test,Dee,Import,1,1,,,,East\n";
    $file = fixture_file($csv, 'csv');
    $rep = members_import_csv($file, false);
    same(2, $rep['created'], json_encode($rep));
    same(0, $rep['updated']);
    same(4, $rep['skipped']);
    same(3, count($rep['errors']));
    $m1 = q_one('SELECT m.* FROM members m JOIN users u ON u.id = m.user_id WHERE u.email = ?', [$u1]);
    ok(str_starts_with((string) $m1['member_number'], 'IMP-'), 'number assigned');
    same('Life', $m1['membership_type']);
    same('active', q_val('SELECT status FROM users WHERE email = ?', [$u1]));
    same('North', array_values(custom_values_for_member((int) $m1['id']))[0]);
    $m2 = q_one('SELECT m.* FROM members m JOIN users u ON u.id = m.user_id WHERE u.email = ?', [$u2]);
    same($fixed, $m2['member_number'], 'given number kept');
    same(null, $m2['birth_day'], 'birthday optional on import');
    ok(q_val('SELECT number FROM issued_numbers WHERE number = ?', [$fixed]) !== null, 'given number reserved');
    same('Ngozi', member_for_user($r['user_id'])['first_name'], 'existing member skipped without update mode');
    $rep = members_import_csv($file, true);
    same(3, $rep['updated'], 'the two imported rows and the existing member');
    same('Changed', member_for_user($r['user_id'])['first_name']);
    $missing = members_import_csv(fixture_file("email,name\nx@example.test,X\n", 'csv'));
    ok(str_contains(implode(' ', $missing['errors']), 'first_name'));
});

test('members CSV export filters and neutralises formulas', function (): void {
    $r = fixture_signup('csv-' . bin2hex(random_bytes(3)) . '@example.test', ['first_name' => '=HYPERLINK("x")', 'res_state' => 'SCT']);
    account_verify($r['token']);
    $rows = members_csv_rows(['region' => 'Scotland']);
    same('member_number', $rows[0][0]);
    $flat = json_encode($rows);
    contains("'=HYPERLINK", $flat);
    $rows = members_csv_rows(['status' => 'suspended', 'q' => 'nobody-matches-this']);
    same(1, count($rows), 'header only');
    same("'-cmd", csv_safe('-cmd'));
    same('-12', csv_safe('-12'));
});

test('join form rate limit per IP', function (): void {
    $_SERVER['REMOTE_ADDR'] = '203.0.113.' . random_int(1, 250);
    for ($i = 0; $i < 3; $i++) {
        same(false, form_rate_limited('join-test', 3, 3600));
    }
    same(true, form_rate_limited('join-test', 3, 3600));
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
});

test('router patterns may use regex quantifiers (reset and verification links)', function (): void {
    $r = new Core\Router();
    $r->get('/reset/{token:[a-f0-9]{64}}', ['X', 'reset']);
    $r->get('/verify/{number:[A-Z0-9-]{1,40}}/{code:[a-z0-9]{6,40}}', ['X', 'verify']);
    same(['token' => str_repeat('c', 64)], $r->match('GET', '/reset/' . str_repeat('c', 64))['params']);
    same('ORG-26-0001', $r->match('GET', '/verify/ORG-26-0001/abc123')['params']['number']);
    throws(Core\HttpException::class, fn () => $r->match('GET', '/reset/' . str_repeat('c', 63)));
    throws(Core\HttpException::class, fn () => $r->match('GET', '/verify/ORG/abc'));
});

test('issuing skips numbers that were typed in by hand', function (): void {
    reset_member_settings(['prefix' => 'HND', 'group_prefix' => 'GHD']);
    q("DELETE FROM number_sequences WHERE scope IN ('member','group')");
    $gid = fixture_content('groups', ['name' => 'Hand numbered', 'group_number' => 'GHD-' . date('y') . '-0001', 'status' => 'active']);
    ok($gid > 0);
    same('GHD-' . date('y') . '-0002', db_tx(static fn (): string => member_issue_number('group', new DateTimeImmutable('now', site_tz()))));
});
