<?php
declare(strict_types=1);

namespace Admin;

use Core\HttpException;

/**
 * D2 admin side (/admin/payments, permission `payments`): pending queue,
 * review screen, all payments, recording payments, items, member payment
 * tabs, reports and the super admin's payment settings. Every POST checks
 * CSRF, writes an audit entry and redirects (303).
 */
final class Payments
{
    private const PER_PAGE = 50;

    private static function admin(): array
    {
        return require_permission('payments');
    }

    private static function page(string $view, string $title, array $data, int $status = 200): void
    {
        render('admin/payments/' . $view, $data + ['title' => $title, 'extra_css' => ['assets/css/payments.css']], 'admin', $status);
    }

    private static function find(int $id): array
    {
        $p = payment_find($id);
        if ($p === null) {
            throw new HttpException(404);
        }
        return $p;
    }

    /** Duplicate warnings for a page of records (one query for all their members). @return array<int,list<array>> */
    private static function duplicates(array $rows): array
    {
        $members = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int) ($r['member_id'] ?? 0), $rows))));
        if ($members === []) {
            return [];
        }
        $cands = q_all(
            "SELECT id, member_id, reference, amount_minor, paid_on, item_id, other_name FROM payments
              WHERE member_id IN (" . db_placeholders(count($members)) . ") AND entry_type = 'payment' AND status IN ('pending','needs_info','approved')",
            $members
        );
        $byMember = [];
        foreach ($cands as $c) {
            $byMember[(int) $c['member_id']][] = $c;
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = payment_duplicates_in($byMember[(int) ($r['member_id'] ?? 0)] ?? [], $r);
        }
        return $out;
    }

    private static function rowsSql(): string
    {
        return 'SELECT p.*, i.name AS item_name, m.first_name, m.last_name, m.member_number, m.user_id AS member_user_id,
                       c.amount_minor AS charge_amount, c.paid_minor AS charge_paid, c.waived AS charge_waived, md.kind AS receipt_kind, md.original_name AS receipt_name,
                       rv.display_name AS reviewer_name, rb.display_name AS recorder_name, r.id AS reversal_id
                  FROM payments p LEFT JOIN payment_items i ON i.id = p.item_id LEFT JOIN members m ON m.id = p.member_id
                  LEFT JOIN payment_charges c ON c.id = p.charge_id LEFT JOIN media md ON md.id = p.receipt_media_id
                  LEFT JOIN users rv ON rv.id = p.reviewed_by LEFT JOIN users rb ON rb.id = p.recorded_by
                  LEFT JOIN payments r ON r.reverses_id = p.id';
    }

    // ---- Pending queue ------------------------------------------------------------------------

    public static function index(array $p): void
    {
        $user = self::admin();
        [$afterAt, $afterId] = self::cursor(query_param('after'));
        $params = [];
        $where = "p.status = 'pending'";
        if ($afterAt !== null) {
            $where .= ' AND (p.created_at > ? OR (p.created_at = ? AND p.id > ?))';
            array_push($params, $afterAt, $afterAt, $afterId);
        }
        $rows = q_all(self::rowsSql() . " WHERE $where ORDER BY p.created_at, p.id LIMIT " . (self::PER_PAGE + 1), $params);
        $more = count($rows) > self::PER_PAGE;
        $rows = array_slice($rows, 0, self::PER_PAGE);
        $last = end($rows);
        self::page('index', 'Payments', [
            'user' => $user, 'rows' => $rows, 'dups' => self::duplicates($rows), 'pending' => payment_pending_count(),
            'needs_info' => (int) q_val("SELECT COUNT(*) FROM payments WHERE status = 'needs_info'"),
            'next' => $more && $last ? $last['created_at'] . '|' . $last['id'] : null, 'first_page' => $afterAt === null,
            'emails_on' => (int) q_val('SELECT payment_emails FROM users WHERE id = ?', [(int) $user['id']]) === 1,
            'settings' => payment_settings(),
        ]);
    }

    /** Keyset cursor "Y-m-d H:i:s|id". @return array{0:?string,1:int} */
    private static function cursor(string $v): array
    {
        return preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d+)$/', $v, $m) ? [$m[1], (int) $m[2]] : [null, 0];
    }

    public static function bulkApprove(array $p): void
    {
        csrf_check();
        $user = self::admin();
        $ids = array_values(array_unique(array_filter(array_map('intval', input_list('ids')))));
        if ($ids === []) {
            flash('Tick the payments to approve first.', 'error');
            redirect('/admin/payments');
        }
        $rows = q_all(self::rowsSql() . ' WHERE p.id IN (' . db_placeholders(count($ids)) . ") AND p.status = 'pending'", $ids);
        $dups = self::duplicates($rows);
        $approved = 0;
        $skipped = 0;
        foreach ($rows as $r) {
            $over = $r['charge_id'] !== null && (int) $r['amount_minor'] > max(0, (int) $r['charge_amount'] - (int) $r['charge_paid']);
            if (($dups[(int) $r['id']] ?? []) !== [] || $over || payment_approve((int) $r['id'], $user) !== null) {
                $skipped++;
                continue;
            }
            audit('approve', 'payment', (int) $r['id'], money((int) $r['amount_minor'], (string) $r['currency']) . ' · ' . payment_member_label($r) . ' (bulk)');
            $approved++;
        }
        flash(pluralise($approved, 'payment') . ' approved' . ($skipped ? '; ' . $skipped . ' left for a closer look (warnings, separation of duties or already reviewed)' : '') . '.', $approved > 0 ? 'success' : 'error');
        redirect('/admin/payments');
    }

    public static function preferences(array $p): void
    {
        csrf_check();
        $user = self::admin();
        $on = input('payment_emails') === '1';
        q('UPDATE users SET payment_emails = ? WHERE id = ?', [$on ? 1 : 0, (int) $user['id']]);
        audit('update', 'preferences', (int) $user['id'], 'payment report emails ' . ($on ? 'on' : 'off'));
        flash($on ? 'You will get an email about new payment reports (at most one every 15 minutes).' : 'Payment report emails are off for you.');
        redirect('/admin/payments');
    }

    // ---- Review --------------------------------------------------------------------------------

    public static function show(array $p): void
    {
        $user = self::admin();
        $rec = q_one(self::rowsSql() . ' WHERE p.id = ?', [(int) $p['id']]);
        if ($rec === null) {
            throw new HttpException(404);
        }
        $prev = $next = null;
        if ($rec['status'] === 'pending') {
            $prev = q_val("SELECT id FROM payments WHERE status = 'pending' AND (created_at < ? OR (created_at = ? AND id < ?)) ORDER BY created_at DESC, id DESC LIMIT 1", [$rec['created_at'], $rec['created_at'], $rec['id']]);
            $next = q_val("SELECT id FROM payments WHERE status = 'pending' AND (created_at > ? OR (created_at = ? AND id > ?)) ORDER BY created_at, id LIMIT 1", [$rec['created_at'], $rec['created_at'], $rec['id']]);
        }
        $dups = self::duplicates([$rec])[(int) $rec['id']] ?? [];
        self::page('show', 'Payment #' . (int) $rec['id'], [
            'user' => $user, 'p' => $rec, 'dups' => $dups, 'timeline' => payment_timeline((int) $rec['id'], false),
            'receipt' => $rec['receipt_media_id'] !== null ? media_find((int) $rec['receipt_media_id']) : null,
            'prev' => $prev !== null ? (int) $prev : null, 'next' => $next !== null ? (int) $next : null,
            'separation' => payment_separation_problem($rec, $user), 'reversal_of' => $rec['reverses_id'] !== null ? (int) $rec['reverses_id'] : null,
        ]);
    }

    /** After an action on the review screen: the next pending report, or the queue. */
    private static function afterReview(int $id): never
    {
        $rec = payment_find($id);
        $next = $rec !== null ? q_val("SELECT id FROM payments WHERE status = 'pending' AND id <> ? ORDER BY created_at, id LIMIT 1", [$id]) : null;
        redirect(input('return') === 'queue' || $next === null ? '/admin/payments' : '/admin/payments/' . (int) $next);
    }

    public static function approve(array $p): void
    {
        csrf_check();
        $user = self::admin();
        $rec = self::find((int) $p['id']);
        $error = payment_approve((int) $rec['id'], $user, mb_substr(input('note'), 0, 1000));
        if ($error !== null) {
            flash($error, 'error');
            redirect('/admin/payments/' . (int) $rec['id']);
        }
        audit('approve', 'payment', (int) $rec['id'], money((int) $rec['amount_minor'], (string) $rec['currency']) . ' · ' . payment_member_label($rec) . ' · ' . payment_what($rec));
        flash('Payment approved; receipt ' . q_val('SELECT receipt_number FROM payments WHERE id = ?', [(int) $rec['id']]) . ' sent to the member.');
        self::afterReview((int) $rec['id']);
    }

    public static function reject(array $p): void
    {
        csrf_check();
        $user = self::admin();
        $rec = self::find((int) $p['id']);
        $reason = trim(input('reason')) !== '' ? input('reason') : input('preset');
        $error = payment_review((int) $rec['id'], $user, 'reject', $reason);
        if ($error !== null) {
            flash($error, 'error');
            redirect('/admin/payments/' . (int) $rec['id']);
        }
        audit('reject', 'payment', (int) $rec['id'], payment_member_label($rec) . ': ' . $reason);
        flash('Payment rejected; the member was told why.');
        self::afterReview((int) $rec['id']);
    }

    public static function info(array $p): void
    {
        csrf_check();
        $user = self::admin();
        $rec = self::find((int) $p['id']);
        $error = payment_review((int) $rec['id'], $user, 'info', input('question'));
        if ($error !== null) {
            flash($error, 'error');
            redirect('/admin/payments/' . (int) $rec['id']);
        }
        audit('ask_info', 'payment', (int) $rec['id'], payment_member_label($rec));
        flash('Question sent: the member answers by editing the payment.');
        self::afterReview((int) $rec['id']);
    }

    public static function reverse(array $p): void
    {
        csrf_check();
        $user = self::admin();
        $rec = self::find((int) $p['id']);
        $reason = trim(input('reason')) !== '' ? input('reason') : input('preset');
        [$rid, $error] = payment_reverse((int) $rec['id'], $user, $reason);
        if ($error !== null) {
            flash($error, 'error');
            redirect('/admin/payments/' . (int) $rec['id']);
        }
        audit('reverse', 'payment', (int) $rec['id'], money((int) $rec['amount_minor'], (string) $rec['currency']) . ' · ' . payment_member_label($rec) . ': ' . $reason . ' (reversal #' . $rid . ')');
        flash('Payment reversed with entry #' . $rid . '. Record a new payment if the money was received another way.');
        redirect('/admin/payments/' . (int) $rec['id']);
    }

    public static function receipt(array $p): void
    {
        self::admin();
        $rec = self::find((int) $p['id']);
        if ($rec['status'] !== 'approved' || $rec['entry_type'] !== 'payment') {
            throw new HttpException(404);
        }
        render('payments/receipt', receipt_view_data($rec) + ['back' => '/admin/payments/' . (int) $rec['id'], 'extra_css' => ['assets/css/payments.css']], 'print');
    }

    // ---- All payments ------------------------------------------------------------------------------

    /** Filters from the query string, validated against whitelists. */
    private static function filters(): array
    {
        $status = query_param('status');
        $method = query_param('method');
        $source = query_param('source');
        $item = query_param('item');
        $date = static fn (string $v): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
        return [
            'status' => in_array($status, [...array_keys(PAYMENT_STATUSES), 'reversed', 'reversal'], true) ? $status : '',
            'method' => isset(PAYMENT_METHODS[$method]) ? $method : '',
            'source' => in_array($source, ['reported', 'recorded'], true) ? $source : '',
            'item' => $item === 'other' || ctype_digit($item) ? $item : '',
            'from' => $date(query_param('from')), 'to' => $date(query_param('to')),
            'member' => ctype_digit(query_param('member')) ? query_param('member') : '',
            'q' => mb_substr(query_param('q'), 0, 100),
        ];
    }

    /** @return array{0:string,1:list<mixed>} */
    private static function filterSql(array $f): array
    {
        $w = ['1 = 1'];
        $params = [];
        if ($f['status'] === 'reversed') {
            $w[] = "p.status = 'approved' AND r.id IS NOT NULL";
        } elseif ($f['status'] === 'reversal') {
            $w[] = "p.entry_type = 'reversal'";
        } elseif ($f['status'] !== '') {
            $w[] = 'p.status = ?';
            $params[] = $f['status'];
            if ($f['status'] === 'approved') {
                $w[] = "p.entry_type = 'payment'";
            }
        }
        foreach (['method' => 'p.method'] as $k => $col) {
            if ($f[$k] !== '') {
                $w[] = "$col = ?";
                $params[] = $f[$k];
            }
        }
        if ($f['source'] !== '') {
            $w[] = $f['source'] === 'recorded' ? 'p.recorded_by IS NOT NULL' : 'p.recorded_by IS NULL';
        }
        if ($f['item'] === 'other') {
            $w[] = 'p.item_id IS NULL';
        } elseif ($f['item'] !== '') {
            $w[] = 'p.item_id = ?';
            $params[] = (int) $f['item'];
        }
        if ($f['from'] !== '') {
            $w[] = 'p.paid_on >= ?';
            $params[] = $f['from'];
        }
        if ($f['to'] !== '') {
            $w[] = 'p.paid_on <= ?';
            $params[] = $f['to'];
        }
        if ($f['member'] !== '') {
            $w[] = 'p.member_id = ?';
            $params[] = (int) $f['member'];
        }
        if ($f['q'] !== '') {
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $w[] = "(p.reference LIKE ? OR p.receipt_number LIKE ? OR m.member_number LIKE ? OR CONCAT(m.first_name, ' ', m.last_name) LIKE ? OR p.former_label LIKE ?)";
            array_push($params, $like, $like, $like, $like, $like);
        }
        return [implode(' AND ', $w), $params];
    }

    public static function all(array $p): void
    {
        self::admin();
        $f = self::filters();
        [$where, $params] = self::filterSql($f);
        $before = (int) query_param('before');
        if ($before > 0) {
            $where .= ' AND p.id < ?';
            $params[] = $before;
        }
        $rows = q_all(self::rowsSql() . " WHERE $where ORDER BY p.id DESC LIMIT " . (self::PER_PAGE + 1), $params);
        $more = count($rows) > self::PER_PAGE;
        $rows = array_slice($rows, 0, self::PER_PAGE);
        self::page('all', 'All payments', [
            'rows' => $rows, 'filters' => $f, 'items' => q_all('SELECT id, name FROM payment_items ORDER BY id DESC'),
            'next' => $more ? (int) end($rows)['id'] : null, 'paged' => $before > 0,
        ]);
    }

    public static function allCsv(array $p): void
    {
        self::admin();
        $f = self::filters();
        [$where, $params] = self::filterSql($f);
        audit('export', 'payments', null, 'all payments CSV' . ($f['status'] !== '' ? ' (' . $f['status'] . ')' : ''));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="payments-' . gmdate('Y-m-d') . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['receipt_number', 'member_number', 'name', 'item', 'amount', 'currency', 'date_paid', 'method', 'reference', 'status', 'reviewer', 'reviewed_at', 'record_id', 'entry'], ',', '"', '');
        $last = PHP_INT_MAX;
        do {
            $rows = q_all(self::rowsSql() . " WHERE $where AND p.id < ? ORDER BY p.id DESC LIMIT 1000", [...$params, $last]);
            foreach ($rows as $r) {
                $last = (int) $r['id'];
                $status = $r['entry_type'] === 'reversal' ? 'reversal' : ($r['reversal_id'] !== null ? 'reversed' : $r['status']);
                fputcsv($out, [
                    (string) ($r['receipt_number'] ?? ''), csv_safe((string) ($r['member_number'] ?? '')), csv_safe(payment_member_label($r)), csv_safe(payment_what($r)),
                    money_input((int) $r['amount_minor'], (string) $r['currency']), (string) $r['currency'], (string) $r['paid_on'], PAYMENT_METHODS[$r['method']] ?? $r['method'],
                    csv_safe((string) $r['reference']), $status, csv_safe((string) ($r['reviewer_name'] ?? '')), (string) ($r['reviewed_at'] ?? ''), (string) $r['id'], (string) $r['entry_type'],
                ], ',', '"', '');
            }
        } while (count($rows) === 1000);
        fclose($out);
    }

    // ---- Record a payment ------------------------------------------------------------------------------

    public static function recordForm(array $p): void
    {
        self::admin();
        $member = null;
        $mid = (int) query_param('member');
        if ($mid > 0) {
            $member = q_one("SELECT m.*, u.email, u.status FROM members m JOIN users u ON u.id = m.user_id WHERE m.id = ? AND u.role = 'member'", [$mid]);
        }
        $q = mb_substr(query_param('q'), 0, 100);
        $matches = [];
        if ($member === null && $q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $matches = q_all("SELECT m.id, m.first_name, m.last_name, m.member_number, u.email, u.status FROM members m JOIN users u ON u.id = m.user_id
                               WHERE u.role = 'member' AND (m.member_number LIKE ? OR CONCAT(m.first_name, ' ', m.last_name) LIKE ? OR u.email LIKE ?) ORDER BY m.first_name, m.last_name LIMIT 30", [$like, $like, $like]);
        }
        $old = take_old_input();
        $warnings = $_SESSION['pay_warnings'] ?? [];
        unset($_SESSION['pay_warnings']);
        $choices = $member !== null ? payment_member_choices((int) $member['id']) : ['charges' => [], 'optional' => []];
        if ($member !== null) {
            // Admins may also record against any open item without a charge for this member.
            $have = array_map(static fn (array $c): int => (int) $c['item_id'], payment_member_charges((int) $member['id']));
            $choices['optional'] = array_values(array_filter(q_all("SELECT * FROM payment_items WHERE status = 'open' ORDER BY name"), static fn (array $i): bool => !in_array((int) $i['id'], $have, true)));
        }
        $values = $old['values'] ?: ['target' => '', 'amount' => '', 'paid_on' => payment_today(), 'method' => 'cash', 'reference' => '', 'payer_name' => '', 'note' => '', 'other_name' => ''];
        if ($old['values'] === [] && ($c = (int) query_param('charge')) > 0) {
            $values['target'] = 'c' . $c;
        }
        self::page('record', 'Record a payment', ['member' => $member, 'q' => $q, 'matches' => $matches, 'values' => $values, 'errors' => $old['errors'], 'warnings' => is_array($warnings) ? $warnings : [], 'choices' => $choices, 'settings' => payment_settings()]);
    }

    public static function record(array $p): void
    {
        csrf_check();
        $user = self::admin();
        $mid = input_int('member_id');
        $member = q_one("SELECT m.* FROM members m JOIN users u ON u.id = m.user_id WHERE m.id = ? AND u.role = 'member'", [$mid]);
        if ($member === null) {
            flash('Choose the member first.', 'error');
            redirect('/admin/payments/record');
        }
        [$d, $errors, $warnings] = payment_validate($_POST, $mid, true);
        if ($errors || ($warnings && input('confirm') !== '1')) {
            remember_input($_POST, $errors);
            $_SESSION['pay_warnings'] = $errors ? [] : $warnings;
            redirect('/admin/payments/record?member=' . $mid);
        }
        $file = $_FILES['receipt'] ?? null;
        [$id, $error] = payment_record($user, $d, is_array($file) ? $file : null);
        if ($id === null) {
            remember_input($_POST, ['receipt' => (string) $error]);
            redirect('/admin/payments/record?member=' . $mid);
        }
        audit('record', 'payment', (int) $id, money((int) $d['amount_minor']) . ' for ' . member_full_name($member) . ($error !== null ? ' (waiting: ' . $error . ')' : ''));
        $status = (string) q_val('SELECT status FROM payments WHERE id = ?', [$id]);
        flash($status === 'approved' ? 'Payment recorded and confirmed; the member has a receipt.' : 'Payment recorded. Separation of duties is on: another admin must approve it.');
        redirect('/admin/payments/member/' . $mid);
    }

    // ---- Member payments tab ------------------------------------------------------------------------------

    public static function member(array $p): void
    {
        self::admin();
        $m = q_one("SELECT m.*, u.email, u.status FROM members m JOIN users u ON u.id = m.user_id WHERE m.id = ? AND u.role = 'member'", [(int) $p['id']]);
        if ($m === null) {
            throw new HttpException(404);
        }
        $charges = payment_member_charges((int) $m['id']);
        $chargeEvents = [];
        if ($charges) {
            foreach (q_all('SELECT e.*, u.display_name FROM payment_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.charge_id IN (' . db_placeholders(count($charges)) . ') ORDER BY e.id', array_map(static fn (array $c): int => (int) $c['id'], $charges)) as $e) {
                $chargeEvents[(int) $e['charge_id']][] = $e;
            }
        }
        $records = q_all(self::rowsSql() . ' WHERE p.member_id = ? ORDER BY p.id DESC LIMIT 200', [(int) $m['id']]);
        $have = array_map(static fn (array $c): int => (int) $c['item_id'], $charges);
        $addable = array_values(array_filter(q_all("SELECT id, name, amount_minor FROM payment_items WHERE status <> 'closed' AND amount_minor IS NOT NULL AND applies_to <> 'nobody' ORDER BY name"), static fn (array $i): bool => !in_array((int) $i['id'], $have, true)));
        self::page('member', member_full_name($m) . ': payments', ['m' => $m, 'charges' => $charges, 'charge_events' => $chargeEvents, 'records' => $records, 'totals' => payment_member_totals((int) $m['id']), 'addable' => $addable]);
    }

    /** Waive, un-waive or change one member's charge (reason required); or add a charge for an item. */
    public static function charge(array $p): void
    {
        csrf_check();
        $user = self::admin();
        $c = q_one('SELECT c.*, i.name AS item_name FROM payment_charges c JOIN payment_items i ON i.id = c.item_id WHERE c.id = ?', [(int) $p['id']]);
        if ($c === null) {
            throw new HttpException(404);
        }
        $action = input('action');
        $reason = input('reason');
        $error = match ($action) {
            'waive' => payment_charge_waive((int) $c['id'], $user, true, $reason),
            'unwaive' => payment_charge_waive((int) $c['id'], $user, false, $reason),
            'amount' => payment_charge_set_amount((int) $c['id'], $user, input('amount'), $reason),
            default => 'Choose what to change.',
        };
        if ($error === null) {
            audit($action === 'amount' ? 'change_amount' : $action, 'payment_charge', (int) $c['id'], $c['item_name'] . ($action === 'amount' ? ' → ' . input('amount') : '') . ': ' . mb_substr(trim($reason), 0, 120));
        }
        flash($error ?? ($action === 'amount' ? 'Amount changed.' : ($action === 'waive' ? 'Charge waived.' : 'Charge no longer waived.')), $error === null ? 'success' : 'error');
        redirect('/admin/payments/member/' . (int) ($c['member_id'] ?? 0) . '#charge-' . (int) $c['id']);
    }

    public static function addCharge(array $p): void
    {
        csrf_check();
        self::admin();
        $mid = (int) $p['id'];
        $item = payment_item(input_int('item_id'));
        if ($item === null || q_val("SELECT 1 FROM members m JOIN users u ON u.id = m.user_id WHERE m.id = ? AND u.role = 'member'", [$mid]) === null) {
            throw new HttpException(404);
        }
        $n = payment_item_add_members((int) $item['id'], [$mid]);
        audit('add_charge', 'payment_item', (int) $item['id'], 'member #' . $mid . ($n ? '' : ' (already had one)'));
        flash($n ? 'Charge added: ' . $item['name'] . '.' : 'Nothing added: the member already has this item, or it is closed.', $n ? 'success' : 'error');
        redirect('/admin/payments/member/' . $mid);
    }

    // ---- Items ----------------------------------------------------------------------------------------------

    public static function items(array $p): void
    {
        self::admin();
        $items = q_all("SELECT * FROM payment_items ORDER BY FIELD(status, 'open', 'draft', 'closed'), due_date IS NULL, due_date DESC, id DESC");
        self::page('items', 'Payment items', ['items' => $items, 'counts' => payment_item_status_counts(array_map(static fn (array $i): int => (int) $i['id'], $items))]);
    }

    private static function itemForm(?array $item, array $values, array $errors, int $status = 200): void
    {
        self::page('item_form', $item === null ? 'New payment item' : 'Edit ' . $item['name'], [
            'item' => $item, 'values' => $values, 'errors' => $errors, 'types' => member_settings()['types'], 'settings' => payment_settings(),
        ], $status);
    }

    private static function itemValues(array $item): array
    {
        $loc = geo_filter_decode((string) $item['applies_value']);
        return [
            'name' => $item['name'], 'description' => (string) $item['description'], 'item_kind' => $item['item_kind'],
            'amount_mode' => $item['amount_minor'] === null ? 'any' : 'fixed', 'amount' => money_input($item['amount_minor'] !== null ? (int) $item['amount_minor'] : null),
            'due_date' => (string) ($item['due_date'] ?? ''), 'allow_part' => (string) $item['allow_part'], 'applies_to' => $item['applies_to'],
            'membership_type' => $item['applies_to'] === 'membership_type' ? $item['applies_value'] : '',
            'loc_slot' => $loc[0] ?? 'residence', 'loc_country' => $loc[1] ?? '', 'loc_state' => $loc[2] ?? '', 'loc_area' => $loc[3] ?? '',
            'include_new_members' => (string) $item['include_new_members'], 'counts_as_dues' => (string) $item['counts_as_dues'], 'covers_until' => (string) ($item['covers_until'] ?? ''),
            'remind_enabled' => (string) $item['remind_enabled'], 'remind_before_days' => $item['remind_before_days'] !== null ? (string) $item['remind_before_days'] : '',
            'remind_after_days' => $item['remind_after_days'] !== null ? (string) $item['remind_after_days'] : '',
        ];
    }

    public static function createItem(array $p): void
    {
        self::admin();
        $old = take_old_input();
        self::itemForm(null, $old['values'] ?: ['item_kind' => 'dues', 'amount_mode' => 'fixed', 'applies_to' => 'everyone', 'allow_part' => '1', 'include_new_members' => '1', 'remind_enabled' => '1', 'loc_slot' => 'residence'], $old['errors']);
    }

    public static function storeItem(array $p): void
    {
        csrf_check();
        $user = self::admin();
        [$d, $errors] = payment_item_validate($_POST);
        if ($errors) {
            remember_input($_POST, $errors);
            redirect('/admin/payments/items/new');
        }
        $id = db_insert('payment_items', $d + ['created_by' => (int) $user['id']]);
        audit('create', 'payment_item', $id, $d['name']);
        flash('Item saved as a draft. Check it, then open it so members can pay.');
        redirect('/admin/payments/items/' . $id);
    }

    public static function editItem(array $p): void
    {
        self::admin();
        $item = payment_item((int) $p['id']) ?? throw new HttpException(404);
        $old = take_old_input();
        self::itemForm($item, $old['values'] ?: self::itemValues($item), $old['errors']);
    }

    public static function updateItem(array $p): void
    {
        csrf_check();
        self::admin();
        $item = payment_item((int) $p['id']) ?? throw new HttpException(404);
        [$d, $errors] = payment_item_validate($_POST, $item);
        if ($errors) {
            remember_input($_POST, $errors);
            redirect('/admin/payments/items/' . (int) $item['id'] . '/edit');
        }
        db_update('payment_items', (int) $item['id'], $d);
        audit('update', 'payment_item', (int) $item['id'], $d['name']);
        flash('Item saved.');
        redirect('/admin/payments/items/' . (int) $item['id']);
    }

    public static function itemStatus(array $p): void
    {
        csrf_check();
        self::admin();
        $status = input('status') === 'closed' ? 'closed' : 'open';
        $item = payment_item_set_status((int) $p['id'], $status);
        if ($item === null) {
            flash('Nothing changed.', 'error');
        } else {
            audit($status === 'open' ? 'open' : 'close', 'payment_item', (int) $p['id'], (string) $item['name']);
            $pending = (int) q_val('SELECT charges_pending FROM payment_items WHERE id = ?', [(int) $p['id']]) === 1;
            flash($status === 'open' ? ($pending ? 'Item opened. Charges are being created in batches of 500 (cron); the progress shows on this page.' : 'Item opened: members can now report payments for it.') : 'Item closed: members can no longer report payments for it.');
        }
        redirect('/admin/payments/items/' . (int) $p['id']);
    }

    public static function duplicateItem(array $p): void
    {
        csrf_check();
        $user = self::admin();
        $id = payment_item_duplicate((int) $p['id'], (int) $user['id']) ?? throw new HttpException(404);
        audit('duplicate', 'payment_item', $id, 'copy of item #' . (int) $p['id']);
        flash('Copy saved as a draft for the next period: check the name, amount and dates.');
        redirect('/admin/payments/items/' . $id . '/edit');
    }

    public static function deleteItem(array $p): void
    {
        csrf_check();
        self::admin();
        $item = payment_item((int) $p['id']) ?? throw new HttpException(404);
        if ($item['status'] !== 'draft' || q_val('SELECT 1 FROM payments WHERE item_id = ? LIMIT 1', [(int) $item['id']]) !== null) {
            flash('Only draft items without payments can be deleted. Close the item instead.', 'error');
            redirect('/admin/payments/items/' . (int) $item['id']);
        }
        q('DELETE FROM payment_items WHERE id = ?', [(int) $item['id']]);
        audit('delete', 'payment_item', (int) $item['id'], (string) $item['name']);
        flash('Draft item deleted.');
        redirect('/admin/payments/items');
    }

    private const ITEM_TABS = ['paid' => 'Paid', 'part' => 'Part-paid', 'unpaid' => 'Unpaid', 'waived' => 'Waived'];

    /** @return array{0:string,1:list<array>,2:?int} [tab, rows, next cursor] */
    private static function itemRows(int $itemId, int $limit): array
    {
        $tab = query_param('tab');
        $tab = isset(self::ITEM_TABS[$tab]) ? $tab : 'unpaid';
        $after = (int) query_param('after');
        $rows = q_all(
            'SELECT c.*, m.first_name, m.last_name, m.member_number, u.email FROM payment_charges c LEFT JOIN members m ON m.id = c.member_id LEFT JOIN users u ON u.id = m.user_id
              WHERE c.item_id = ? AND c.charge_status = ? AND c.id > ? ORDER BY c.id LIMIT ' . ($limit + 1),
            [$itemId, $tab, $after]
        );
        $next = count($rows) > $limit ? (int) $rows[$limit - 1]['id'] : null;
        return [$tab, array_slice($rows, 0, $limit), $next];
    }

    public static function item(array $p): void
    {
        self::admin();
        $item = payment_item((int) $p['id']) ?? throw new HttpException(404);
        [$tab, $rows, $next] = self::itemRows((int) $item['id'], 100);
        self::page('item', $item['name'], [
            'item' => $item, 'tab' => $tab, 'tabs' => self::ITEM_TABS, 'rows' => $rows, 'next' => $next,
            'counts' => payment_item_status_counts([(int) $item['id']])[(int) $item['id']], 'progress' => payment_item_progress($item),
            'remind' => payment_remind_count((int) $item['id']), 'q' => mb_substr(query_param('q'), 0, 100),
            'matches' => $item['applies_to'] === 'members' && query_param('q') !== '' ? self::memberSearch(query_param('q'), (int) $item['id']) : [],
            'payments' => (int) q_val("SELECT COUNT(*) FROM payments WHERE item_id = ? AND status = 'approved'", [(int) $item['id']]),
        ]);
    }

    private static function memberSearch(string $q, int $itemId): array
    {
        $like = '%' . addcslashes(mb_substr($q, 0, 100), '%_\\') . '%';
        return q_all("SELECT m.id, m.first_name, m.last_name, m.member_number FROM members m JOIN users u ON u.id = m.user_id
                       WHERE u.role = 'member' AND u.status = 'active' AND (m.member_number LIKE ? OR CONCAT(m.first_name, ' ', m.last_name) LIKE ?)
                         AND NOT EXISTS (SELECT 1 FROM payment_charges c WHERE c.item_id = ? AND c.member_id = m.id) ORDER BY m.first_name LIMIT 30", [$like, $like, $itemId]);
    }

    public static function itemCsv(array $p): void
    {
        self::admin();
        $item = payment_item((int) $p['id']) ?? throw new HttpException(404);
        [$tab] = self::itemRows((int) $item['id'], 1);
        audit('export', 'payment_item', (int) $item['id'], $tab . ' CSV');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="item-' . (int) $item['id'] . '-' . $tab . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['member_number', 'name', 'email', 'amount', 'paid', 'balance', 'status', 'waive_reason'], ',', '"', '');
        $after = 0;
        do {
            $rows = q_all('SELECT c.*, m.first_name, m.last_name, m.member_number, u.email FROM payment_charges c LEFT JOIN members m ON m.id = c.member_id LEFT JOIN users u ON u.id = m.user_id
                            WHERE c.item_id = ? AND c.charge_status = ? AND c.id > ? ORDER BY c.id LIMIT 1000', [(int) $item['id'], $tab, $after]);
            foreach ($rows as $r) {
                $after = (int) $r['id'];
                fputcsv($out, [csv_safe((string) ($r['member_number'] ?? '')), csv_safe($r['first_name'] !== null ? trim($r['first_name'] . ' ' . $r['last_name']) : (string) $r['former_label']), csv_safe((string) ($r['email'] ?? '')),
                    money_input((int) $r['amount_minor']), money_input((int) $r['paid_minor']), money_input(payment_charge_balance($r)), $r['charge_status'], csv_safe((string) $r['waive_reason'])], ',', '"', '');
            }
        } while (count($rows) === 1000);
        fclose($out);
    }

    public static function remind(array $p): void
    {
        csrf_check();
        self::admin();
        $item = payment_item((int) $p['id']) ?? throw new HttpException(404);
        $n = payment_remind_unpaid((int) $item['id']);
        audit('remind', 'payment_item', (int) $item['id'], $n . ' reminder(s) queued');
        flash($n > 0 ? pluralise($n, 'reminder') . ' queued; they go out with the email queue.' : 'No reminders queued (everyone has paid, was reminded today, or cannot be emailed).', $n > 0 ? 'success' : 'error');
        redirect('/admin/payments/items/' . (int) $item['id'] . '?tab=unpaid');
    }

    /** "Message unpaid": a C3 announcement to members who have not paid in full. */
    public static function message(array $p): void
    {
        csrf_check();
        $user = self::admin();
        $item = payment_item((int) $p['id']) ?? throw new HttpException(404);
        $ids = array_map(static fn (array $r): int => (int) $r['user_id'], payment_unpaid_rows((int) $item['id']));
        if ($ids === []) {
            flash('Everyone has paid: there is nobody to message.', 'error');
            redirect('/admin/payments/items/' . (int) $item['id']);
        }
        [$convId, $error] = msg_start($user, array_slice($ids, 0, 4999), input('body'), ['announcement' => true, 'title' => mb_substr(input('title') !== '' ? input('title') : $item['name'], 0, 80)]);
        if ($convId === null) {
            flash((string) $error, 'error');
            redirect('/admin/payments/items/' . (int) $item['id']);
        }
        audit('message', 'payment_item', (int) $item['id'], 'announcement to ' . count($ids) . ' unpaid member(s)');
        flash('Message sent to ' . pluralise(count($ids), 'member') . ' who have not paid in full.');
        redirect('/admin/messages/' . (int) $convId);
    }

    public static function addMembers(array $p): void
    {
        csrf_check();
        self::admin();
        $item = payment_item((int) $p['id']) ?? throw new HttpException(404);
        $n = payment_item_add_members((int) $item['id'], array_map('intval', input_list('member_ids')));
        audit('add_members', 'payment_item', (int) $item['id'], $n . ' member(s) added');
        flash($n > 0 ? pluralise($n, 'member') . ' added.' : 'Nobody added.', $n > 0 ? 'success' : 'error');
        redirect('/admin/payments/items/' . (int) $item['id']);
    }

    // ---- Reports -------------------------------------------------------------------------------------------

    private static function range(): array
    {
        $year = (new \DateTimeImmutable('now', site_tz()))->format('Y');
        $ok = static fn (string $v): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && \DateTimeImmutable::createFromFormat('!Y-m-d', $v) !== false;
        $from = $ok(query_param('from')) ? query_param('from') : "$year-01-01";
        $to = $ok(query_param('to')) ? query_param('to') : payment_today();
        return $from <= $to ? [$from, $to] : [$to, $from];
    }

    public static function reports(array $p): void
    {
        self::admin();
        [$from, $to] = self::range();
        self::page('reports', 'Payment reports', ['from' => $from, 'to' => $to, 'r' => payment_report($from, $to), 'ytd' => payment_report(substr(payment_today(), 0, 4) . '-01-01', payment_today())['total'], 'dash' => payment_dashboard()]);
    }

    public static function reportsCsv(array $p): void
    {
        self::admin();
        [$from, $to] = self::range();
        $r = payment_report($from, $to);
        audit('export', 'payments', null, "report CSV $from to $to");
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="payment-report-' . $from . '-' . $to . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['group', 'name', 'payments', 'total', 'currency'], ',', '"', '');
        foreach ($r['items'] as $i) {
            fputcsv($out, ['item', csv_safe($i['name']), (string) $i['n'], money_input((int) $i['total']), site_currency()], ',', '"', '');
        }
        foreach ($r['months'] as $m) {
            fputcsv($out, ['month', (string) $m['ym'], (string) $m['n'], money_input((int) $m['total']), site_currency()], ',', '"', '');
        }
        foreach ($r['methods'] as $m) {
            fputcsv($out, ['method', PAYMENT_METHODS[$m['method']] ?? (string) $m['method'], (string) $m['n'], money_input((int) $m['total']), site_currency()], ',', '"', '');
        }
        fputcsv($out, ['total', "$from to $to", '', money_input($r['total']), site_currency()], ',', '"', '');
        fclose($out);
    }

    // ---- Settings (super admin) ----------------------------------------------------------------------------

    public static function settings(array $p): void
    {
        require_permission('super_admin');
        $old = take_old_input();
        $s = payment_settings();
        $values = $old['values'] ?: [
            'currency' => $s['currency'], 'receipt_required' => $s['receipt_required'] ? '1' : '', 'separation' => $s['separation'] ? '1' : '',
            'notify_received' => $s['notify_received'] ? '1' : '', 'remind_before' => (string) $s['remind_before'], 'remind_after' => (string) $s['remind_after'],
            'reviewer_label' => $s['reviewer_label'], 'retention_years' => (string) $s['retention_years'], 'bank_details' => $s['bank_details'],
        ];
        render('admin/payment_settings', ['title' => 'Payment settings', 'values' => $values, 'errors' => $old['errors'], 'locked' => payments_exist(), 'extra_css' => ['assets/css/payments.css']], 'admin');
    }

    public static function saveSettings(array $p): void
    {
        csrf_check();
        require_permission('super_admin');
        $s = payment_settings();
        $errors = [];
        $currency = strtoupper(input('currency'));
        if (payments_exist()) {
            $currency = $s['currency']; // blocked once any payment exists
        } elseif (!isset(currency_table()[$currency])) {
            $errors['currency'] = 'Choose a currency.';
        }
        $num = static function (string $k, int $min, int $max) use (&$errors): int {
            $v = input($k);
            if (!ctype_digit($v) || (int) $v < $min || (int) $v > $max) {
                $errors[$k] = "Enter a whole number from $min to $max.";
                return $min;
            }
            return (int) $v;
        };
        $before = $num('remind_before', 0, 90);
        $after = $num('remind_after', 0, 180);
        $years = $num('retention_years', 1, 30);
        $label = trim(input('reviewer_label'));
        if ($label === '' || mb_strlen($label) > 40) {
            $errors['reviewer_label'] = 'Enter how admins appear to members (up to 40 characters), e.g. Treasurer.';
        }
        $bank = \Core\Sanitizer::clean(is_string($_POST['bank_details'] ?? null) ? mb_substr($_POST['bank_details'], 0, 5000) : '');
        if ($errors) {
            remember_input($_POST, $errors);
            redirect('/admin/settings/payments');
        }
        $new = [
            'currency' => $currency, 'receipt_required' => input('receipt_required') === '1', 'separation' => input('separation') === '1',
            'notify_received' => input('notify_received') === '1', 'remind_before' => $before, 'remind_after' => $after,
            'reviewer_label' => $label, 'retention_years' => $years, 'bank_details' => $bank,
        ];
        $changed = array_keys(array_filter($new, static fn ($v, string $k): bool => ($s[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
        setting_set('payments', $new);
        audit('update', 'settings', null, 'payment settings' . ($changed ? ': ' . implode(', ', $changed) : ''));
        flash('Payment settings saved.');
        redirect('/admin/settings/payments');
    }
}
