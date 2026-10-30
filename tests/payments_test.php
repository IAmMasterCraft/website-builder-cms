<?php
declare(strict_types=1);

// Addendum D2: payments (dues, member payment reports, admin confirmation).

function pay_reset(array $settings = []): void
{
    foreach (['payment_events', 'payment_admin_notices', 'receipt_sequences'] as $t) {
        q('DELETE FROM ' . db_ident($t));
    }
    q('DELETE FROM payments WHERE entry_type = \'reversal\'');
    q('DELETE FROM payments');
    q('DELETE FROM payment_charges');
    q('DELETE FROM payment_items');
    q('DELETE FROM email_queue');
    q('DELETE FROM email_suppressions');
    q("DELETE FROM users WHERE role IN ('member','group')");
    q('DELETE FROM rate_hits');
    setting_set('payments', $settings + ['currency' => 'GBP', 'receipt_required' => false]);
    payment_pending_count(true);
}

/** An active member with a members row: [user_id, member_id, user]. */
function pay_member(string $first = 'Ada', string $type = 'Standard', string $status = 'active'): array
{
    $uid = create_user('pay-' . bin2hex(random_bytes(4)) . '@example.test', 'correct horse battery', 'member', "$first Payer", $status);
    $mid = db_insert('members', ['user_id' => $uid, 'first_name' => $first, 'last_name' => 'Payer', 'membership_type' => $type, 'birth_day' => 1, 'birth_month' => 1,
        'member_number' => 'PAY-' . random_int(10000, 99999), 'membership_fee_status' => 'due', 'verified_at' => gmdate('Y-m-d H:i:s'), 'prefs' => json_encode(['newsletters' => false])]);
    return ['user_id' => $uid, 'member_id' => $mid, 'user' => dm_user($uid)];
}

function pay_item(array $over = []): int
{
    [$d, $errors] = payment_item_validate($over + ['name' => '2027 annual dues', 'item_kind' => 'dues', 'amount' => '30.00', 'applies_to' => 'everyone', 'allow_part' => '1', 'include_new_members' => '1', 'remind_enabled' => '1']);
    if ($errors) {
        throw new RuntimeException('item invalid: ' . json_encode($errors));
    }
    return db_insert('payment_items', $d + ['created_by' => null]);
}

function pay_report(array $m, string $target, string $amount, array $over = []): int
{
    q('DELETE FROM rate_hits WHERE bucket = ?', ['payrep:' . $m['user_id']]);
    [$d, $errors] = payment_validate($over + ['target' => $target, 'amount' => $amount, 'paid_on' => payment_today(), 'method' => 'bank_transfer', 'reference' => 'REF-' . bin2hex(random_bytes(3))], $m['member_id'], false);
    if ($errors) {
        throw new RuntimeException('report invalid: ' . json_encode($errors));
    }
    [$id, $err] = payment_submit($m['user'], $m['member_id'], $d, null);
    if ($err !== null) {
        throw new RuntimeException($err);
    }
    return (int) $id;
}

function pay_charge(int $itemId, int $memberId): array
{
    return q_one('SELECT * FROM payment_charges WHERE item_id = ? AND member_id = ?', [$itemId, $memberId]);
}

test('D2: money is parsed and printed in minor units, never floats', function (): void {
    pay_reset();
    same(3050, money_parse('30.50'));
    same(3050, money_parse('£30.5'));
    same(125000, money_parse('1,250'));
    same(null, money_parse('1.234'), 'too many decimals');
    same(null, money_parse('-5'));
    same(null, money_parse('12,34'));
    same(1, money_parse('0.01'));
    same('£1,250.00', money(125000));
    same('-£12.50', money(-1250));
    same('30.50', money_input(3050));
    same('0.05', money_input(5));
    same('¥1,500', money(1500, 'JPY'), 'no decimals for yen');
    same(1500, money_parse('1500', 'JPY'));
    same(null, money_parse('15.5', 'JPY'));
    same('KD 1.250', money(1250, 'KWD'), 'three decimals');
    same('Thirty pounds and fifty pence', money_words(3050));
    same('One thousand two hundred and fifty naira', money_words(125000, 'NGN'));
    same('One pound and one penny', money_words(101));
    same('One thousand and five pounds', money_words(100500));
    same('NGN', currency_for_country('NG'));
    same('GBP', currency_for_country('gb'));
    same(null, currency_for_country('ZZ'));
});

test('D2: payments is the twelfth permission key', function (): void {
    same(12, count(admin_permissions()));
    ok(isset(admin_permissions()['payments']));
});

test('D2: opening an item creates charges in batches for the audience; counters match the ledger', function (): void {
    pay_reset();
    q("DELETE FROM users WHERE role IN ('member','group')");
    $a = pay_member('Ada');
    $b = pay_member('Ben', 'Student');
    $c = pay_member('Cy');
    pay_member('Unverified', 'Standard', 'unverified');
    $item = pay_item();
    $students = pay_item(['name' => 'Student levy', 'amount' => '5', 'applies_to' => 'membership_type', 'membership_type' => 'Student']);
    q("UPDATE payment_items SET status = 'open', charges_pending = 1 WHERE id = ?", [$item]);
    same([2, false], payment_charges_batch($item, 2), 'first batch of two');
    same([1, true], payment_charges_batch($item, 2), 'last batch');
    same(3, (int) q_val('SELECT COUNT(*) FROM payment_charges WHERE item_id = ?', [$item]), 'unverified members get no charge');
    $i = payment_item($item);
    same([9000, 9000, 0], [(int) $i['expected_minor'], (int) $i['outstanding_minor'], (int) $i['collected_minor']]);
    payment_item_set_status($students, 'open');
    same(1, (int) q_val('SELECT COUNT(*) FROM payment_charges WHERE item_id = ?', [$students]), 'membership type audience');
    same($b['member_id'], (int) q_val('SELECT member_id FROM payment_charges WHERE item_id = ?', [$students]));
    same([], payments_reconcile());
    // Later joiners: verification adds their charge when "also apply to people who join later" is on.
    $late = pay_member('Late');
    same(1, payment_charges_for_new_member($late['member_id']));
    same(0, payment_charges_for_new_member($late['member_id']), 'idempotent');
    same(12000, (int) payment_item($item)['expected_minor']);
    same([], payments_reconcile());
    unset($a, $c);
});

test('D2: report validation: dates, amounts, part payments, overpayment and duplicate warnings', function (): void {
    pay_reset();
    $m = pay_member();
    $item = pay_item(['allow_part' => '']);
    payment_item_set_status($item, 'open');
    $charge = pay_charge($item, $m['member_id']);
    $base = ['target' => 'c' . $charge['id'], 'amount' => '30', 'paid_on' => payment_today(), 'method' => 'bank_transfer', 'reference' => 'TRF-1'];
    [, $e] = payment_validate(['paid_on' => (new DateTimeImmutable('+2 days', site_tz()))->format('Y-m-d')] + $base, $m['member_id'], false);
    ok(isset($e['paid_on']), 'no future dates');
    [, $e] = payment_validate(['paid_on' => '1999-12-31'] + $base, $m['member_id'], false);
    ok(isset($e['paid_on']), 'not before 2000');
    [, $e] = payment_validate(['amount' => '10'] + $base, $m['member_id'], false);
    contains('must be paid in full', $e['amount'] ?? '');
    [, $e, $w] = payment_validate(['amount' => '45'] + $base, $m['member_id'], false);
    same([], $e);
    contains('more than the balance', $w[0] ?? '');
    $other = pay_member('Other');
    [, $e] = payment_validate($base, $other['member_id'], false);
    ok(isset($e['target']), 'another member\'s charge cannot be chosen');
    $first = pay_report($m, 'c' . $charge['id'], '30', ['reference' => 'TRF-1']);
    [, , $w] = payment_validate(['reference' => ' trf-1 '] + $base, $m['member_id'], false);
    contains('Possible duplicate of payment #' . $first . ' (same reference)', implode(' ', $w));
    [, , $w] = payment_validate(['reference' => 'X'] + $base, $m['member_id'], false);
    contains('same amount, date and item', implode(' ', $w));
    [, , $w] = payment_validate(['reference' => 'X', 'paid_on' => '2020-01-01'] + $base, $m['member_id'], false);
    same([], $w, 'no warning for a different date and reference');
    setting_set('payments', ['currency' => 'GBP', 'receipt_required' => true]);
    [$d] = payment_validate(['reference' => 'Y', 'paid_on' => '2021-01-01'] + $base, $m['member_id'], false);
    [$id, $err] = payment_submit($m['user'], $m['member_id'], $d, null);
    same(null, $id);
    contains('upload the receipt', $err);
    // "Same transfer": reusing the receipt of an earlier record satisfies the requirement.
    $media = fixture_image_media($m['user_id'], 'private');
    q('UPDATE payments SET receipt_media_id = ? WHERE id = ?', [$media, $first]);
    [$id, $err] = payment_submit($m['user'], $m['member_id'], $d, null, $first);
    ok($id !== null && $err === null);
    same($media, (int) q_val('SELECT receipt_media_id FROM payments WHERE id = ?', [$id]));
});

test('D2: approve and reverse keep charges, items and member dues correct; reconcile finds no drift', function (): void {
    pay_reset();
    $m = pay_member();
    $admin = dm_staff(['payments']);
    $item = pay_item(['counts_as_dues' => '1', 'covers_until' => '2027-12-31']);
    payment_item_set_status($item, 'open');
    $charge = pay_charge($item, $m['member_id']);
    $p1 = pay_report($m, 'c' . $charge['id'], '10');
    same(1, payment_pending_count());
    same(null, payment_approve($p1, $admin));
    $c = pay_charge($item, $m['member_id']);
    same([1000, 'part'], [(int) $c['paid_minor'], $c['charge_status']]);
    $p = payment_find($p1);
    ok((bool) preg_match('/^RCP-\d{4}-\d{5}$/', (string) $p['receipt_number']), 'receipt number');
    ok((bool) preg_match('/^[a-f0-9]{24}$/', (string) $p['receipt_code']));
    same('due', q_val('SELECT membership_fee_status FROM members WHERE id = ?', [$m['member_id']]), 'part-paid dues do not count yet');
    $p2 = pay_report($m, 'c' . $charge['id'], '20');
    same(null, payment_approve($p2, $admin));
    same('paid', pay_charge($item, $m['member_id'])['charge_status']);
    same('paid', q_val('SELECT membership_fee_status FROM members WHERE id = ?', [$m['member_id']]));
    same('2027-12-31', q_val('SELECT card_valid_until FROM members WHERE id = ?', [$m['member_id']]));
    $i = payment_item($item);
    same([3000, 3000, 0], [(int) $i['expected_minor'], (int) $i['collected_minor'], (int) $i['outstanding_minor']]);
    contains('no longer waiting', (string) payment_approve($p2, $admin), 'approving twice is refused');
    // Reversal: a linked negative entry; the original is untouched.
    [$rid, $err] = payment_reverse($p2, $admin, 'Bounced transfer');
    same(null, $err);
    $r = payment_find($rid);
    same(['reversal', -2000, $p2, 'approved'], [$r['entry_type'], (int) $r['amount_minor'], (int) $r['reverses_id'], $r['status']]);
    same('approved', payment_find($p2)['status'], 'approved records are never edited');
    same((int) $rid, (int) payment_find($p2)['reversal_id']);
    $c = pay_charge($item, $m['member_id']);
    same([1000, 'part'], [(int) $c['paid_minor'], $c['charge_status']]);
    same('due', q_val('SELECT membership_fee_status FROM members WHERE id = ?', [$m['member_id']]), 'fee status back to due');
    $i = payment_item($item);
    same([3000, 1000, 2000], [(int) $i['expected_minor'], (int) $i['collected_minor'], (int) $i['outstanding_minor']]);
    same([null, 'This payment was already reversed.'], payment_reverse($p2, $admin, 'again'));
    same('Only approved payments can be reversed.', payment_reverse($rid, $admin, 'reverse the reversal')[1]);
    same([], payments_reconcile());
    // Drift is found and fixed.
    q('UPDATE payment_items SET collected_minor = collected_minor + 7 WHERE id = ?', [$item]);
    q("UPDATE payment_charges SET paid_minor = 5, charge_status = 'paid' WHERE id = ?", [$c['id']]);
    same(2, count(payments_reconcile()));
    payments_reconcile(true);
    same([], payments_reconcile());
    same(1000, (int) pay_charge($item, $m['member_id'])['paid_minor']);
});

test('D2: reject, ask for info, member edit answers, withdraw, timeline without admin names', function (): void {
    pay_reset(['reviewer_label' => 'Treasurer']);
    $m = pay_member();
    $admin = dm_staff(['payments']);
    q('UPDATE users SET display_name = ? WHERE id = ?', ['Secret Admin Name', $admin['id']]);
    $admin = dm_user((int) $admin['id']);
    $other = pay_item(['name' => 'Donation', 'amount_mode' => 'any', 'amount' => '', 'applies_to' => 'nobody', 'item_kind' => 'donation']);
    payment_item_set_status($other, 'open');
    same(0, (int) q_val('SELECT COUNT(*) FROM payment_charges WHERE item_id = ?', [$other]), 'optional items have no charges');
    $p = pay_report($m, 'i' . $other, '15');
    same('Give a reason for rejecting (up to 1,000 characters).', payment_review($p, $admin, 'reject', ''));
    same(null, payment_review($p, $admin, 'info', 'Which account did you pay into?'));
    same('needs_info', payment_find($p)['status']);
    [$d] = payment_validate(['target' => 'i' . $other, 'amount' => '15', 'paid_on' => payment_today(), 'method' => 'cash', 'note' => 'Paid in cash at the meeting'], $m['member_id'], false, payment_find($p));
    same(null, payment_member_update($m['user'], $p, $m['member_id'], $d, null));
    same('pending', payment_find($p)['status'], 'answering returns it to the queue');
    same(null, payment_review($p, $admin, 'reject', 'We could not find it.'));
    same('This payment can no longer be changed.', payment_member_update($m['user'], $p, $m['member_id'], $d, null));
    $timeline = payment_timeline($p, true);
    same(['Reported', 'Asked for more information', 'Answered the question', 'Rejected'], array_column($timeline, 'what'));
    same(['You', 'Treasurer', 'You', 'Treasurer'], array_column($timeline, 'who'));
    not_contains('Secret Admin Name', json_encode(payment_timeline($p, true)));
    contains('Secret Admin Name', json_encode(payment_timeline($p, false)));
    $p2 = pay_report($m, 'i' . $other, '5');
    ok(payment_withdraw($m['user'], $p2, $m['member_id']));
    ok(!payment_withdraw($m['user'], $p2, $m['member_id']), 'only once');
    $p3 = pay_report($m, 'other', '7', ['other_name' => 'Raffle tickets']);
    $o = pay_member('Nosy');
    ok(!payment_withdraw($o['user'], $p3, $o['member_id']), 'not someone else\'s record');
    same(0, (int) payment_item($other)['collected_minor'], 'nothing approved for the donation item');
});

test('D2: emails: approved with receipt link, rejected with reason, needs info; admin report emails batched per 15 minutes', function (): void {
    pay_reset();
    rec_mailer();
    $m = pay_member();
    $admin = dm_staff(['payments']);
    $off = dm_staff(['payments']);
    q('UPDATE users SET payment_emails = 0 WHERE id = ?', [$off['id']]);
    $noPerm = dm_staff(['members']);
    $item = pay_item();
    payment_item_set_status($item, 'open');
    $charge = pay_charge($item, $m['member_id']);
    $p1 = pay_report($m, 'c' . $charge['id'], '10');
    $t = time();
    cron_payment_notices($t);
    $to = array_column(q_all("SELECT to_email FROM email_queue WHERE kind = 'essential' AND dedupe_key LIKE 'payrep:%'"), 'to_email');
    ok(in_array($admin['email'], $to, true), 'payments admin emailed');
    ok(!in_array($off['email'], $to, true), 'switched off');
    ok(!in_array($noPerm['email'], $to, true), 'no permission');
    $body = (string) q_val('SELECT text_body FROM email_queue WHERE to_email = ? AND dedupe_key LIKE ?', [$admin['email'], 'payrep:%']);
    contains('Ada Payer', $body);
    contains('/admin/payments/' . $p1, $body);
    $p2 = pay_report($m, 'c' . $charge['id'], '5');
    cron_payment_notices($t + 60);
    same(1, (int) q_val('SELECT COUNT(*) FROM email_queue WHERE to_email = ? AND dedupe_key LIKE ?', [$admin['email'], 'payrep:%']), 'within 15 minutes: no second email');
    cron_payment_notices($t + 16 * 60);
    same(2, (int) q_val('SELECT COUNT(*) FROM email_queue WHERE to_email = ? AND dedupe_key LIKE ?', [$admin['email'], 'payrep:%']), 'after 15 minutes the new report is sent');
    contains('/admin/payments/' . $p2, (string) q_val('SELECT text_body FROM email_queue WHERE to_email = ? AND dedupe_key LIKE ? ORDER BY id DESC LIMIT 1', [$admin['email'], 'payrep:%']));
    payment_approve($p1, $admin);
    $mail = (string) q_val("SELECT text_body FROM email_queue WHERE dedupe_key LIKE ? ORDER BY id DESC LIMIT 1", ['pay:' . $p1 . ':payment_approved:%']);
    contains('/account/payments/' . $p1 . '/receipt', $mail);
    payment_review($p2, $admin, 'reject', 'Not in our account');
    contains('Not in our account', (string) q_val("SELECT text_body FROM email_queue WHERE dedupe_key LIKE ?", ['pay:' . $p2 . ':payment_rejected:%']));
});

test('D2: separation of duties, admin-recorded payments, waive and amount changes keep counters', function (): void {
    pay_reset();
    $m = pay_member();
    $a1 = dm_staff(['payments']);
    $a2 = dm_staff(['payments']);
    $item = pay_item();
    payment_item_set_status($item, 'open');
    $charge = pay_charge($item, $m['member_id']);
    [$d] = payment_validate(['target' => 'c' . $charge['id'], 'amount' => '10', 'paid_on' => payment_today(), 'method' => 'cash'], $m['member_id'], true);
    [$id, $err] = payment_record($a1, $d, null);
    same(null, $err);
    same('approved', payment_find((int) $id)['status'], 'recorded payments are approved (separation off)');
    same((int) $a1['id'], (int) payment_find((int) $id)['recorded_by']);
    setting_set('payments', ['currency' => 'GBP', 'separation' => true]);
    [$id2] = payment_record($a1, $d, null);
    same('pending', payment_find((int) $id2)['status'], 'with separation on, another admin approves');
    contains('another admin must approve a payment you recorded', (string) payment_approve((int) $id2, $a1));
    same(null, payment_approve((int) $id2, $a2));
    same('Give a reason (up to 255 characters).', payment_charge_waive((int) $charge['id'], $a1, true, ''));
    same(null, payment_charge_set_amount((int) $charge['id'], $a1, '25', 'Concession'));
    $i = payment_item($item);
    same([2500, 500], [(int) $i['expected_minor'], (int) $i['outstanding_minor']]);
    same(null, payment_charge_waive((int) $charge['id'], $a1, true, 'Hardship'));
    $i = payment_item($item);
    same([2000, 0, 'waived'], [(int) $i['expected_minor'], (int) $i['outstanding_minor'], pay_charge($item, $m['member_id'])['charge_status']]);
    same(null, payment_charge_waive((int) $charge['id'], $a1, false, 'Back to normal'));
    same('part', pay_charge($item, $m['member_id'])['charge_status']);
    same([], payments_reconcile());
    same(2, (int) q_val('SELECT COUNT(*) FROM payment_events WHERE charge_id = ? AND event_type IN (\'waived\',\'unwaived\')', [$charge['id']]), 'charge timeline');
});

test('D2: receipt numbers are unique and gap-free under concurrent approvals (row lock)', function (): void {
    pay_reset();
    $m = pay_member();
    $admin = dm_staff(['payments']);
    $item = pay_item(['name' => 'Raffle', 'amount_mode' => 'any', 'amount' => '', 'applies_to' => 'nobody']);
    payment_item_set_status($item, 'open');
    $ids = [];
    for ($i = 0; $i < 24; $i++) {
        $ids[] = pay_report($m, 'i' . $item, (string) (1 + $i), ['reference' => 'C' . $i]);
    }
    $script = tempnam(sys_get_temp_dir(), 'payc') . '.php';
    file_put_contents($script, '<?php require ' . var_export(BASE . '/tests/bootstrap.php', true) . ';'
        . '$admin = q_one("SELECT id, email, role, status, display_name FROM users WHERE id = ?", [(int) $argv[1]]); $admin["permissions"] = ["payments"];'
        . 'foreach (array_slice($argv, 2) as $id) { $e = payment_approve((int) $id, $admin); if ($e !== null) { fwrite(STDERR, $e); exit(1); } }');
    $procs = [];
    $pipesAll = [];
    foreach (array_chunk($ids, 6) as $chunk) {
        $procs[] = proc_open([PHP_BINARY, $script, (string) $admin['id'], ...array_map('strval', $chunk)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $pipesAll[] = $pipes;
    }
    foreach ($procs as $i => $p) {
        $err = stream_get_contents($pipesAll[$i][2]) . stream_get_contents($pipesAll[$i][1]);
        same(0, proc_close($p), 'worker finished: ' . $err);
    }
    unlink($script);
    $numbers = array_column(q_all("SELECT receipt_number FROM payments WHERE status = 'approved' ORDER BY receipt_number"), 'receipt_number');
    same(24, count(array_unique($numbers)));
    $year = (new DateTimeImmutable('now', site_tz()))->format('Y');
    same(array_map(static fn (int $n): string => sprintf('RCP-%s-%05d', $year, $n), range(1, 24)), $numbers);
    same(30000, (int) payment_item($item)['collected_minor'], '£1 + £2 + … + £24');
    same([], payments_reconcile());
});

test('D2: receipts are private: only the member and payments admins; never in the media library', function (): void {
    pay_reset();
    $m = pay_member();
    $o = pay_member('Other');
    $payAdmin = dm_staff(['payments']);
    $membersAdmin = dm_staff(['members']);
    $jpg = fixture_jpeg(800, 600, "Exif\0\0GPS-SECRET");
    $mediaId = payment_store_receipt(['tmp_name' => $jpg, 'name' => 'receipt.jpg', 'size' => filesize($jpg), 'error' => UPLOAD_ERR_OK], $m['user_id'], false);
    $media = media_find($mediaId);
    same(['private', 'receipts'], [$media['visibility'], $media['folder']]);
    not_contains('GPS-SECRET', (string) file_get_contents(media_file_path($media)), 'EXIF stripped');
    $item = pay_item(['name' => 'Gift', 'amount_mode' => 'any', 'amount' => '', 'applies_to' => 'nobody']);
    payment_item_set_status($item, 'open');
    $p = pay_report($m, 'i' . $item, '3');
    q('UPDATE payments SET receipt_media_id = ? WHERE id = ?', [$mediaId, $p]);
    ok(media_can_view($media, dm_user($m['user_id'])), 'owner');
    ok(!media_can_view($media, dm_user($o['user_id'])), 'other member');
    ok(media_can_view($media, $payAdmin), 'payments admin');
    ok(!media_can_view($media, $membersAdmin), 'admin without payments');
    ok(!media_can_view($media, null), 'visitor');
    $pdf = fixture_file("%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n", 'pdf');
    same('application/pdf', media_find((int) payment_store_receipt(['tmp_name' => $pdf, 'name' => 'r.pdf', 'size' => filesize($pdf), 'error' => UPLOAD_ERR_OK], $m['user_id'], false))['mime']);
    $big = fixture_file(str_repeat('x', 10), 'txt');
    throws(Core\UploadException::class, fn () => payment_store_receipt(['tmp_name' => $big, 'name' => 'r.txt', 'size' => filesize($big), 'error' => UPLOAD_ERR_OK], $m['user_id'], false));
    throws(Core\UploadException::class, fn () => payment_store_receipt(['tmp_name' => $pdf, 'name' => 'r.pdf', 'size' => 6 * 1024 * 1024, 'error' => UPLOAD_ERR_OK], $m['user_id'], false));
});

test('D2: account deletion anonymises records, removes unpaid charges, keeps receipts until retention', function (): void {
    pay_reset();
    $m = pay_member();
    $admin = dm_staff(['payments']);
    $number = (string) q_val('SELECT member_number FROM members WHERE id = ?', [$m['member_id']]);
    $dues = pay_item();
    $levy = pay_item(['name' => 'Hall levy', 'amount' => '50']);
    payment_item_set_status($dues, 'open');
    payment_item_set_status($levy, 'open');
    $charge = pay_charge($dues, $m['member_id']);
    $p = pay_report($m, 'c' . $charge['id'], '10', ['payer_name' => 'My Spouse']);
    payment_approve($p, $admin);
    $media = fixture_image_media($m['user_id'], 'private');
    q("UPDATE media SET folder = 'receipts' WHERE id = ?", [$media]);
    q('UPDATE payments SET receipt_media_id = ? WHERE id = ?', [$media, $p]);
    $waiting = pay_report($m, 'c' . $charge['id'], '5');
    $export = member_export($m['user_id']);
    same(2, count($export['payments']['records']));
    same(2, count($export['payments']['charges']));
    ok(account_delete($m['user_id']));
    $row = payment_find($p);
    same([null, 'Former member #' . $number, ''], [$row['member_id'], $row['former_label'], $row['payer_name']]);
    same('Former member #' . $number, payment_member_label($row));
    same('withdrawn', payment_find($waiting)['status']);
    same(0, (int) q_val('SELECT COUNT(*) FROM payment_charges WHERE item_id = ?', [$levy]), 'unpaid charge removed');
    $c = q_one('SELECT * FROM payment_charges WHERE id = ?', [$charge['id']]);
    same([null, 'waived'], [$c['member_id'], $c['charge_status']]);
    same([0, 0], [(int) payment_item($levy)['expected_minor'], (int) payment_item($levy)['outstanding_minor']]);
    same([], payments_reconcile());
    same('0 receipt file(s) deleted', cron_payment_retention());
    same('1 receipt file(s) deleted', cron_payment_retention(new DateTimeImmutable('+8 years', utc())));
    same(null, media_find($media));
});

test('D2: due-date reminders: before and after, once per charge per stage, balance only, suppression respected', function (): void {
    pay_reset();
    $tz = site_tz();
    $a = pay_member('Owes');
    $b = pay_member('Paid');
    $c = pay_member('Suppressed');
    q("INSERT INTO email_suppressions (email, reason) VALUES (?, 'complaint')", [$c['user']['email']]);
    $admin = dm_staff(['payments']);
    $item = pay_item(['due_date' => '2027-03-31']);
    payment_item_set_status($item, 'open');
    $pb = pay_report($b, 'c' . pay_charge($item, $b['member_id'])['id'], '30');
    payment_approve($pb, $admin);
    same('0 reminder(s)', cron_payment_reminders(new DateTimeImmutable('2027-03-20 09:00', $tz)), '11 days before: too early');
    same('1 reminder(s)', cron_payment_reminders(new DateTimeImmutable('2027-03-24 09:00', $tz)), '7 days before');
    same('0 reminder(s)', cron_payment_reminders(new DateTimeImmutable('2027-03-25 09:00', $tz)), 'once per stage');
    same('0 reminder(s)', cron_payment_reminders(new DateTimeImmutable('2027-04-10 09:00', $tz)));
    same('1 reminder(s)', cron_payment_reminders(new DateTimeImmutable('2027-04-14 09:00', $tz)), '14 days after');
    $to = array_column(q_all("SELECT to_email FROM email_queue WHERE dedupe_key LIKE 'payrem:%'"), 'to_email');
    same([$a['user']['email'], $a['user']['email']], $to);
    same(['send' => 1, 'skipped' => 1], payment_remind_count($item));
    same(1, payment_remind_unpaid($item));
    same(0, payment_remind_unpaid($item), 'at most once a day per charge');
});

test('D2: reports group by item, month and method (ONLY_FULL_GROUP_BY safe); dashboard numbers', function (): void {
    pay_reset();
    $m = pay_member();
    $admin = dm_staff(['payments']);
    $item = pay_item(['name' => 'Gift', 'amount_mode' => 'any', 'amount' => '', 'applies_to' => 'nobody']);
    payment_item_set_status($item, 'open');
    $old = db()->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    db()->exec("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES'");
    try {
        foreach ([['2026-01-10', '10', 'cash'], ['2026-01-20', '20', 'bank_transfer'], ['2026-02-05', '5', 'cash']] as [$day, $amount, $method]) {
            payment_approve(pay_report($m, 'i' . $item, $amount, ['paid_on' => $day, 'method' => $method]), $admin);
        }
        payment_approve(pay_report($m, 'other', '7', ['other_name' => 'Tea', 'paid_on' => '2026-02-06', 'method' => 'cash']), $admin);
        $r = payment_report('2026-01-01', '2026-12-31');
        same(4200, $r['total']);
        same([['Gift', 3500, 3], ['Other', 700, 1]], array_map(static fn (array $x): array => [$x['name'], $x['total'], $x['n']], $r['items']));
        same([['2026-01', 3000], ['2026-02', 1200]], array_map(static fn (array $x): array => [$x['ym'], (int) $x['total']], $r['months']));
        same(['cash' => 2200, 'bank_transfer' => 2000], array_map('intval', array_column($r['methods'], 'total', 'method')));
        payments_reconcile();
        payment_dashboard();
    } finally {
        db()->exec('SET SESSION sql_mode = ' . db()->quote((string) $old));
    }
    same(0, payment_dashboard()['pending']);
});

test('D2: the receipt verification lookup shows only initials and invalidates reversed receipts', function (): void {
    pay_reset();
    $m = pay_member('Zara');
    $admin = dm_staff(['payments']);
    $item = pay_item(['name' => 'Gift', 'amount_mode' => 'any', 'amount' => '', 'applies_to' => 'nobody']);
    payment_item_set_status($item, 'open');
    $p = pay_report($m, 'i' . $item, '12');
    payment_approve($p, $admin);
    $code = (string) payment_find($p)['receipt_code'];
    $v = payment_verify($code);
    same('Z. P.', payment_initials($v));
    same(null, $v['reversal_id']);
    payment_reverse($p, $admin, 'Refunded');
    ok(payment_verify($code)['reversal_id'] !== null);
    same(null, payment_verify('nothex'));
});
