<?php
declare(strict_types=1);

namespace Account;

use Core\HttpException;

/**
 * D2 member side (/account/payments): what I owe, report a payment, my
 * payment history, edit/withdraw waiting reports, printable receipts.
 * Members only see their own records (every query is scoped to their
 * member id; anything else is 404). Impersonation is read-only here
 * (impersonation_guard() refuses every POST).
 */
final class Payments
{
    /** @return array{0:array,1:array} [user, member] */
    private static function member(): array
    {
        $user = require_login();
        if (is_staff($user)) {
            redirect('/admin/payments');
        }
        if ($user['status'] !== 'active') {
            flash('Please verify your email first: open the link we sent you, or send a new one below.', 'info');
            redirect('/account');
        }
        $member = member_for_user((int) $user['id']);
        if ($member === null) {
            throw new HttpException(404, 'Payments are for member accounts.');
        }
        return [$user, $member];
    }

    private static function page(string $view, string $title, array $data, int $status = 200): void
    {
        Account::shell('payments/' . $view, $title, 'payments', $data + ['extra_css' => ['assets/css/card.css', 'assets/css/payments.css'], 'settings' => payment_settings()], $status);
    }

    /** One of the member's own records (404 otherwise). */
    private static function own(int $id, array $member): array
    {
        $p = payment_find($id);
        if ($p === null || (int) ($p['member_id'] ?? 0) !== (int) $member['id']) {
            throw new HttpException(404);
        }
        return $p;
    }

    public static function index(array $p): void
    {
        [$user, $member] = self::member();
        $years = array_map('intval', array_column(q_all('SELECT DISTINCT YEAR(paid_on) AS y FROM payments WHERE member_id = ? ORDER BY y DESC', [(int) $member['id']]), 'y'));
        $year = (int) query_param('year');
        $year = in_array($year, $years, true) ? $year : 0;
        $records = q_all(
            'SELECT p.*, i.name AS item_name, r.id AS reversal_id FROM payments p LEFT JOIN payment_items i ON i.id = p.item_id LEFT JOIN payments r ON r.reverses_id = p.id
              WHERE p.member_id = ?' . ($year ? ' AND p.paid_on BETWEEN ? AND ?' : '') . ' ORDER BY p.paid_on DESC, p.id DESC LIMIT 500',
            $year ? [(int) $member['id'], "$year-01-01", "$year-12-31"] : [(int) $member['id']]
        );
        $owe = array_values(array_filter(payment_member_charges((int) $member['id'], true), static fn (array $c): bool => payment_charge_balance($c) > 0));
        self::page('index', 'Payments', [
            'user' => $user, 'member' => $member, 'owe' => $owe, 'records' => $records, 'years' => $years, 'year' => $year,
            'totals' => payment_member_totals((int) $member['id']), 'optional' => payment_member_choices((int) $member['id'])['optional'],
            'today' => payment_today(), 'impersonating' => is_impersonating(),
            'waiting' => array_map('intval', array_column(q_all("SELECT charge_id, SUM(amount_minor) AS s FROM payments WHERE member_id = ? AND charge_id IS NOT NULL AND status IN ('pending','needs_info') GROUP BY charge_id", [(int) $member['id']]), 's', 'charge_id')),
        ]);
    }

    /** Form values for a new report: a charge or item from the link, or "same transfer" from an earlier record. */
    private static function defaults(array $member): array
    {
        $v = ['target' => '', 'amount' => '', 'paid_on' => payment_today(), 'method' => 'bank_transfer', 'reference' => '', 'payer_name' => '', 'note' => '', 'other_name' => '', 'from' => ''];
        $charge = (int) query_param('charge');
        $item = (int) query_param('item');
        if ($charge > 0 && ($c = q_one('SELECT * FROM payment_charges WHERE id = ? AND member_id = ?', [$charge, (int) $member['id']])) !== null) {
            $v['target'] = 'c' . $charge;
            $v['amount'] = money_input(payment_charge_balance($c) ?: null);
        } elseif ($item > 0 && ($i = payment_item($item)) !== null && $i['applies_to'] === 'nobody') {
            $v['target'] = 'i' . $item;
            $v['amount'] = money_input($i['amount_minor'] !== null ? (int) $i['amount_minor'] : null);
        }
        $from = (int) query_param('from');
        if ($from > 0 && ($f = payment_reusable_receipt($from, (int) $member['id'])) !== null) {
            $v = ['paid_on' => (string) $f['paid_on'], 'method' => (string) $f['method'], 'reference' => (string) $f['reference'], 'payer_name' => (string) $f['payer_name'], 'from' => (string) $from] + $v;
        }
        return $v;
    }

    public static function create(array $p): void
    {
        [$user, $member] = self::member();
        $old = take_old_input();
        $values = $old['values'] ?: self::defaults($member);
        $from = (int) ($values['from'] ?? 0);
        self::page('form', 'Report a payment', [
            'user' => $user, 'member' => $member, 'values' => $values, 'errors' => $old['errors'], 'warnings' => self::takeWarnings(),
            'choices' => payment_member_choices((int) $member['id']), 'record' => null,
            'from_record' => $from > 0 ? payment_reusable_receipt($from, (int) $member['id']) : null, 'held_receipt' => self::heldReceipt((int) $user['id'], $values),
        ]);
    }

    public static function store(array $p): void
    {
        csrf_check();
        [$user, $member] = self::member();
        [$d, $errors, $warnings] = payment_validate($_POST, (int) $member['id'], false);
        $fromId = (int) input('from') ?: null;
        $from = $fromId !== null ? payment_reusable_receipt($fromId, (int) $member['id']) : null;
        $held = self::heldReceipt((int) $user['id'], $_POST);
        $file = $_FILES['receipt'] ?? null;
        $hasFile = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if (!$hasFile && $held === null && ($from === null || $from['receipt_media_id'] === null) && payment_settings()['receipt_required']) {
            $errors['receipt'] = 'Upload the receipt or proof of payment (a photo or PDF, up to 5 MB).';
        }
        if ($errors || ($warnings && input('confirm') !== '1')) {
            // Keep an uploaded receipt so the member does not have to choose it again.
            $keep = self::holdUpload($user, $file, $errors);
            remember_input(array_merge($_POST, $keep), $errors);
            $_SESSION['pay_warnings'] = $errors ? [] : $warnings;
            redirect('/account/payments/new');
        }
        [$id, $error] = payment_submit($user, (int) $member['id'], $d, $hasFile ? $file : null, $fromId, true, !$hasFile && $held !== null ? (int) $held['id'] : null);
        if ($error !== null) {
            remember_input($_POST, ['receipt' => $error]);
            redirect('/account/payments/new');
        }
        flash('Thank you: your payment report is waiting for confirmation.');
        redirect('/account/payments/' . (int) $id);
    }

    public static function show(array $p): void
    {
        [$user, $member] = self::member();
        $rec = self::own((int) $p['id'], $member);
        $receipt = $rec['receipt_media_id'] !== null ? media_find((int) $rec['receipt_media_id']) : null;
        self::page('show', 'Payment #' . (int) $rec['id'], [
            'user' => $user, 'member' => $member, 'p' => $rec, 'receipt' => $receipt, 'timeline' => payment_timeline((int) $rec['id'], true),
            'can_edit' => in_array($rec['status'], ['pending', 'needs_info'], true) && $rec['entry_type'] === 'payment', 'impersonating' => is_impersonating(),
        ]);
    }

    public static function edit(array $p): void
    {
        [$user, $member] = self::member();
        $rec = self::own((int) $p['id'], $member);
        if (!in_array($rec['status'], ['pending', 'needs_info'], true) || $rec['entry_type'] !== 'payment') {
            flash('This payment can no longer be changed.', 'error');
            redirect('/account/payments/' . (int) $rec['id']);
        }
        $old = take_old_input();
        $target = $rec['charge_id'] !== null ? 'c' . $rec['charge_id'] : ($rec['item_id'] !== null ? 'i' . $rec['item_id'] : 'other');
        $values = $old['values'] ?: ['target' => $target, 'amount' => money_input((int) $rec['amount_minor']), 'paid_on' => (string) $rec['paid_on'], 'method' => (string) $rec['method'],
            'reference' => (string) $rec['reference'], 'payer_name' => (string) $rec['payer_name'], 'note' => (string) $rec['note'], 'other_name' => (string) $rec['other_name']];
        self::page('form', 'Edit payment #' . (int) $rec['id'], [
            'user' => $user, 'member' => $member, 'values' => $values, 'errors' => $old['errors'], 'warnings' => self::takeWarnings(),
            'choices' => payment_member_choices((int) $member['id'], $rec), 'record' => $rec, 'from_record' => null, 'held_receipt' => null,
            'receipt' => $rec['receipt_media_id'] !== null ? media_find((int) $rec['receipt_media_id']) : null,
        ]);
    }

    public static function update(array $p): void
    {
        csrf_check();
        [$user, $member] = self::member();
        $rec = self::own((int) $p['id'], $member);
        [$d, $errors, $warnings] = payment_validate($_POST, (int) $member['id'], false, $rec);
        if ($errors || ($warnings && input('confirm') !== '1')) {
            remember_input($_POST, $errors);
            $_SESSION['pay_warnings'] = $errors ? [] : $warnings;
            redirect('/account/payments/' . (int) $rec['id'] . '/edit');
        }
        $file = $_FILES['receipt'] ?? null;
        $error = payment_member_update($user, (int) $rec['id'], (int) $member['id'], $d, is_array($file) ? $file : null);
        if ($error !== null) {
            remember_input($_POST, ['receipt' => $error]);
            redirect('/account/payments/' . (int) $rec['id'] . '/edit');
        }
        flash($rec['status'] === 'needs_info' ? 'Thank you: your answer was sent and the payment is waiting for confirmation again.' : 'Payment report updated.');
        redirect('/account/payments/' . (int) $rec['id']);
    }

    public static function withdraw(array $p): void
    {
        csrf_check();
        [$user, $member] = self::member();
        $rec = self::own((int) $p['id'], $member);
        $ok = payment_withdraw($user, (int) $rec['id'], (int) $member['id']);
        flash($ok ? 'Payment report withdrawn.' : 'This payment can no longer be withdrawn.', $ok ? 'success' : 'error');
        redirect('/account/payments');
    }

    /** Printable receipt (approved records only). */
    public static function receipt(array $p): void
    {
        [, $member] = self::member();
        $rec = self::own((int) $p['id'], $member);
        if ($rec['status'] !== 'approved' || $rec['entry_type'] !== 'payment') {
            throw new HttpException(404);
        }
        render('payments/receipt', receipt_view_data($rec) + ['back' => '/account/payments/' . (int) $rec['id'], 'extra_css' => ['assets/css/payments.css']], 'print');
    }

    /** Public check of a receipt's QR code: valid or not, organisation, amount, date, initials only. */
    public static function verify(array $p): void
    {
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        $limited = rate_limited('rcpverify:' . client_ip(), 60, 600);
        if (!$limited) {
            rate_hit('rcpverify:' . client_ip());
        }
        $row = $limited ? null : payment_verify((string) $p['code']);
        $valid = $row !== null && $row['status'] === 'approved' && $row['reversal_id'] === null;
        render('payments/verify', ['title' => 'Receipt check', 'row' => $row, 'valid' => $valid, 'limited' => $limited, 'noindex' => true], 'site', $limited ? 429 : ($row === null ? 404 : 200));
    }

    private static function takeWarnings(): array
    {
        $w = $_SESSION['pay_warnings'] ?? [];
        unset($_SESSION['pay_warnings']);
        return is_array($w) ? array_values(array_map('strval', $w)) : [];
    }

    /** Store an uploaded receipt while the form is shown again; returns hidden fields that refer to it. */
    private static function holdUpload(array $user, ?array $file, array &$errors): array
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [];
        }
        try {
            $id = payment_store_receipt($file, (int) $user['id']);
        } catch (\Core\UploadException $e) {
            $errors['receipt'] = $e->getMessage();
            return [];
        }
        return $id === null ? [] : ['held_receipt' => $id . '.' . app_sign('held-receipt|' . $id . '|' . $user['id'])];
    }

    /** The held receipt (signed for this user, still unused), or null. */
    private static function heldReceipt(int $userId, array $in): ?array
    {
        $v = is_string($in['held_receipt'] ?? null) ? $in['held_receipt'] : '';
        if (!preg_match('/^(\d+)\.([a-f0-9]{64})$/', $v, $m) || !hash_equals(app_sign('held-receipt|' . $m[1] . '|' . $userId), $m[2])) {
            return null;
        }
        $media = media_find((int) $m[1]);
        if ($media === null || (string) $media['folder'] !== 'receipts' || (int) $media['uploaded_by'] !== $userId || q_val('SELECT 1 FROM payments WHERE receipt_media_id = ? LIMIT 1', [(int) $m[1]]) !== null) {
            return null;
        }
        return $media + ['token' => $v];
    }
}
