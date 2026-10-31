<?php
declare(strict_types=1);

// Addendum D1 smoke checks through php -S: birthday list and CSV, notice
// settings, overview tabs and the bell. Included by tests/smoke.php after
// smoke_addendum_c3.php (uses Browser, check(), $admin (super admin), $base,
// login_as(), meta_csrf(), status_is()).

$bdToday = new DateTimeImmutable('now', site_tz());
$bdUid = create_user('bday.smoke@example.test', 'bday-smoke-password', 'member', 'Bea Birthday', 'active');
$bdMid = db_insert('members', ['user_id' => $bdUid, 'first_name' => 'Bea', 'last_name' => 'Birthday', 'membership_type' => 'Standard',
    'birth_day' => (int) $bdToday->format('j'), 'birth_month' => (int) $bdToday->format('n'), 'birth_year' => 1977, 'member_number' => 'BDY-26-0001',
    'verified_at' => gmdate('Y-m-d H:i:s'), 'prefs' => json_encode(['newsletters' => false])]);

$h = $admin->get('/admin/members/birthdays');
check('D1: Members → Birthdays lists this month with number, day and a message link', $h['status'] === 200 && str_contains($h['body'], 'Bea Birthday') && str_contains($h['body'], 'BDY-26-0001')
    && str_contains($h['body'], '/admin/messages/new?to=' . $bdUid) && !str_contains($h['body'], '1977'));
$other = (int) $bdToday->modify('first day of next month')->format('n');
check('D1: … filtered by month', !str_contains($admin->get('/admin/members/birthdays?month=' . $other)['body'], 'Bea Birthday'));
$r = $admin->get('/admin/members/birthdays.csv?month=' . (int) $bdToday->format('n'));
check('D1: CSV export has name, number, day and month only (no year)', $r['status'] === 200 && str_contains($r['type'], 'text/csv') && str_contains($r['body'], "name,member_number,day,month")
    && str_contains($r['body'], '"Bea Birthday",BDY-26-0001,' . $bdToday->format('j') . ',' . $bdToday->format('n')) && !str_contains($r['body'], '1977'));
check('D1: the export is audited', (int) q_val("SELECT COUNT(*) FROM audit_log WHERE action = 'export' AND item_type = 'birthdays'") >= 1);

$h = $admin->get('/admin');
check('D1: overview birthday card has Today / This week / This month tabs with counts', str_contains($h['body'], 'href="/admin?bday=week"') && str_contains($h['body'], 'href="/admin?bday=month"')
    && (bool) preg_match('#aria-current="page">Today <span class="count tabular">\d+</span>#', $h['body']) && str_contains($h['body'], 'Bea Birthday'));
check('D1: … the week tab works without JavaScript', str_contains($admin->get('/admin?bday=week')['body'], 'aria-current="page">This week'));
check('D1: the bell says how many birthdays today', (bool) preg_match('/title="[^"]*\d+ birthdays? today/', $h['body']));

status_is($admin, '/admin/settings/birthdays', 200, 'Birthday notices');
$r = $admin->submit('/admin/settings/birthdays', '/admin/settings/birthdays', ['daily' => '1', 'recipients' => 'chosen']);
check('D1: "only these people" without anyone is refused', $r['status'] === 303 && str_contains($admin->get('/admin/settings/birthdays')['body'], 'Choose at least one person'));
$samId = (int) q_val("SELECT id FROM users WHERE email = 'sam@example.test'");
$r = $admin->submit('/admin/settings/birthdays', '/admin/settings/birthdays', ['daily' => '1', 'monthly' => '1', 'skip_empty' => '1', 'recipients' => 'chosen', 'chosen[]' => (string) $samId]);
$saved = setting_fresh('birthdays.notices');
check('D1: settings saved (weekly off, chosen recipient) and audited', $r['status'] === 303 && $saved['weekly'] === false && $saved['monthly'] === true && $saved['chosen'] === [$samId]
    && (int) q_val("SELECT COUNT(*) FROM audit_log WHERE item_type = 'settings' AND summary LIKE 'birthday notices%'") >= 1);
create_user('bday-radio@example.test', 'bday-radio-password', 'admin', 'Radio Only', 'active', ['radio']);
$radioOnly = login_as($base, 'bday-radio@example.test', 'bday-radio-password');
check('D1: admins without the members permission get no birthday list or card', $radioOnly->get('/admin/members/birthdays')['status'] === 403 && !str_contains($radioOnly->get('/admin')['body'], 'bday-title'));
$admin->submit('/admin/settings/birthdays', '/admin/settings/birthdays', ['daily' => '1', 'weekly' => '1', 'monthly' => '1', 'skip_empty' => '1', 'recipients' => 'members']);
q('DELETE FROM users WHERE id = ?', [$bdUid]);
unset($bdMid);

// ---- D2 payments -------------------------------------------------------------------------
settings_all([]);
check('D2: the installer stored the chosen currency and the demo has dues, a levy and donations', site_currency() === 'GBP'
    && (int) q_val("SELECT COUNT(*) FROM payment_items WHERE status = 'open'") === 3 && (int) q_val("SELECT COUNT(*) FROM payments WHERE status = 'pending'") >= 2 && payments_reconcile() === []);
$payMember = static function (string $first, string $email): array {
    $uid = create_user($email, 'pay-smoke-password', 'member', "$first Payer", 'active');
    $mid = db_insert('members', ['user_id' => $uid, 'first_name' => $first, 'last_name' => 'Payer', 'membership_type' => 'Standard', 'birth_day' => 3, 'birth_month' => 4,
        'member_number' => 'PAY-26-' . random_int(1000, 9999), 'membership_fee_status' => 'due', 'verified_at' => gmdate('Y-m-d H:i:s', time() - 86400 * 3), 'prefs' => json_encode(['newsletters' => false])]);
    return [$uid, $mid];
};
[$pUid, $pMid] = $payMember('Penny', 'penny.pay@example.test');
[$qUid, $qMid] = $payMember('Quentin', 'quentin.pay@example.test');
$penny = login_as($base, 'penny.pay@example.test', 'pay-smoke-password');
$quentin = login_as($base, 'quentin.pay@example.test', 'pay-smoke-password');
$jpg = static function (string $name): CURLFile {
    $f = sys_get_temp_dir() . '/pay-' . bin2hex(random_bytes(3)) . '.jpg';
    $im = imagecreatetruecolor(400, 300);
    imagefilledrectangle($im, 0, 0, 400, 300, imagecolorallocate($im, 230, 240, 250));
    imagejpeg($im, $f);
    return new CURLFile($f, 'image/jpeg', $name);
};

// Admin creates and opens an item.
status_is($admin, '/admin/payments/items/new', 200, 'New payment item');
$r = $admin->submit('/admin/payments/items/new', '/admin/payments/items', ['name' => 'Smoke dues 2027', 'item_kind' => 'dues', 'amount_mode' => 'fixed', 'amount' => '40', 'applies_to' => 'everyone',
    'allow_part' => '1', 'include_new_members' => '1', 'counts_as_dues' => '1', 'covers_until' => '2027-12-31', 'remind_enabled' => '1', 'due_date' => '2027-03-31']);
$itemId = (int) q_val("SELECT id FROM payment_items WHERE name = 'Smoke dues 2027'");
check('D2: a new item is saved as a draft', $r['status'] === 303 && $itemId > 0 && q_val('SELECT status FROM payment_items WHERE id = ?', [$itemId]) === 'draft');
$r = $admin->submit('/admin/payments/items/' . $itemId, '/admin/payments/items/' . $itemId . '/status', ['status' => 'open']);
$chargeId = (int) q_val('SELECT id FROM payment_charges WHERE item_id = ? AND member_id = ?', [$itemId, $pMid]);
check('D2: opening it creates a charge for every active member', $r['status'] === 303 && $chargeId > 0 && (int) q_val('SELECT expected_minor FROM payment_items WHERE id = ?', [$itemId]) === 4000 * (int) q_val('SELECT COUNT(*) FROM payment_charges WHERE item_id = ?', [$itemId]));
$r = $admin->submit('/admin/payments/items/' . $itemId . '/edit', '/admin/payments/items/' . $itemId, ['name' => 'Smoke dues 2027', 'item_kind' => 'dues', 'amount' => '1', 'applies_to' => 'nobody', 'allow_part' => '1', 'counts_as_dues' => '1', 'covers_until' => '2027-12-31', 'due_date' => '2027-03-31', 'remind_enabled' => '1', 'include_new_members' => '1']);
check('D2: amount and audience are locked once the item is open', (int) q_val('SELECT amount_minor FROM payment_items WHERE id = ?', [$itemId]) === 4000 && q_val('SELECT applies_to FROM payment_items WHERE id = ?', [$itemId]) === 'everyone');

// Member side.
$h = $penny->get('/account/payments');
check('D2: members see Payments in the account menu and what they owe', $h['status'] === 200 && str_contains($h['body'], 'href="/account/payments" aria-current="page"') && str_contains($h['body'], 'Smoke dues 2027') && str_contains($h['body'], '£40.00'));
$h = $penny->get('/account/payments/new?charge=' . $chargeId);
check('D2: "Report a payment" is pre-filled with the balance and shows the bank details', str_contains($h['body'], 'value="40.00"') && str_contains($h['body'], 'How to pay') && str_contains($h['body'], 'value="c' . $chargeId . '" data-amount="40.00"'));
$base1 = ['target' => 'c' . $chargeId, 'amount' => '15.00', 'paid_on' => payment_today(), 'method' => 'bank_transfer', 'reference' => 'PENNY-REF-1', 'payer_name' => '', 'note' => '<b>first part</b>'];
$r = $penny->post('/account/payments', ['_csrf' => $penny->csrf('/account/payments/new')] + $base1);
check('D2: a report without a receipt is refused (receipt required by default)', $r['status'] === 303 && str_contains($r['location'], '/account/payments/new') && str_contains($penny->get($r['location'])['body'], 'Upload the receipt'));
$r = $penny->post('/account/payments', ['_csrf' => $penny->csrf('/account/payments/new'), 'receipt' => $jpg('bank.jpg')] + $base1);
$p1 = preg_match('#/account/payments/(\d+)$#', $r['location'], $m) ? (int) $m[1] : 0;
check('D2: reporting a payment with a receipt saves it as pending (PRG)', $r['status'] === 303 && $p1 > 0 && q_val('SELECT status FROM payments WHERE id = ?', [$p1]) === 'pending');
$h = $penny->get('/account/payments/' . $p1);
check('D2: the record page shows status, escaped note and the timeline', str_contains($h['body'], 'Pending') && str_contains($h['body'], '&lt;b&gt;first part&lt;/b&gt;') && str_contains($h['body'], 'Reported'));
$media1 = (int) q_val('SELECT receipt_media_id FROM payments WHERE id = ?', [$p1]);
$mediaUrl = media_original_url(media_find($media1));
check('D2: the receipt file: owner 200, other member 404, visitor 404, not in the media library', $penny->get($mediaUrl)['status'] === 200 && $quentin->get($mediaUrl)['status'] === 404
    && (new Browser($base))->get($mediaUrl)['status'] === 404 && $admin->get('/admin/media/' . $media1)['status'] === 404 && !str_contains($admin->get('/admin/media?folder=receipts')['body'], '/media/' . $media1 . '/'));
check('D2: the receipt file is served with nosniff and a sandbox CSP', str_contains($penny->get($mediaUrl)['headers'], 'X-Content-Type-Options: nosniff') && str_contains($penny->last['headers'], 'sandbox'));
check('D2: members cannot open someone else\'s record (404)', $quentin->get('/account/payments/' . $p1)['status'] === 404 && $quentin->get('/account/payments/' . $p1 . '/edit')['status'] === 404);
$r = $quentin->post('/account/payments/' . $p1 . '/withdraw', ['_csrf' => $quentin->csrf('/account/payments')]);
check('D2: … nor withdraw it', $r['status'] === 404 && q_val('SELECT status FROM payments WHERE id = ?', [$p1]) === 'pending');
// Duplicate warning (same reference): warned, kept upload, then saved with "save anyway".
$r = $penny->post('/account/payments', ['_csrf' => $penny->csrf('/account/payments/new'), 'receipt' => $jpg('again.jpg')] + ['reference' => 'penny-ref-1', 'amount' => '5'] + $base1);
$h = $penny->get('/account/payments/new');
check('D2: a possible duplicate warns and asks to confirm (the uploaded receipt is kept)', $r['status'] === 303 && str_contains($h['body'], 'Possible duplicate of payment #' . $p1) && str_contains($h['body'], 'name="confirm"') && str_contains($h['body'], 'name="held_receipt"'));
preg_match('/name="held_receipt" value="([^"]+)"/', $h['body'], $hm);
$before = (int) q_val('SELECT COUNT(*) FROM payments WHERE member_id = ?', [$pMid]);
$r = $penny->post('/account/payments', ['_csrf' => $penny->csrf('/account/payments/new'), 'held_receipt' => $hm[1] ?? '', 'confirm' => '1'] + ['reference' => 'penny-ref-1', 'amount' => '5'] + $base1);
$p2 = (int) q_val('SELECT MAX(id) FROM payments WHERE member_id = ?', [$pMid]);
check('D2: "save anyway" saves it with the kept receipt', $r['status'] === 303 && (int) q_val('SELECT COUNT(*) FROM payments WHERE member_id = ?', [$pMid]) === $before + 1 && q_val('SELECT receipt_media_id FROM payments WHERE id = ?', [$p2]) !== null);
$r = $quentin->post('/account/payments', ['_csrf' => $quentin->csrf('/account/payments/new'), 'held_receipt' => $hm[1] ?? '', 'confirm' => '1', 'target' => 'other', 'other_name' => 'Steal', 'amount' => '1', 'paid_on' => payment_today(), 'method' => 'cash']);
check('D2: another member cannot reuse someone\'s held receipt token', (int) q_val('SELECT COUNT(*) FROM payments WHERE member_id = ? AND receipt_media_id IS NOT NULL', [$qMid]) === 0);

// Admin queue, notifications, review.
$h = $admin->get('/admin/payments');
check('D2: the pending queue lists the reports with receipt previews, warnings and a sidebar count', $h['status'] === 200 && str_contains($h['body'], 'Penny Payer') && str_contains($h['body'], '/media/' . $media1 . '/w/400')
    && str_contains($h['body'], 'Possible duplicate') && (bool) preg_match('#Pending</span><span class="nav-count tabular">\d+#', $h['body']) && (bool) preg_match('/title="[^"]*payment reports? to review/', $h['body']));
check('D2: the queue has a contextual help link', str_contains($h['body'], 'href="/admin/help/manager-guide/payments"'));
cron_payment_notices();
$samMail = (string) q_val("SELECT text_body FROM email_queue WHERE to_email = 'sam@example.test' AND dedupe_key LIKE 'payrep:%' ORDER BY id DESC LIMIT 1");
check('D2: admins with the payments permission get a report email (queued, batched)', str_contains($samMail, 'Penny Payer') && str_contains($samMail, '/admin/payments/' . $p1));
$h = $admin->get('/admin/payments/' . $p1);
check('D2: the review screen shows the receipt, the timeline with names, and next/previous', $h['status'] === 200 && str_contains($h['body'], 'Approve and send receipt') && str_contains($h['body'], 'by Penny Payer') && (str_contains($h['body'], 'data-pay-next') || str_contains($h['body'], 'data-pay-prev')));
$r = $admin->post('/admin/payments/' . $p1 . '/approve', ['note' => 'x']);
check('D2: approving without a CSRF token is refused (419)', $r['status'] === 419 && q_val('SELECT status FROM payments WHERE id = ?', [$p1]) === 'pending');
$version = static fn (Browser $b, string $page): string => preg_match('/name="version" value="([a-f0-9]{20})"/', $b->get($page)['body'], $vm) ? $vm[1] : '';
$r = $admin->submit('/admin/payments/' . $p1, '/admin/payments/' . $p1 . '/approve', ['note' => 'x', 'version' => str_repeat('0', 20)]);
check('D review: approving with a stale version token is refused ("changed since you opened it")', $r['status'] === 303 && q_val('SELECT status FROM payments WHERE id = ?', [$p1]) === 'pending'
    && str_contains($admin->get('/admin/payments/' . $p1)['body'], 'changed since you opened it'));
$r = $admin->submit('/admin/payments/' . $p1, '/admin/payments/' . $p1 . '/approve', ['note' => 'Seen on the statement', 'version' => $version($admin, '/admin/payments/' . $p1)]);
$rcp = (string) q_val('SELECT receipt_number FROM payments WHERE id = ?', [$p1]);
check('D2: approve → receipt number, charge part-paid, audited', $r['status'] === 303 && (bool) preg_match('/^RCP-\d{4}-\d{5}$/', $rcp) && q_val('SELECT charge_status FROM payment_charges WHERE id = ?', [$chargeId]) === 'part'
    && (int) q_val("SELECT COUNT(*) FROM audit_log WHERE action = 'approve' AND item_type = 'payment' AND item_id = ?", [$p1]) === 1);
check('D2: the member is emailed with a receipt link', str_contains((string) q_val('SELECT text_body FROM email_queue WHERE to_email = ? AND dedupe_key LIKE ? ORDER BY id DESC LIMIT 1', ['penny.pay@example.test', 'pay:' . $p1 . ':payment_approved:%']), '/account/payments/' . $p1 . '/receipt'));
$h = $penny->get('/account/payments/' . $p1 . '/receipt');
check('D2: printable receipt: number, amount in figures and words, QR to the verify page', $h['status'] === 200 && str_contains($h['body'], $rcp) && str_contains($h['body'], '£15.00') && str_contains($h['body'], 'Fifteen pounds')
    && (bool) preg_match('#data-qr="[^"]*/verify-receipt/[a-f0-9]{24}"#', $h['body']) && str_contains($h['body'], 'payments.css'));
check('D2: the timeline never names the admin to the member', !str_contains($penny->get('/account/payments/' . $p1)['body'], 'Sam Smoke') && str_contains($penny->last['body'], 'by Treasurer'));
$code = (string) q_val('SELECT receipt_code FROM payments WHERE id = ?', [$p1]);
$v = (new Browser($base))->get('/verify-receipt/' . $code);
check('D2: the public verify page: valid, amount, date, initials only', $v['status'] === 200 && str_contains($v['body'], 'Valid receipt') && str_contains($v['body'], 'P. P.') && !str_contains($v['body'], 'Penny') && !str_contains($v['body'], 'PAY-26-'));
check('D2: unknown receipt codes are "not valid" (404)', (new Browser($base))->get('/verify-receipt/' . str_repeat('ab', 12))['status'] === 404);
status_is($quentin, '/account/payments/' . $p1 . '/receipt', 404);
// Reject with a preset, ask for info, member answers by editing.
$r = $admin->submit('/admin/payments/' . $p2, '/admin/payments/' . $p2 . '/info', ['question' => 'Is this the same transfer as #' . $p1 . '?']);
check('D2: ask for info → needs info, member emailed the question', $r['status'] === 303 && q_val('SELECT status FROM payments WHERE id = ?', [$p2]) === 'needs_info'
    && str_contains((string) q_val('SELECT text_body FROM email_queue WHERE dedupe_key LIKE ? ORDER BY id DESC LIMIT 1', ['pay:' . $p2 . ':payment_needs_info:%']), 'same transfer'));
$h = $penny->get('/account/payments/' . $p2 . '/edit');
check('D2: the member sees the question on the edit form', str_contains($h['body'], 'same transfer') && str_contains($h['body'], 'Send answer'));
$r = $penny->post('/account/payments/' . $p2, ['_csrf' => $penny->csrf('/account/payments/' . $p2 . '/edit'), 'confirm' => '1'] + ['reference' => 'PENNY-REF-2', 'amount' => '5', 'note' => 'No, a second transfer'] + $base1);
check('D2: answering returns it to the queue (pending)', $r['status'] === 303 && q_val('SELECT status FROM payments WHERE id = ?', [$p2]) === 'pending' && q_val('SELECT reference FROM payments WHERE id = ?', [$p2]) === 'PENNY-REF-2');
$r = $admin->submit('/admin/payments/' . $p2, '/admin/payments/' . $p2 . '/reject', ['preset' => PAYMENT_REJECT_PRESETS[0], 'reason' => '']);
check('D2: reject with a preset reason', $r['status'] === 303 && q_val('SELECT status FROM payments WHERE id = ?', [$p2]) === 'rejected' && q_val('SELECT review_note FROM payments WHERE id = ?', [$p2]) === PAYMENT_REJECT_PRESETS[0]);
// Reversal.
$r = $admin->submit('/admin/payments/' . $p1, '/admin/payments/' . $p1 . '/reverse', ['preset' => 'Bounced transfer']);
check('D2: reverse → linked negative entry, balance back, receipt invalid', $r['status'] === 303 && (int) q_val('SELECT amount_minor FROM payments WHERE reverses_id = ?', [$p1]) === -1500
    && q_val('SELECT charge_status FROM payment_charges WHERE id = ?', [$chargeId]) === 'unpaid' && str_contains((new Browser($base))->get('/verify-receipt/' . $code)['body'], 'Not a valid receipt')
    && str_contains($penny->get('/account/payments/' . $p1 . '/receipt')['body'], 'no longer valid'));
check('D2: the ledger counters match after approve and reverse (reconcile)', payments_reconcile() === []);
// Overpayment needs an explicit confirmation on the single approve.
$r = $penny->post('/account/payments', ['_csrf' => $penny->csrf('/account/payments/new'), 'receipt' => $jpg('over.jpg'), 'confirm' => '1'] + ['reference' => 'PENNY-OVER', 'amount' => '45'] + $base1);
$pOver = (int) q_val("SELECT id FROM payments WHERE reference = 'PENNY-OVER'");
$h = $admin->get('/admin/payments/' . $pOver);
check('D review: the review screen asks to confirm an overpayment', str_contains($h['body'], 'Approve anyway (overpayment'));
$admin->submit('/admin/payments/' . $pOver, '/admin/payments/' . $pOver . '/approve', ['version' => $version($admin, '/admin/payments/' . $pOver)]);
check('D review: … and refuses it without the tick', q_val('SELECT status FROM payments WHERE id = ?', [$pOver]) === 'pending' && str_contains($admin->get('/admin/payments/' . $pOver)['body'], 'above what is owed'));
$admin->submit('/admin/payments/' . $pOver, '/admin/payments/' . $pOver . '/approve', ['version' => $version($admin, '/admin/payments/' . $pOver), 'overpay' => '1']);
check('D review: … and approves it with the tick', q_val('SELECT status FROM payments WHERE id = ?', [$pOver]) === 'approved' && payments_reconcile() === []);

// Admin records cash for Quentin; bulk approve.
$qCharge = (int) q_val('SELECT id FROM payment_charges WHERE item_id = ? AND member_id = ?', [$itemId, $qMid]);
status_is($admin, '/admin/payments/record?q=Quentin', 200, 'Quentin Payer');
$r = $admin->submit('/admin/payments/record?member=' . $qMid, '/admin/payments/record', ['member_id' => (string) $qMid, 'target' => 'c' . $qCharge, 'amount' => '40', 'paid_on' => payment_today(), 'method' => 'cash', 'note' => 'AGM']);
check('D2: recording cash for a member saves it approved with "Recorded by"; dues paid sets the fee status and card date', $r['status'] === 303 && q_val('SELECT status FROM payments WHERE member_id = ? AND recorded_by IS NOT NULL', [$qMid]) === 'approved'
    && q_val('SELECT membership_fee_status FROM members WHERE id = ?', [$qMid]) === 'paid' && q_val('SELECT card_valid_until FROM members WHERE id = ?', [$qMid]) === '2027-12-31');
$queue = $admin->get('/admin/payments')['body'];
preg_match_all('/name="v\[(\d+)\]" value="([a-f0-9]{20})"/', $queue, $vm, PREG_SET_ORDER);
$ids = array_map(static fn (array $x): int => (int) $x[1], $vm);
$fields = ['_csrf' => meta_csrf($admin, '/admin/payments')];
foreach ($vm as $i => [, $vid, $vv]) {
    $fields['ids[' . $i . ']'] = $vid;
    $fields['v[' . $vid . ']'] = $vv;
}
$r = $admin->post('/admin/payments/bulk-approve', $fields);
check('D2: bulk approve approves the selected reports without warnings (with their version tokens)', $r['status'] === 303 && $ids !== [] && (int) q_val('SELECT COUNT(*) FROM payments WHERE status = \'approved\' AND id IN (' . implode(',', $ids) . ')') === count($ids));
// Charges: waive and change amount on the member's payments page.
status_is($admin, '/admin/payments/member/' . $pMid, 200, 'Charges');
$r = $admin->submit('/admin/payments/member/' . $pMid, '/admin/payments/charges/' . $chargeId, ['action' => 'amount', 'amount' => '20', 'reason' => 'Concession']);
$r2 = $admin->submit('/admin/payments/member/' . $pMid, '/admin/payments/charges/' . $chargeId, ['action' => 'waive', 'reason' => '']);
check('D2: change a charge (reason required), audited', (int) q_val('SELECT amount_minor FROM payment_charges WHERE id = ?', [$chargeId]) === 2000 && q_val('SELECT waived FROM payment_charges WHERE id = ?', [$chargeId]) === 0
    && (int) q_val("SELECT COUNT(*) FROM audit_log WHERE item_type = 'payment_charge' AND item_id = ?", [$chargeId]) === 1);
check('D2: the member page links to the member\'s payments for payments admins', str_contains($admin->get('/admin/members/' . $pMid)['body'], 'href="/admin/payments/member/' . $pMid . '"'));
// Lists, exports, reports, item page, reminders.
$h = $admin->get('/admin/payments/all?status=reversed');
check('D2: all payments filtered by status (reversed)', $h['status'] === 200 && str_contains($h['body'], '/admin/payments/' . $p1 . '"') && !str_contains($h['body'], '/admin/payments/' . $p2 . '"'));
$r = $admin->get('/admin/payments/all.csv');
check('D2: CSV export with the spec columns', $r['status'] === 200 && str_contains($r['type'], 'text/csv') && str_contains($r['body'], 'receipt_number,member_number,name,item,amount,currency,date_paid,method,reference,status,reviewer,reviewed_at') && str_contains($r['body'], $rcp));
$h = $admin->get('/admin/payments/items/' . $itemId);
check('D2: item page: totals, progress bar, tabs with counts, remind count', $h['status'] === 200 && str_contains($h['body'], '<progress') && str_contains($h['body'], 'Part-paid') && str_contains($h['body'], 'Remind unpaid'));
check('D2: item tab CSV', str_contains($admin->get('/admin/payments/items/' . $itemId . '.csv?tab=unpaid')['body'], 'member_number,name,email,amount,paid,balance,status'));
$before = (int) q_val("SELECT COUNT(*) FROM email_queue WHERE dedupe_key LIKE 'payremind:%'");
$r = $admin->submit('/admin/payments/items/' . $itemId, '/admin/payments/items/' . $itemId . '/remind', []);
$after = (int) q_val("SELECT COUNT(*) FROM email_queue WHERE dedupe_key LIKE 'payremind:%'");
$admin->submit('/admin/payments/items/' . $itemId, '/admin/payments/items/' . $itemId . '/remind', []);
check('D2: "Remind unpaid" queues one email per unpaid member, at most once a day', $r['status'] === 303 && $after > $before && (int) q_val("SELECT COUNT(*) FROM email_queue WHERE dedupe_key LIKE 'payremind:%'") === $after);
$r = $admin->submit('/admin/payments/items/' . $itemId, '/admin/payments/items/' . $itemId . '/message', ['title' => 'Dues', 'body' => 'A friendly reminder about the 2027 dues.']);
check('D2: "Message unpaid" starts an announcement', $r['status'] === 303 && str_starts_with($r['location'], '/admin/messages/') && (int) q_val("SELECT COUNT(*) FROM conversations WHERE kind = 'announcement' AND title = 'Dues'") === 1);
$h = $admin->get('/admin/payments/reports?from=2000-01-01&to=' . payment_today());
check('D2: reports by item, month and method', $h['status'] === 200 && str_contains($h['body'], 'By item') && str_contains($h['body'], 'By month') && str_contains($h['body'], 'By method') && str_contains($h['body'], 'Smoke dues 2027'));
check('D2: report CSV', str_contains($admin->get('/admin/payments/reports.csv')['body'], 'group,name,payments,total,currency'));
$r = $admin->submit('/admin/payments/items/' . $itemId, '/admin/payments/items/' . $itemId . '/duplicate', []);
check('D2: duplicate for next period makes a 2028 draft', $r['status'] === 303 && q_val("SELECT status FROM payment_items WHERE name = 'Smoke dues 2028'") === 'draft');
// Settings and currency lock.
$r = $admin->submit('/admin/settings/payments', '/admin/settings/payments', ['currency' => 'USD', 'receipt_required' => '1', 'remind_before' => '5', 'remind_after' => '10', 'reviewer_label' => 'Treasurer', 'retention_years' => '7', 'bank_details' => '<p>Bank <script>x</script></p>']);
settings_all([]);
check('D2: settings saved; currency locked once payments exist; bank details sanitised', $r['status'] === 303 && site_currency() === 'GBP' && payment_settings()['remind_before'] === 5 && !str_contains(payment_settings()['bank_details'], '<script'));
// Impersonation is read-only for payments.
$admin->submit('/admin/impersonate/' . $pUid, '/admin/impersonate/' . $pUid, ['reason' => 'payments check', 'allow_changes' => '1']);
$h = $admin->get('/account/payments');
$r = $admin->post('/account/payments', ['_csrf' => $admin->csrf('/account/payments')] + $base1);
check('D2: while impersonating, payments can be read but not reported (403 even with "allow changes")', $h['status'] === 200 && str_contains($h['body'], 'not available while you view the site as a member') && $r['status'] === 403 && str_contains($r['body'], 'Payments are read-only'));
$admin->submit('/account', '/impersonation/stop', []);
check('D2: data export includes charges and payment records', str_contains($penny->get('/account/privacy/export')['body'], '"payments"') && str_contains($penny->last['body'], 'PENNY-REF-2'));
check('D2: payments admins see a Payments card on the overview', str_contains($admin->get('/admin')['body'], 'Collected this month'));
check('D2: members get 403 on /admin/payments', $penny->get('/admin/payments')['status'] === 403);
check('D2: the counters still match the ledger at the end (System health)', payments_reconcile() === [] && payments_reconcile_record() === [] && str_contains($admin->get('/admin/health')['body'], 'totals match the payment records; full check'));
