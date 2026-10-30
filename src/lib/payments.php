<?php
declare(strict_types=1);

use Core\Mail\Message;
use Core\UploadException;

/*
 * Addendum D2: dues and payment records (report-only, no online gateway).
 *
 * - Payment items say what people pay for; charges say what each member is
 *   expected to pay for an item (amount, paid so far, waived).
 * - Payment records are the ledger: members report payments (pending), admins
 *   approve, reject or ask for more information, or record payments
 *   themselves. Approved records are never edited or deleted: a reversal (a
 *   linked negative entry) corrects them.
 * - Counters (charge paid_minor/charge_status, item expected/collected/
 *   outstanding) change in the same transaction as the ledger;
 *   payments_reconcile() recomputes them and reports drift.
 * Lock order everywhere: payments row, then charge row, then item row.
 */

const PAYMENT_METHODS = ['bank_transfer' => 'Bank transfer', 'cash' => 'Cash', 'pos' => 'POS / card machine', 'mobile_money' => 'Mobile money', 'cheque' => 'Cheque', 'other' => 'Other'];
const PAYMENT_ITEM_KINDS = ['dues' => 'Dues', 'levy' => 'Levy', 'event_fee' => 'Event fee', 'donation' => 'Donation', 'other' => 'Other'];
const PAYMENT_STATUSES = ['pending' => 'Pending', 'needs_info' => 'Needs info', 'approved' => 'Approved', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn'];
const PAYMENT_APPLIES = ['everyone' => 'Everyone active', 'membership_type' => 'A membership type', 'location' => 'Members in a location', 'members' => 'Chosen members', 'nobody' => 'Nobody (optional: members choose it, e.g. donations)'];
const PAYMENT_RECEIPT_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
const PAYMENT_RECEIPT_MAX_BYTES = 5 * 1024 * 1024;
const PAYMENT_CHARGE_BATCH = 500;
/** Member reports a day (submit and edit together). */
const PAYMENT_REPORTS_PER_DAY = 20;
const PAYMENT_ADMIN_EMAIL_MINUTES = 15;
const PAYMENT_REJECT_PRESETS = [
    'We could not find this payment in our bank account.',
    'The amount does not match our records.',
    'The receipt is unreadable or missing.',
    'This payment was already recorded.',
];

/** Settings → Payments (super admin), with defaults. */
function payment_settings(): array
{
    $s = setting('payments');
    $s = is_array($s) ? $s : [];
    return [
        'currency' => is_string($s['currency'] ?? null) && preg_match('/^[A-Z]{3}$/', $s['currency']) ? $s['currency'] : 'NGN',
        'receipt_required' => (bool) ($s['receipt_required'] ?? true),
        'separation' => (bool) ($s['separation'] ?? false),
        'remind_before' => max(0, min(90, (int) ($s['remind_before'] ?? 7))),
        'remind_after' => max(0, min(180, (int) ($s['remind_after'] ?? 14))),
        'reviewer_label' => is_string($s['reviewer_label'] ?? null) && trim($s['reviewer_label']) !== '' ? mb_substr(trim($s['reviewer_label']), 0, 40) : 'Treasurer',
        'retention_years' => max(1, min(30, (int) ($s['retention_years'] ?? 7))),
        'bank_details' => (string) ($s['bank_details'] ?? ''),
        'notify_received' => (bool) ($s['notify_received'] ?? false),
    ];
}

/**
 * Run $fn in a transaction. Deadlocks (1213) and MariaDB's "record has
 * changed since last read" (1020, snapshot isolation) are retried a few
 * times: two admins approving at the same moment must both succeed.
 */
function payments_tx(callable $fn, int $tries = 4): mixed
{
    payment_settings(); // load settings before the transaction takes its snapshot
    for ($i = 1; ; $i++) {
        try {
            return db_tx($fn);
        } catch (PDOException $e) {
            if ($i >= $tries || !in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1020], true)) {
                throw $e;
            }
            usleep(random_int(2000, 20000) * $i);
        }
    }
}

/** Has any payment record been made (the currency can no longer change)? */
function payments_exist(): bool
{
    return q_val('SELECT 1 FROM payments LIMIT 1') !== null;
}

/** Pending reports waiting for review (one indexed COUNT, cached per request). */
function payment_pending_count(bool $refresh = false): int
{
    static $n = null;
    if ($refresh || $n === null) {
        $n = (int) q_val("SELECT COUNT(*) FROM payments WHERE status = 'pending'");
    }
    return $n;
}

/** Today's date in the site time zone. */
function payment_today(?DateTimeImmutable $now = null): string
{
    return ($now ?? new DateTimeImmutable('now', utc()))->setTimezone(site_tz())->format('Y-m-d');
}

// ---- Charges and counters ---------------------------------------------------------------------

function payment_charge_status(int $amount, int $paid, bool $waived): string
{
    return match (true) {
        $waived => 'waived',
        $paid >= $amount => 'paid',
        $paid > 0 => 'part',
        default => 'unpaid',
    };
}

/** What a charge adds to its item's counters: [expected, outstanding]. A waived charge expects only what was paid. */
function payment_charge_contrib(array $c): array
{
    $amount = (int) $c['amount_minor'];
    $paid = (int) $c['paid_minor'];
    if ((int) $c['waived'] === 1) {
        return [max(0, $paid), 0];
    }
    return [$amount, max(0, $amount - $paid)];
}

/** Balance still owed on a charge (0 when waived). */
function payment_charge_balance(array $c): int
{
    return (int) $c['waived'] === 1 ? 0 : max(0, (int) $c['amount_minor'] - (int) $c['paid_minor']);
}

/** Add deltas to an item's counters. */
function payment_item_bump(int $itemId, int $expected, int $collected, int $outstanding): void
{
    if ($expected !== 0 || $collected !== 0 || $outstanding !== 0) {
        q('UPDATE payment_items SET expected_minor = expected_minor + ?, collected_minor = collected_minor + ?, outstanding_minor = outstanding_minor + ? WHERE id = ?', [$expected, $collected, $outstanding, $itemId]);
    }
}

/** Save a changed charge (amount, paid, waived) and move the item counters by the difference. Caller holds the row lock. */
function payment_charge_save(array $before, array $after): array
{
    $after['charge_status'] = payment_charge_status((int) $after['amount_minor'], (int) $after['paid_minor'], (int) $after['waived'] === 1);
    q('UPDATE payment_charges SET amount_minor = ?, paid_minor = ?, waived = ?, waive_reason = ?, charge_status = ? WHERE id = ?', [
        (int) $after['amount_minor'], (int) $after['paid_minor'], (int) $after['waived'], mb_substr((string) $after['waive_reason'], 0, 255), $after['charge_status'], (int) $after['id'],
    ]);
    [$e0, $o0] = payment_charge_contrib($before);
    [$e1, $o1] = payment_charge_contrib($after);
    payment_item_bump((int) $after['item_id'], $e1 - $e0, 0, $o1 - $o0);
    return $after;
}

/** Create a charge (no-op when the member already has one for the item). Returns the id or null. */
function payment_charge_create(int $itemId, int $memberId, int $amount): ?int
{
    $st = q('INSERT IGNORE INTO payment_charges (item_id, member_id, amount_minor, charge_status) VALUES (?, ?, ?, ?)', [$itemId, $memberId, $amount, $amount <= 0 ? 'paid' : 'unpaid']);
    if ($st->rowCount() !== 1) {
        return null;
    }
    payment_item_bump($itemId, $amount, 0, max(0, $amount));
    return (int) db()->lastInsertId();
}

/** SQL (members m JOIN users u) for the members an item applies to. @return array{0:string,1:list<mixed>} */
function payment_item_audience_sql(array $item): array
{
    $base = "u.role = 'member' AND u.status = 'active'";
    return match ($item['applies_to']) {
        'everyone' => [$base, []],
        'membership_type' => [$base . ' AND m.membership_type = ?', [(string) $item['applies_value']]],
        'location' => ($f = geo_filter_decode((string) $item['applies_value'])) !== null
            ? (static function () use ($base, $f): array { [$c, $p] = geo_filter_sql($f); return [$base . ' AND ' . $c, $p]; })()
            : [$base . ' AND 1 = 0', []],
        default => [$base . ' AND 1 = 0', []],
    };
}

/** Does an item create charges automatically (everyone, a type, a location)? */
function payment_item_has_audience(array $item): bool
{
    return in_array($item['applies_to'], ['everyone', 'membership_type', 'location'], true) && $item['amount_minor'] !== null;
}

/**
 * Create the next batch of charges for an opening item (members with id >
 * charges_cursor). Returns [created, finished].
 * @return array{0:int,1:bool}
 */
function payment_charges_batch(int $itemId, int $limit = PAYMENT_CHARGE_BATCH): array
{
    return payments_tx(static function () use ($itemId, $limit): array {
        $item = q_one('SELECT * FROM payment_items WHERE id = ? FOR UPDATE', [$itemId]);
        if ($item === null || (int) $item['charges_pending'] !== 1) {
            return [0, true];
        }
        if ($item['status'] !== 'open' || !payment_item_has_audience($item)) {
            q('UPDATE payment_items SET charges_pending = 0 WHERE id = ?', [$itemId]);
            return [0, true];
        }
        [$cond, $params] = payment_item_audience_sql($item);
        $ids = array_map('intval', array_column(q_all('SELECT m.id FROM members m JOIN users u ON u.id = m.user_id WHERE ' . $cond . ' AND m.id > ? ORDER BY m.id LIMIT ' . max(1, $limit), [...$params, (int) $item['charges_cursor']]), 'id'));
        $created = 0;
        foreach ($ids as $mid) {
            if (payment_charge_create($itemId, $mid, (int) $item['amount_minor']) !== null) {
                $created++;
            }
        }
        $finished = count($ids) < $limit;
        q('UPDATE payment_items SET charges_cursor = ?, charges_pending = ? WHERE id = ?', [$ids ? max($ids) : (int) $item['charges_cursor'], $finished ? 0 : 1, $itemId]);
        return [$created, $finished];
    });
}

/** Members an item applies to and how many already have a charge (progress while batches run). */
function payment_item_progress(array $item): array
{
    $charges = (int) q_val('SELECT COUNT(*) FROM payment_charges WHERE item_id = ?', [(int) $item['id']]);
    if (!payment_item_has_audience($item)) {
        return ['charges' => $charges, 'audience' => $charges, 'pending' => false];
    }
    [$cond, $params] = payment_item_audience_sql($item);
    $audience = (int) q_val('SELECT COUNT(*) FROM members m JOIN users u ON u.id = m.user_id WHERE ' . $cond, $params);
    return ['charges' => $charges, 'audience' => $audience, 'pending' => (int) $item['charges_pending'] === 1];
}

/** Cron: create pending charges in batches of 500 (up to ~20 seconds per run). */
function cron_payment_charges(): string
{
    $start = microtime(true);
    $created = 0;
    foreach (array_map('intval', array_column(q_all('SELECT id FROM payment_items WHERE charges_pending = 1 ORDER BY id'), 'id')) as $id) {
        do {
            [$n, $done] = payment_charges_batch($id);
            $created += $n;
        } while (!$done && microtime(true) - $start < 20);
        if (microtime(true) - $start >= 20) {
            break;
        }
    }
    return $created . ' charge(s) created';
}

/** A member was verified (or re-activated): add charges for open items that apply to people who join later. */
function payment_charges_for_new_member(int $memberId): int
{
    $n = 0;
    foreach (q_all("SELECT * FROM payment_items WHERE status = 'open' AND include_new_members = 1 AND amount_minor IS NOT NULL AND applies_to IN ('everyone','membership_type','location')") as $item) {
        [$cond, $params] = payment_item_audience_sql($item);
        if (q_val('SELECT 1 FROM members m JOIN users u ON u.id = m.user_id WHERE ' . $cond . ' AND m.id = ?', [...$params, $memberId]) === null) {
            continue;
        }
        payments_tx(static function () use ($item, $memberId, &$n): void {
            q('SELECT id FROM payment_items WHERE id = ? FOR UPDATE', [(int) $item['id']]);
            if (payment_charge_create((int) $item['id'], $memberId, (int) $item['amount_minor']) !== null) {
                $n++;
            }
        });
    }
    return $n;
}

// ---- Items ---------------------------------------------------------------------------------------

function payment_item(int $id): ?array
{
    return q_one('SELECT * FROM payment_items WHERE id = ?', [$id]);
}

/**
 * Validate the item form. Amount and "applies to" can only change while the
 * item is a draft (charges exist afterwards: change single charges instead).
 * @return array{0:array,1:array<string,string>}
 */
function payment_item_validate(array $in, ?array $existing = null): array
{
    $errors = [];
    $locked = $existing !== null && $existing['status'] !== 'draft';
    $d = [
        'name' => trim((string) ($in['name'] ?? '')),
        'description' => trim((string) ($in['description'] ?? '')),
        'item_kind' => (string) ($in['item_kind'] ?? 'dues'),
        'due_date' => trim((string) ($in['due_date'] ?? '')),
        'allow_part' => ($in['allow_part'] ?? '') === '1' ? 1 : 0,
        'include_new_members' => ($in['include_new_members'] ?? '') === '1' ? 1 : 0,
        'counts_as_dues' => ($in['counts_as_dues'] ?? '') === '1' ? 1 : 0,
        'covers_until' => trim((string) ($in['covers_until'] ?? '')),
        'remind_enabled' => ($in['remind_enabled'] ?? '') === '1' ? 1 : 0,
        'remind_before_days' => trim((string) ($in['remind_before_days'] ?? '')),
        'remind_after_days' => trim((string) ($in['remind_after_days'] ?? '')),
    ];
    if ($d['name'] === '' || mb_strlen($d['name']) > 120) {
        $errors['name'] = 'Give the item a name (up to 120 characters), e.g. “2027 annual dues”.';
    }
    if (mb_strlen($d['description']) > 2000) {
        $errors['description'] = 'Keep the description under 2,000 characters.';
    }
    if (!isset(PAYMENT_ITEM_KINDS[$d['item_kind']])) {
        $errors['item_kind'] = 'Choose what kind of payment this is.';
    }
    foreach (['due_date' => 'due date', 'covers_until' => '“covers until” date'] as $k => $label) {
        if ($d[$k] !== '' && (DateTimeImmutable::createFromFormat('!Y-m-d', $d[$k]) === false || DateTimeImmutable::createFromFormat('!Y-m-d', $d[$k])->format('Y-m-d') !== $d[$k])) {
            $errors[$k] = 'Enter the ' . $label . ' as a date.';
        }
        $d[$k] = $d[$k] === '' ? null : $d[$k];
    }
    foreach (['remind_before_days' => 90, 'remind_after_days' => 180] as $k => $max) {
        if ($d[$k] === '') {
            $d[$k] = null;
        } elseif (!ctype_digit($d[$k]) || (int) $d[$k] > $max) {
            $errors[$k] = "Enter a number of days from 0 to $max, or leave empty for the default.";
        } else {
            $d[$k] = (int) $d[$k];
        }
    }
    if ($d['counts_as_dues'] === 0) {
        $d['covers_until'] = null;
    }
    if ($locked) {
        foreach (['amount_minor', 'applies_to', 'applies_value'] as $k) {
            $d[$k] = $existing[$k];
        }
    } else {
        $any = ($in['amount_mode'] ?? 'fixed') === 'any';
        $d['applies_to'] = (string) ($in['applies_to'] ?? 'everyone');
        $d['applies_value'] = '';
        if (!isset(PAYMENT_APPLIES[$d['applies_to']])) {
            $errors['applies_to'] = 'Choose who this applies to.';
        }
        if ($any) {
            $d['amount_minor'] = null;
            if ($d['applies_to'] !== 'nobody') {
                $errors['amount'] = '“Any amount” is for optional items (applies to nobody), such as donations.';
            }
        } else {
            $d['amount_minor'] = money_parse((string) ($in['amount'] ?? ''));
            if ($d['amount_minor'] === null || $d['amount_minor'] <= 0) {
                $errors['amount'] = 'Enter the amount, e.g. ' . money_input(3000) . '.';
            }
        }
        if ($d['applies_to'] === 'membership_type') {
            $d['applies_value'] = trim((string) ($in['membership_type'] ?? ''));
            if (!in_array($d['applies_value'], member_settings()['types'], true)) {
                $errors['membership_type'] = 'Choose a membership type.';
            }
        } elseif ($d['applies_to'] === 'location') {
            $cc = strtoupper(trim((string) ($in['loc_country'] ?? '')));
            $value = geo_filter_encode((string) ($in['loc_slot'] ?? 'residence'), $cc, trim((string) ($in['loc_state'] ?? '')), trim((string) ($in['loc_area'] ?? '')));
            if (geo_filter_decode($value) === null) {
                $errors['location'] = 'Choose a country (and optionally a state and area).';
            }
            $d['applies_value'] = $value;
        }
    }
    if ($d['applies_to'] === 'nobody' || $d['applies_to'] === 'members') {
        $d['include_new_members'] = 0;
    }
    return [$d, $errors];
}

/** Open an item: charges are created now for the first 500 members and by cron for the rest. */
function payment_item_set_status(int $itemId, string $status): ?array
{
    $item = payments_tx(static function () use ($itemId, $status): ?array {
        $item = q_one('SELECT * FROM payment_items WHERE id = ? FOR UPDATE', [$itemId]);
        if ($item === null || !in_array($status, ['open', 'closed'], true) || $item['status'] === $status) {
            return null;
        }
        $first = $status === 'open' && $item['opened_at'] === null;
        q('UPDATE payment_items SET status = ?, opened_at = COALESCE(opened_at, ?), closed_at = ?, charges_pending = ? WHERE id = ?', [
            $status, $status === 'open' ? gmdate('Y-m-d H:i:s') : null, $status === 'closed' ? gmdate('Y-m-d H:i:s') : null,
            $status === 'open' && payment_item_has_audience($item) && ($first || (int) $item['include_new_members'] === 1) ? 1 : 0, $itemId,
        ]);
        if ($status === 'open' && payment_item_has_audience($item) && (int) $item['include_new_members'] === 1 && !$first) {
            q('UPDATE payment_items SET charges_cursor = 0 WHERE id = ?', [$itemId]); // re-opened: catch up members who joined meanwhile
        }
        return $item;
    });
    if ($item !== null && $status === 'open') {
        payment_charges_batch($itemId);
    }
    return $item;
}

/** "Duplicate for next period": a draft copy with dates moved on a year. */
function payment_item_duplicate(int $itemId, int $userId): ?int
{
    $item = payment_item($itemId);
    if ($item === null) {
        return null;
    }
    $plusYear = static fn (?string $d): ?string => $d === null ? null : (new DateTimeImmutable($d))->modify('+1 year')->format('Y-m-d');
    $name = (string) preg_replace_callback('/\b(19|20)(\d{2})\b/', static fn (array $m): string => (string) ((int) ($m[1] . $m[2]) + 1), (string) $item['name'], 1);
    if ($name === $item['name']) {
        $name = mb_substr($item['name'] . ' (next period)', 0, 120);
    }
    return db_insert('payment_items', [
        'name' => $name, 'description' => $item['description'], 'item_kind' => $item['item_kind'], 'amount_minor' => $item['amount_minor'],
        'due_date' => $plusYear($item['due_date']), 'status' => 'draft', 'allow_part' => $item['allow_part'], 'applies_to' => $item['applies_to'],
        'applies_value' => $item['applies_value'], 'include_new_members' => $item['include_new_members'], 'counts_as_dues' => $item['counts_as_dues'],
        'covers_until' => $plusYear($item['covers_until']), 'remind_enabled' => $item['remind_enabled'], 'remind_before_days' => $item['remind_before_days'],
        'remind_after_days' => $item['remind_after_days'], 'created_by' => $userId,
    ]);
}

/** Charge counts per status for items: [item_id => [unpaid, part, paid, waived]] (one grouped query). */
function payment_item_status_counts(array $itemIds): array
{
    $out = [];
    if ($itemIds === []) {
        return $out;
    }
    foreach (q_all('SELECT item_id, charge_status, COUNT(*) AS n FROM payment_charges WHERE item_id IN (' . db_placeholders(count($itemIds)) . ') GROUP BY item_id, charge_status', array_values($itemIds)) as $r) {
        $out[(int) $r['item_id']][(string) $r['charge_status']] = (int) $r['n'];
    }
    foreach ($itemIds as $id) {
        $out[(int) $id] = ($out[(int) $id] ?? []) + ['unpaid' => 0, 'part' => 0, 'paid' => 0, 'waived' => 0];
    }
    return $out;
}

/** "Applies to" in words. */
function payment_item_applies_label(array $item): string
{
    return match ($item['applies_to']) {
        'membership_type' => 'Membership type: ' . $item['applies_value'],
        'location' => ($f = geo_filter_decode((string) $item['applies_value'])) !== null ? geo_filter_label($f) : 'A location',
        default => PAYMENT_APPLIES[$item['applies_to']] ?? '',
    };
}

// ---- Receipts (uploaded proof) -------------------------------------------------------------------

/**
 * Store an uploaded receipt (image or PDF, 5 MB) as private media in the
 * "receipts" folder: images are re-encoded (EXIF stripped) with WebP
 * thumbnails, PDFs are stored as they are. Null when no file was chosen.
 */
function payment_store_receipt(?array $file, int $userId, bool $isUpload = true): ?int
{
    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $u = $isUpload ? media_upload_from_files($file) : ['tmp' => (string) $file['tmp_name'], 'name' => (string) $file['name'], 'size' => (int) $file['size']];
    if ($u['size'] > PAYMENT_RECEIPT_MAX_BYTES) {
        throw new UploadException('Receipts can be up to 5 MB.');
    }
    $info = media_validate($u['tmp'], $u['name'], $u['size']);
    if (!in_array($info['mime'], PAYMENT_RECEIPT_MIMES, true)) {
        throw new UploadException('Upload a photo (JPG, PNG, WebP or GIF) or a PDF of the receipt.');
    }
    return media_store($u['tmp'], $u['name'], $u['size'], $userId, $isUpload, ['visibility' => 'private', 'folder' => 'receipts', 'alt' => 'Payment receipt', 'title' => 'Payment receipt']);
}

/** Who may open an uploaded receipt: the member whose payment it belongs to, and admins with the payments permission. */
function payment_receipt_can_view(int $mediaId, ?array $user): bool
{
    if ($user === null || $user['status'] !== 'active') {
        return false;
    }
    if (user_can($user, 'payments')) {
        return true;
    }
    return q_val('SELECT 1 FROM payments p JOIN members m ON m.id = p.member_id WHERE p.receipt_media_id = ? AND m.user_id = ? LIMIT 1', [$mediaId, (int) $user['id']]) !== null;
}

/** Delete a receipt file unless another record still uses it ("same transfer" records share one). */
function payment_receipt_release(?int $mediaId): void
{
    if ($mediaId === null || q_val('SELECT 1 FROM payments WHERE receipt_media_id = ? LIMIT 1', [$mediaId]) !== null) {
        return;
    }
    $m = media_find($mediaId);
    if ($m !== null && (string) $m['folder'] === 'receipts') {
        q('DELETE FROM media WHERE id = ?', [$mediaId]);
        media_delete_files($m);
        media_forget($mediaId);
    }
}

// ---- Records -----------------------------------------------------------------------------------

function payment_event(?int $paymentId, ?int $chargeId, ?int $actorId, string $role, string $type, string $note = ''): void
{
    q('INSERT INTO payment_events (payment_id, charge_id, actor_id, actor_role, event_type, note) VALUES (?, ?, ?, ?, ?, ?)', [$paymentId, $chargeId, $actorId, $role, $type, mb_substr($note, 0, 1000)]);
}

/** A record with its item name and member details. */
function payment_find(int $id): ?array
{
    return q_one(
        'SELECT p.*, i.name AS item_name, i.status AS item_status, i.allow_part, m.first_name, m.last_name, m.member_number, m.user_id AS member_user_id,
                r.id AS reversal_id, r.created_at AS reversed_at, r.review_note AS reversal_reason
           FROM payments p LEFT JOIN payment_items i ON i.id = p.item_id LEFT JOIN members m ON m.id = p.member_id
           LEFT JOIN payments r ON r.reverses_id = p.id WHERE p.id = ?',
        [$id]
    );
}

/** Name shown for a record's member ("Former member #HCA-26-0001" after deletion). */
function payment_member_label(array $p): string
{
    if (($p['first_name'] ?? null) !== null) {
        return trim($p['first_name'] . ' ' . $p['last_name']);
    }
    return (string) ($p['former_label'] ?? 'Former member');
}

/** What a record was for. */
function payment_what(array $p): string
{
    return $p['item_id'] !== null ? (string) ($p['item_name'] ?? 'Payment') : ($p['other_name'] !== '' ? 'Other: ' . $p['other_name'] : 'Other');
}

/** Charges of a member with item details (open items first). */
function payment_member_charges(int $memberId, bool $openOnly = false): array
{
    return q_all(
        'SELECT c.*, i.name AS item_name, i.due_date, i.status AS item_status, i.allow_part, i.item_kind
           FROM payment_charges c JOIN payment_items i ON i.id = c.item_id
          WHERE c.member_id = ?' . ($openOnly ? " AND i.status = 'open'" : '') . '
          ORDER BY (i.status = \'open\') DESC, i.due_date IS NULL, i.due_date, i.id',
        [$memberId]
    );
}

/** What a member can report a payment for: open charges with a balance, optional open items. */
function payment_member_choices(int $memberId, ?array $current = null): array
{
    $charges = array_values(array_filter(payment_member_charges($memberId, true), static fn (array $c): bool => payment_charge_balance($c) > 0 || ($current !== null && (int) $current['charge_id'] === (int) $c['id'])));
    $optional = q_all("SELECT * FROM payment_items WHERE status = 'open' AND applies_to = 'nobody' ORDER BY name");
    // Open items with a charge for this member are paid through the charge.
    return ['charges' => $charges, 'optional' => $optional];
}

/** Totals for "What I owe": outstanding on open items and paid (approved, net of reversals) this year. */
function payment_member_totals(int $memberId, ?DateTimeImmutable $now = null): array
{
    $year = (int) ($now ?? new DateTimeImmutable('now', site_tz()))->setTimezone(site_tz())->format('Y');
    $outstanding = 0;
    foreach (payment_member_charges($memberId, true) as $c) {
        $outstanding += payment_charge_balance($c);
    }
    $paid = (int) q_val("SELECT COALESCE(SUM(amount_minor), 0) FROM payments WHERE member_id = ? AND status = 'approved' AND paid_on BETWEEN ? AND ?", [$memberId, "$year-01-01", "$year-12-31"]);
    return ['outstanding' => $outstanding, 'paid_year' => $paid, 'year' => $year];
}

/**
 * Possible duplicates (warn, not block): the member's pending/approved
 * records with the same reference, or the same amount + date + item.
 * @return list<array{id:int,why:string}>
 */
function payment_duplicates(int $memberId, array $d, ?int $excludeId = null): array
{
    $rows = q_all(
        "SELECT id, reference, amount_minor, paid_on, item_id, other_name FROM payments
          WHERE member_id = ? AND entry_type = 'payment' AND status IN ('pending','needs_info','approved') AND id <> ?",
        [$memberId, $excludeId ?? 0]
    );
    return payment_duplicates_in($rows, $d);
}

/** Match candidate rows against one record (shared by the form and the admin queue). */
function payment_duplicates_in(array $rows, array $d): array
{
    $out = [];
    $ref = mb_strtolower(trim((string) ($d['reference'] ?? '')));
    foreach ($rows as $r) {
        if ((int) $r['id'] === (int) ($d['id'] ?? 0)) {
            continue;
        }
        if ($ref !== '' && mb_strtolower(trim((string) $r['reference'])) === $ref) {
            $out[] = ['id' => (int) $r['id'], 'why' => 'same reference'];
        } elseif ((int) $r['amount_minor'] === (int) $d['amount_minor'] && (string) $r['paid_on'] === (string) $d['paid_on']
            && (int) ($r['item_id'] ?? 0) === (int) ($d['item_id'] ?? 0) && ($d['item_id'] !== null || mb_strtolower((string) $r['other_name']) === mb_strtolower((string) ($d['other_name'] ?? '')))) {
            $out[] = ['id' => (int) $r['id'], 'why' => 'same amount, date and item'];
        }
    }
    return $out;
}

/**
 * Validate a report (member) or a record (admin). $target: "c<chargeId>",
 * "i<itemId>" (optional item) or "other". Returns data, errors and warnings
 * (duplicates, overpayment) that need "save anyway".
 * @return array{0:array,1:array<string,string>,2:list<string>}
 */
function payment_validate(array $in, int $memberId, bool $byAdmin, ?array $existing = null, ?DateTimeImmutable $now = null): array
{
    $errors = [];
    $warnings = [];
    $target = (string) ($in['target'] ?? '');
    $d = [
        'member_id' => $memberId, 'item_id' => null, 'charge_id' => null, 'other_name' => '',
        'amount_minor' => money_parse((string) ($in['amount'] ?? '')),
        'paid_on' => trim((string) ($in['paid_on'] ?? '')),
        'method' => (string) ($in['method'] ?? ''),
        'reference' => trim((string) preg_replace('/\s+/u', ' ', (string) ($in['reference'] ?? ''))),
        'payer_name' => trim((string) ($in['payer_name'] ?? '')),
        'note' => trim((string) ($in['note'] ?? '')),
        'id' => $existing['id'] ?? null,
    ];
    $balance = null;
    $item = null;
    if (preg_match('/^c(\d+)$/', $target, $m)) {
        $c = q_one('SELECT c.*, i.status AS item_status, i.allow_part FROM payment_charges c JOIN payment_items i ON i.id = c.item_id WHERE c.id = ? AND c.member_id = ?', [(int) $m[1], $memberId]);
        if ($c === null || ($c['item_status'] !== 'open' && !$byAdmin && (int) ($existing['charge_id'] ?? 0) !== (int) $c['id'])) {
            $errors['target'] = 'Choose what you paid for.';
        } else {
            $d['charge_id'] = (int) $c['id'];
            $d['item_id'] = (int) $c['item_id'];
            $balance = payment_charge_balance($c);
            $item = $c;
        }
    } elseif (preg_match('/^i(\d+)$/', $target, $m)) {
        $i = payment_item((int) $m[1]);
        $hasCharge = $i !== null && q_val('SELECT id FROM payment_charges WHERE item_id = ? AND member_id = ?', [(int) $i['id'], $memberId]) !== null;
        if ($i === null || $hasCharge || ($i['applies_to'] !== 'nobody' && !$byAdmin) || ($i['status'] !== 'open' && !$byAdmin && (int) ($existing['item_id'] ?? 0) !== (int) $i['id'])) {
            $errors['target'] = $hasCharge ? 'This member has a charge for that item: choose the charge.' : 'Choose what you paid for.';
        } else {
            $d['item_id'] = (int) $i['id'];
            $item = $i;
            if ($i['amount_minor'] !== null) {
                $balance = (int) $i['amount_minor'];
            }
        }
    } elseif ($target === 'other') {
        $d['other_name'] = trim((string) ($in['other_name'] ?? ''));
        if ($d['other_name'] === '' || mb_strlen($d['other_name']) > 120) {
            $errors['other_name'] = 'Say what the payment was for (up to 120 characters).';
        }
    } else {
        $errors['target'] = 'Choose what you paid for.';
    }
    if ($d['amount_minor'] === null || $d['amount_minor'] <= 0) {
        $errors['amount'] = 'Enter the amount paid, e.g. ' . money_input(2500) . '.';
    } elseif ($balance !== null && $item !== null) {
        if ((int) $item['allow_part'] === 0 && $d['amount_minor'] < $balance) {
            $errors['amount'] = 'This item must be paid in full: ' . money($balance) . '.';
        } elseif ($d['amount_minor'] > $balance) {
            $warnings[] = 'The amount is more than the ' . ($d['charge_id'] !== null ? 'balance' : 'price') . ' (' . money($balance) . ').';
        }
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $d['paid_on'], site_tz());
    if ($date === false || $date->format('Y-m-d') !== $d['paid_on']) {
        $errors['paid_on'] = 'Enter the date you paid.';
    } elseif ($d['paid_on'] > payment_today($now)) {
        $errors['paid_on'] = 'The date paid cannot be in the future.';
    } elseif ($d['paid_on'] < '2000-01-01') {
        $errors['paid_on'] = 'Enter a date from 2000 onwards.';
    }
    if (!isset(PAYMENT_METHODS[$d['method']])) {
        $errors['method'] = 'Choose how you paid.';
    }
    if (mb_strlen($d['reference']) > 120) {
        $errors['reference'] = 'Keep the reference under 120 characters.';
    }
    if (mb_strlen($d['payer_name']) > 120) {
        $errors['payer_name'] = 'Keep the name under 120 characters.';
    }
    if (mb_strlen($d['note']) > 2000) {
        $errors['note'] = 'Keep the note under 2,000 characters.';
    }
    if (!isset($errors['amount'], $errors['paid_on']) && $d['amount_minor'] !== null) {
        foreach (payment_duplicates($memberId, $d, $existing !== null ? (int) $existing['id'] : null) as $dup) {
            $warnings[] = 'Possible duplicate of payment #' . $dup['id'] . ' (' . $dup['why'] . ').';
        }
    }
    unset($d['id']);
    return [$d, $errors, $warnings];
}

/** Receipt id reused from another record of the same member ("Report another payment for the same transfer"). */
function payment_reusable_receipt(int $fromId, int $memberId): ?array
{
    $p = q_one('SELECT id, paid_on, method, reference, payer_name, receipt_media_id FROM payments WHERE id = ? AND member_id = ? AND entry_type = \'payment\'', [$fromId, $memberId]);
    return $p;
}

/**
 * A member reports a payment (status pending). Returns [id, error].
 * @return array{0:?int,1:?string}
 */
function payment_submit(array $user, int $memberId, array $d, ?array $file, ?int $fromId = null, bool $isUpload = true): array
{
    $hit = rate_take('payrep:' . (int) $user['id'], PAYMENT_REPORTS_PER_DAY, 86400);
    if ($hit === null) {
        return [null, 'You have sent a lot of payment reports today. Please try again tomorrow or contact us.'];
    }
    $from = $fromId !== null ? payment_reusable_receipt($fromId, $memberId) : null;
    try {
        $mediaId = payment_store_receipt($file, (int) $user['id'], $isUpload);
    } catch (UploadException $e) {
        rate_release($hit);
        return [null, $e->getMessage()];
    }
    $mediaId ??= $from !== null && $from['receipt_media_id'] !== null ? (int) $from['receipt_media_id'] : null;
    if ($mediaId === null && payment_settings()['receipt_required']) {
        rate_release($hit);
        return [null, 'Please upload the receipt or proof of payment (a photo or PDF).'];
    }
    $id = payments_tx(static function () use ($d, $mediaId, $user): int {
        $id = db_insert('payments', $d + ['currency' => site_currency(), 'receipt_media_id' => $mediaId, 'status' => 'pending']);
        payment_event($id, null, (int) $user['id'], 'member', 'submitted');
        return $id;
    });
    payment_pending_count(true);
    if (payment_settings()['notify_received']) {
        payment_mail_member($id, 'payment_received');
    }
    return [$id, null];
}

/**
 * A member edits a pending or "needs info" record (an edit answers a
 * question: the record goes back to pending). Returns an error or null.
 */
function payment_member_update(array $user, int $id, int $memberId, array $d, ?array $file, bool $isUpload = true): ?string
{
    $hit = rate_take('payrep:' . (int) $user['id'], PAYMENT_REPORTS_PER_DAY, 86400);
    if ($hit === null) {
        return 'You have sent a lot of payment reports today. Please try again tomorrow or contact us.';
    }
    try {
        $mediaId = payment_store_receipt($file, (int) $user['id'], $isUpload);
    } catch (UploadException $e) {
        rate_release($hit);
        return $e->getMessage();
    }
    $old = null;
    $error = payments_tx(static function () use ($id, $memberId, $d, $mediaId, $user, &$old): ?string {
        $p = q_one('SELECT * FROM payments WHERE id = ? AND member_id = ? FOR UPDATE', [$id, $memberId]);
        if ($p === null || !in_array($p['status'], ['pending', 'needs_info'], true) || $p['entry_type'] !== 'payment') {
            return 'This payment can no longer be changed.';
        }
        $old = $p;
        db_update('payments', $id, $d + ['status' => 'pending'] + ($mediaId !== null ? ['receipt_media_id' => $mediaId] : []));
        payment_event($id, null, (int) $user['id'], 'member', $p['status'] === 'needs_info' ? 'answered' : 'edited');
        return null;
    });
    if ($error !== null) {
        rate_release($hit);
        if ($mediaId !== null) {
            payment_receipt_release($mediaId);
        }
        return $error;
    }
    if ($mediaId !== null && $old !== null && $old['receipt_media_id'] !== null) {
        payment_receipt_release((int) $old['receipt_media_id']);
    }
    payment_pending_count(true);
    return null;
}

/** A member withdraws a pending or "needs info" record. */
function payment_withdraw(array $user, int $id, int $memberId): bool
{
    $media = null;
    $ok = payments_tx(static function () use ($id, $memberId, $user, &$media): bool {
        $p = q_one('SELECT * FROM payments WHERE id = ? AND member_id = ? FOR UPDATE', [$id, $memberId]);
        if ($p === null || !in_array($p['status'], ['pending', 'needs_info'], true)) {
            return false;
        }
        q("UPDATE payments SET status = 'withdrawn', receipt_media_id = NULL WHERE id = ?", [$id]);
        payment_event($id, null, (int) $user['id'], 'member', 'withdrawn');
        $media = $p['receipt_media_id'] !== null ? (int) $p['receipt_media_id'] : null;
        return true;
    });
    if ($ok) {
        payment_receipt_release($media);
        payment_pending_count(true);
    }
    return $ok;
}

// ---- Review and the ledger ------------------------------------------------------------------------

/** Next receipt number for the year (row-locked counter; call inside a transaction). */
function payment_next_receipt_number(DateTimeImmutable $at): string
{
    $year = (int) $at->setTimezone(site_tz())->format('Y');
    q('INSERT IGNORE INTO receipt_sequences (seq_year, seq_value) VALUES (?, 0)', [$year]);
    $n = (int) q_val('SELECT seq_value FROM receipt_sequences WHERE seq_year = ? FOR UPDATE', [$year]) + 1;
    q('UPDATE receipt_sequences SET seq_value = ? WHERE seq_year = ?', [$n, $year]);
    return sprintf('RCP-%d-%05d', $year, $n);
}

/**
 * Apply an approved amount (negative for a reversal) to the charge and item
 * counters; "counts as membership dues" items update the member's fee
 * status (and card "valid until") when the charge becomes fully paid.
 */
function payment_ledger_apply(array $p, int $amount): void
{
    if ($p['charge_id'] !== null) {
        $before = q_one('SELECT * FROM payment_charges WHERE id = ? FOR UPDATE', [(int) $p['charge_id']]);
        if ($before !== null) {
            $after = payment_charge_save($before, ['paid_minor' => (int) $before['paid_minor'] + $amount] + $before);
            $item = q_one('SELECT counts_as_dues, covers_until FROM payment_items WHERE id = ?', [(int) $before['item_id']]);
            if ($item !== null && (int) $item['counts_as_dues'] === 1 && $before['member_id'] !== null) {
                if ($after['charge_status'] === 'paid' && $before['charge_status'] !== 'paid') {
                    q("UPDATE members SET membership_fee_status = 'paid' WHERE id = ?", [(int) $before['member_id']]);
                    if ($item['covers_until'] !== null) {
                        q('UPDATE members SET card_valid_until = ? WHERE id = ? AND (card_valid_until IS NULL OR card_valid_until < ?)', [$item['covers_until'], (int) $before['member_id'], $item['covers_until']]);
                    }
                } elseif ($before['charge_status'] === 'paid' && !in_array($after['charge_status'], ['paid', 'waived'], true)) {
                    q("UPDATE members SET membership_fee_status = 'due' WHERE id = ? AND membership_fee_status = 'paid'", [(int) $before['member_id']]);
                }
            }
        }
    }
    if ($p['item_id'] !== null) {
        payment_item_bump((int) $p['item_id'], 0, $amount, 0);
    }
}

/** Separation of duties: may this admin approve the record? */
function payment_separation_problem(array $p, array $admin): ?string
{
    if (!payment_settings()['separation']) {
        return null;
    }
    if ($p['recorded_by'] !== null && (int) $p['recorded_by'] === (int) $admin['id']) {
        return 'Separation of duties is on: another admin must approve a payment you recorded.';
    }
    if ($p['member_id'] !== null && (int) q_val('SELECT user_id FROM members WHERE id = ?', [(int) $p['member_id']]) === (int) $admin['id']) {
        return 'Separation of duties is on: another admin must approve your own payment.';
    }
    return null;
}

/** Approve a pending/needs-info record: ledger, receipt number, email. Returns an error or null. */
function payment_approve(int $id, array $admin, string $note = '', ?DateTimeImmutable $now = null): ?string
{
    $now ??= new DateTimeImmutable('now', utc());
    $error = payments_tx(static function () use ($id, $admin, $note, $now): ?string {
        $p = q_one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$id]);
        if ($p === null || $p['entry_type'] !== 'payment' || !in_array($p['status'], ['pending', 'needs_info'], true)) {
            return 'This payment is no longer waiting for review.';
        }
        if (($problem = payment_separation_problem($p, $admin)) !== null) {
            return $problem;
        }
        payment_ledger_apply($p, (int) $p['amount_minor']);
        q("UPDATE payments SET status = 'approved', reviewed_by = ?, reviewed_at = ?, review_note = ?, receipt_number = ?, receipt_code = ? WHERE id = ?", [
            (int) $admin['id'], $now->format('Y-m-d H:i:s'), mb_substr($note, 0, 1000), payment_next_receipt_number($now), bin2hex(random_bytes(12)), $id,
        ]);
        payment_event($id, null, (int) $admin['id'], 'admin', 'approved', $note);
        return null;
    });
    if ($error === null) {
        payment_pending_count(true);
        payment_mail_member($id, 'payment_approved');
    }
    return $error;
}

/** Reject (reason required) or ask for more information (question required). */
function payment_review(int $id, array $admin, string $action, string $text): ?string
{
    $text = trim($text);
    if ($text === '' || mb_strlen($text) > 1000) {
        return $action === 'reject' ? 'Give a reason for rejecting (up to 1,000 characters).' : 'Write your question for the member (up to 1,000 characters).';
    }
    $status = $action === 'reject' ? 'rejected' : 'needs_info';
    $error = payments_tx(static function () use ($id, $admin, $status, $text): ?string {
        $p = q_one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$id]);
        if ($p === null || $p['entry_type'] !== 'payment' || !in_array($p['status'], ['pending', 'needs_info'], true)) {
            return 'This payment is no longer waiting for review.';
        }
        q('UPDATE payments SET status = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(), review_note = ? WHERE id = ?', [$status, (int) $admin['id'], $text, $id]);
        payment_event($id, null, (int) $admin['id'], 'admin', $status === 'rejected' ? 'rejected' : 'needs_info', $text);
        return null;
    });
    if ($error === null) {
        payment_pending_count(true);
        payment_mail_member($id, $status === 'rejected' ? 'payment_rejected' : 'payment_needs_info');
    }
    return $error;
}

/**
 * Reverse an approved payment: a linked negative entry with a reason
 * (bounced transfer, entered twice, refunded...). Returns [reversalId, error].
 * @return array{0:?int,1:?string}
 */
function payment_reverse(int $id, array $admin, string $reason, ?DateTimeImmutable $now = null): array
{
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason) > 1000) {
        return [null, 'Give the reason for the reversal (up to 1,000 characters).'];
    }
    $now ??= new DateTimeImmutable('now', utc());
    try {
        return payments_tx(static function () use ($id, $admin, $reason, $now): array {
            $p = q_one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$id]);
            if ($p === null || $p['entry_type'] !== 'payment' || $p['status'] !== 'approved') {
                return [null, 'Only approved payments can be reversed.'];
            }
            if (q_val('SELECT id FROM payments WHERE reverses_id = ?', [$id]) !== null) {
                return [null, 'This payment was already reversed.'];
            }
            $rid = db_insert('payments', [
                'member_id' => $p['member_id'], 'item_id' => $p['item_id'], 'charge_id' => $p['charge_id'], 'other_name' => $p['other_name'],
                'entry_type' => 'reversal', 'reverses_id' => $id, 'amount_minor' => -(int) $p['amount_minor'], 'currency' => $p['currency'],
                'paid_on' => payment_today($now), 'method' => $p['method'], 'reference' => $p['reference'], 'status' => 'approved',
                'recorded_by' => (int) $admin['id'], 'reviewed_by' => (int) $admin['id'], 'reviewed_at' => $now->format('Y-m-d H:i:s'),
                'review_note' => $reason, 'former_label' => $p['former_label'],
            ]);
            payment_ledger_apply($p, -(int) $p['amount_minor']);
            payment_event($id, null, (int) $admin['id'], 'admin', 'reversed', $reason);
            payment_event($rid, null, (int) $admin['id'], 'admin', 'recorded', 'Reversal of payment #' . $id . ': ' . $reason);
            return [$rid, null];
        });
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) {
            return [null, 'This payment was already reversed.'];
        }
        throw $e;
    }
}

/**
 * An admin records a payment for a member (cash at a meeting, a bank
 * statement line). Saved as approved with "Recorded by", or pending when
 * separation of duties is on (another admin approves it).
 * @return array{0:?int,1:?string}
 */
function payment_record(array $admin, array $d, ?array $file, bool $isUpload = true, ?DateTimeImmutable $now = null): array
{
    try {
        $mediaId = payment_store_receipt($file, (int) $admin['id'], $isUpload);
    } catch (UploadException $e) {
        return [null, $e->getMessage()];
    }
    $id = payments_tx(static function () use ($d, $mediaId, $admin): int {
        $id = db_insert('payments', $d + ['currency' => site_currency(), 'receipt_media_id' => $mediaId, 'status' => 'pending', 'recorded_by' => (int) $admin['id']]);
        payment_event($id, null, (int) $admin['id'], 'admin', 'recorded');
        return $id;
    });
    if (!payment_settings()['separation']) {
        $error = payment_approve($id, $admin, 'Recorded by an admin', $now);
        if ($error !== null) {
            return [$id, $error];
        }
    } else {
        payment_pending_count(true);
    }
    return [$id, null];
}

/** Waive (or un-waive) a member's charge; reason required. */
function payment_charge_waive(int $chargeId, array $admin, bool $waive, string $reason): ?string
{
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason) > 255) {
        return 'Give a reason (up to 255 characters).';
    }
    return payments_tx(static function () use ($chargeId, $admin, $waive, $reason): ?string {
        $c = q_one('SELECT * FROM payment_charges WHERE id = ? FOR UPDATE', [$chargeId]);
        if ($c === null || (int) $c['waived'] === ($waive ? 1 : 0)) {
            return 'Nothing to change.';
        }
        payment_charge_save($c, ['waived' => $waive ? 1 : 0, 'waive_reason' => $waive ? $reason : ''] + $c);
        payment_event(null, $chargeId, (int) $admin['id'], 'admin', $waive ? 'waived' : 'unwaived', $reason);
        return null;
    });
}

/** Change what one member owes for an item; reason required. */
function payment_charge_set_amount(int $chargeId, array $admin, string $amountText, string $reason): ?string
{
    $reason = trim($reason);
    $amount = money_parse($amountText);
    if ($amount === null) {
        return 'Enter the new amount.';
    }
    if ($reason === '' || mb_strlen($reason) > 255) {
        return 'Give a reason (up to 255 characters).';
    }
    return payments_tx(static function () use ($chargeId, $admin, $amount, $reason): ?string {
        $c = q_one('SELECT * FROM payment_charges WHERE id = ? FOR UPDATE', [$chargeId]);
        if ($c === null || (int) $c['amount_minor'] === $amount) {
            return 'Nothing to change.';
        }
        payment_charge_save($c, ['amount_minor' => $amount] + $c);
        payment_event(null, $chargeId, (int) $admin['id'], 'admin', 'amount_changed', money((int) $c['amount_minor']) . ' → ' . money($amount) . ': ' . $reason);
        return null;
    });
}

/** Add charges for chosen members (items that apply to chosen members). Returns the number added. */
function payment_item_add_members(int $itemId, array $memberIds): int
{
    $n = 0;
    payments_tx(static function () use ($itemId, $memberIds, &$n): void {
        $n = 0;
        $item = q_one('SELECT * FROM payment_items WHERE id = ? FOR UPDATE', [$itemId]);
        if ($item === null || $item['amount_minor'] === null || $item['status'] === 'closed') {
            return;
        }
        foreach (array_unique(array_map('intval', $memberIds)) as $mid) {
            if ($mid > 0 && q_val("SELECT 1 FROM members m JOIN users u ON u.id = m.user_id WHERE m.id = ? AND u.role = 'member'", [$mid]) !== null
                && payment_charge_create($itemId, $mid, (int) $item['amount_minor']) !== null) {
                $n++;
            }
        }
    });
    return $n;
}

/** The record's timeline. For members, admins appear as the reviewer label ("Treasurer"). */
function payment_timeline(int $paymentId, bool $forMember): array
{
    $label = payment_settings()['reviewer_label'];
    $rows = q_all('SELECT e.*, u.display_name FROM payment_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.payment_id = ? ORDER BY e.id', [$paymentId]);
    $words = ['submitted' => 'Reported', 'edited' => 'Edited', 'answered' => 'Answered the question', 'withdrawn' => 'Withdrawn', 'approved' => 'Approved',
        'rejected' => 'Rejected', 'needs_info' => 'Asked for more information', 'reversed' => 'Reversed', 'recorded' => 'Recorded', 'account_deleted' => 'Account deleted', 'receipt_deleted' => 'Receipt file deleted'];
    return array_map(static function (array $r) use ($forMember, $label, $words): array {
        $who = match ($r['actor_role']) {
            'member' => $forMember ? 'You' : ($r['display_name'] ?? 'Member'),
            'admin' => $forMember ? $label : ($r['display_name'] ?? 'Admin'),
            default => 'System',
        };
        return ['when' => (string) $r['created_at'], 'who' => $who, 'what' => $words[$r['event_type']] ?? $r['event_type'], 'note' => (string) $r['note']];
    }, $rows);
}

// ---- Emails ----------------------------------------------------------------------------------------

/** Queue a status email to the member (transactional: bounced addresses are skipped). */
function payment_mail_member(int $paymentId, string $template): bool
{
    $p = q_one('SELECT p.*, i.name AS item_name, m.first_name, m.last_name, u.email, u.status AS user_status FROM payments p
                 LEFT JOIN payment_items i ON i.id = p.item_id JOIN members m ON m.id = p.member_id JOIN users u ON u.id = m.user_id WHERE p.id = ?', [$paymentId]);
    if ($p === null || $p['user_status'] !== 'active') {
        return false;
    }
    $parts = email_template_render($template, [
        'first_name' => (string) $p['first_name'], 'item' => payment_what($p), 'amount' => money((int) $p['amount_minor'], (string) $p['currency']),
        'date_paid' => (new DateTimeImmutable((string) $p['paid_on']))->format('j M Y'), 'receipt_number' => (string) ($p['receipt_number'] ?? ''),
        'reason' => (string) $p['review_note'], 'question' => (string) $p['review_note'],
        'receipt_url' => base_url('/account/payments/' . $paymentId . '/receipt'), 'payment_url' => base_url('/account/payments/' . $paymentId),
        'reviewer' => payment_settings()['reviewer_label'],
    ]);
    try {
        return mail_queue_message(new Message((string) $p['email'], trim($p['first_name'] . ' ' . $p['last_name']), $parts['subject'], $parts['text'], $parts['html']), 'transactional', 'pay:' . $paymentId . ':' . $template . ':' . substr(hash('sha256', (string) $p['updated_at'] . $p['status']), 0, 12));
    } catch (InvalidArgumentException) {
        return false;
    }
}

/** Admins who review payments and want report emails. */
function payment_admins(bool $emailOnly = false): array
{
    return q_all("SELECT u.id, u.email, u.display_name FROM users u WHERE u.status = 'active' AND (u.role = 'super_admin' OR (u.role = 'admin' AND EXISTS (SELECT 1 FROM admin_permissions p WHERE p.user_id = u.id AND p.permission = 'payments')))"
        . ($emailOnly ? ' AND u.payment_emails = 1' : '') . ' ORDER BY u.id');
}

/**
 * Cron: email admins about new and edited payment reports, at most one
 * email per admin per 15 minutes (template payment_reported_admin).
 */
function cron_payment_notices(?int $now = null): string
{
    $now ??= time();
    $sent = 0;
    $max = (int) q_val("SELECT COALESCE(MAX(id), 0) FROM payment_events WHERE event_type IN ('submitted','edited','answered')");
    if ($max === 0) {
        return '0 email(s)';
    }
    $since = gmdate('Y-m-d H:i:s', $now - 7 * 86400);
    foreach (payment_admins(true) as $a) {
        $mark = q_one('SELECT * FROM payment_admin_notices WHERE user_id = ?', [(int) $a['id']]);
        $last = (int) ($mark['last_event_id'] ?? 0);
        if ($last >= $max || ($mark !== null && $mark['last_sent_at'] !== null && strtotime($mark['last_sent_at'] . ' UTC') > $now - PAYMENT_ADMIN_EMAIL_MINUTES * 60)) {
            continue;
        }
        $rows = q_all(
            "SELECT e.id AS event_id, e.event_type, p.id, p.amount_minor, p.currency, p.paid_on, p.method, p.reference, p.item_id, p.other_name, i.name AS item_name, m.first_name, m.last_name, m.member_number, p.former_label
               FROM payment_events e JOIN payments p ON p.id = e.payment_id LEFT JOIN payment_items i ON i.id = p.item_id LEFT JOIN members m ON m.id = p.member_id
              WHERE e.id > ? AND e.id <= ? AND e.event_type IN ('submitted','edited','answered') AND e.created_at > ? AND p.status = 'pending'
              ORDER BY e.id LIMIT 50",
            [$last, $max, $since]
        );
        $mark === null
            ? q('INSERT INTO payment_admin_notices (user_id, last_event_id, last_sent_at) VALUES (?, ?, ?)', [(int) $a['id'], $max, $rows ? gmdate('Y-m-d H:i:s', $now) : null])
            : q('UPDATE payment_admin_notices SET last_event_id = ?, last_sent_at = ' . ($rows ? '?' : 'last_sent_at') . ' WHERE user_id = ?', $rows ? [$max, gmdate('Y-m-d H:i:s', $now), (int) $a['id']] : [$max, (int) $a['id']]);
        if ($rows === [] || email_suppression((string) $a['email']) !== null) {
            continue;
        }
        $lines = [];
        $seen = [];
        foreach ($rows as $r) {
            if (isset($seen[(int) $r['id']])) {
                continue;
            }
            $seen[(int) $r['id']] = true;
            $lines[] = '• ' . payment_member_label($r) . ($r['member_number'] ? ' (' . $r['member_number'] . ')' : '') . ' · ' . payment_what($r) . ' · ' . money((int) $r['amount_minor'], (string) $r['currency'])
                . ' · paid ' . (new DateTimeImmutable((string) $r['paid_on']))->format('j M') . ' by ' . strtolower(PAYMENT_METHODS[$r['method']] ?? $r['method'])
                . ($r['reference'] !== '' ? ' (ref ' . $r['reference'] . ')' : '') . ($r['event_type'] !== 'submitted' ? ' [' . ($r['event_type'] === 'answered' ? 'answered your question' : 'edited') . ']' : '')
                . "\n  Review: " . base_url('/admin/payments/' . (int) $r['id']);
        }
        $parts = email_template_render('payment_reported_admin', [
            'first_name' => explode(' ', (string) $a['display_name'])[0], 'count' => (string) count($lines), 'report_list' => implode("\n", $lines),
            'queue_url' => base_url('/admin/payments'), 'preferences_url' => base_url('/admin/payments#payment-emails'),
        ]);
        try {
            if (mail_queue_message(new Message((string) $a['email'], (string) $a['display_name'], $parts['subject'], $parts['text'], $parts['html']), 'essential', 'payrep:' . $a['id'] . ':' . $max, 0, '', $now)) {
                $sent++;
            }
        } catch (InvalidArgumentException) {
            continue;
        }
    }
    return $sent . ' email(s)';
}

/** Charges with a balance on an item (unpaid and part-paid), active members only. */
function payment_unpaid_rows(int $itemId, int $limit = 0, int $afterId = 0): array
{
    return q_all(
        "SELECT c.*, m.first_name, m.last_name, m.member_number, m.user_id, u.email FROM payment_charges c JOIN members m ON m.id = c.member_id JOIN users u ON u.id = m.user_id
          WHERE c.item_id = ? AND c.charge_status IN ('unpaid','part') AND u.status = 'active' AND c.id > ? ORDER BY c.id" . ($limit > 0 ? ' LIMIT ' . $limit : ''),
        [$itemId, $afterId]
    );
}

/** How many unpaid members "Remind unpaid" would email (suppressed addresses left out). */
function payment_remind_count(int $itemId): array
{
    $total = 0;
    $skipped = 0;
    $suppressed = array_flip(array_column(q_all('SELECT email FROM email_suppressions'), 'email'));
    foreach (payment_unpaid_rows($itemId) as $r) {
        isset($suppressed[strtolower((string) $r['email'])]) ? $skipped++ : $total++;
    }
    return ['send' => $total, 'skipped' => $skipped];
}

/** Queue one reminder (essential notice) for a charge. */
function payment_queue_reminder(array $item, array $c, string $template, string $dedupe, ?int $now = null): bool
{
    if (email_suppression((string) $c['email']) !== null) {
        return false;
    }
    $parts = email_template_render($template, [
        'first_name' => (string) $c['first_name'], 'item' => (string) $item['name'], 'amount' => money((int) $c['amount_minor']),
        'balance' => money(payment_charge_balance($c)), 'due_date' => $item['due_date'] !== null ? (new DateTimeImmutable((string) $item['due_date']))->format('j F Y') : 'no due date',
        'pay_url' => base_url('/account/payments'), 'bank_details' => trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n"], payment_settings()['bank_details'])), ENT_QUOTES | ENT_HTML5)),
    ]);
    try {
        return mail_queue_message(new Message((string) $c['email'], trim($c['first_name'] . ' ' . $c['last_name']), $parts['subject'], $parts['text'], $parts['html']), 'essential', $dedupe, 0, '', $now);
    } catch (InvalidArgumentException) {
        return false;
    }
}

/** "Remind unpaid": one queued email per unpaid member, at most once a day per charge. Returns how many were queued. */
function payment_remind_unpaid(int $itemId, ?DateTimeImmutable $now = null): int
{
    $item = payment_item($itemId);
    if ($item === null) {
        return 0;
    }
    $day = payment_today($now);
    $n = 0;
    $after = 0;
    do {
        $rows = payment_unpaid_rows($itemId, PAYMENT_CHARGE_BATCH, $after);
        foreach ($rows as $c) {
            $after = (int) $c['id'];
            if (payment_queue_reminder($item, $c, 'payment_remind_unpaid', 'payremind:' . $c['id'] . ':' . $day, $now?->getTimestamp())) {
                $n++;
            }
        }
    } while (count($rows) === PAYMENT_CHARGE_BATCH);
    return $n;
}

/**
 * Cron (daily): due-date reminders per item, N days before the due date and
 * N days after it (item values, else the defaults from Settings → Payments),
 * at most one reminder per charge per stage, to members with a balance.
 */
function cron_payment_reminders(?DateTimeImmutable $now = null): string
{
    $today = payment_today($now);
    $s = payment_settings();
    $sent = 0;
    $nowTs = $now?->getTimestamp();
    foreach (q_all("SELECT * FROM payment_items WHERE status = 'open' AND remind_enabled = 1 AND due_date IS NOT NULL AND amount_minor IS NOT NULL") as $item) {
        $due = new DateTimeImmutable((string) $item['due_date']);
        $before = $item['remind_before_days'] !== null ? (int) $item['remind_before_days'] : $s['remind_before'];
        $after = $item['remind_after_days'] !== null ? (int) $item['remind_after_days'] : $s['remind_after'];
        $stages = [];
        if ($before > 0 && $today >= $due->modify("-$before days")->format('Y-m-d') && $today <= $due->format('Y-m-d')) {
            $stages[] = ['before', 'reminded_before_at'];
        }
        if ($after > 0 && $today >= $due->modify("+$after days")->format('Y-m-d')) {
            $stages[] = ['after', 'reminded_after_at'];
        }
        foreach ($stages as [$stage, $col]) {
            do {
                $rows = q_all(
                    "SELECT c.*, m.first_name, m.last_name, u.email FROM payment_charges c JOIN members m ON m.id = c.member_id JOIN users u ON u.id = m.user_id
                      WHERE c.item_id = ? AND c.charge_status IN ('unpaid','part') AND u.status = 'active' AND c." . db_ident($col) . ' IS NULL ORDER BY c.id LIMIT ' . PAYMENT_CHARGE_BATCH,
                    [(int) $item['id']]
                );
                foreach ($rows as $c) {
                    if (payment_queue_reminder($item, $c, 'payment_reminder', 'payrem:' . $c['id'] . ':' . $stage, $nowTs)) {
                        $sent++;
                    }
                    q('UPDATE payment_charges SET ' . db_ident($col) . ' = UTC_TIMESTAMP() WHERE id = ?', [(int) $c['id']]);
                }
            } while (count($rows) === PAYMENT_CHARGE_BATCH);
        }
    }
    return $sent . ' reminder(s)';
}

// ---- Reconcile ---------------------------------------------------------------------------------

/**
 * Recompute every maintained counter from the ledger and report drift:
 * charge paid_minor / charge_status, item expected / collected / outstanding.
 * With $fix the stored values are corrected.
 * @return list<string>
 */
function payments_reconcile(bool $fix = false): array
{
    $drift = [];
    $charges = q_all(
        "SELECT * FROM (SELECT c.id, c.item_id, c.amount_minor, c.paid_minor, c.waived, c.charge_status,
                (SELECT COALESCE(SUM(p.amount_minor), 0) FROM payments p WHERE p.charge_id = c.id AND p.status = 'approved') AS paid_calc
           FROM payment_charges c) t
          WHERE t.paid_calc <> t.paid_minor OR t.charge_status <> CASE WHEN t.waived = 1 THEN 'waived' WHEN t.paid_calc >= t.amount_minor THEN 'paid' WHEN t.paid_calc > 0 THEN 'part' ELSE 'unpaid' END"
    );
    foreach ($charges as $c) {
        $status = payment_charge_status((int) $c['amount_minor'], (int) $c['paid_calc'], (int) $c['waived'] === 1);
        $drift[] = sprintf('charge #%d: paid %d (ledger %d), status %s (should be %s)', $c['id'], $c['paid_minor'], $c['paid_calc'], $c['charge_status'], $status);
        if ($fix) {
            q('UPDATE payment_charges SET paid_minor = ?, charge_status = ? WHERE id = ?', [(int) $c['paid_calc'], $status, (int) $c['id']]);
        }
    }
    $items = q_all(
        "SELECT * FROM (SELECT i.id, i.expected_minor, i.collected_minor, i.outstanding_minor,
                (SELECT COALESCE(SUM(CASE WHEN c.waived = 1 THEN GREATEST(c.paid_minor, 0) ELSE c.amount_minor END), 0) FROM payment_charges c WHERE c.item_id = i.id) AS expected_calc,
                (SELECT COALESCE(SUM(CASE WHEN c.waived = 1 THEN 0 ELSE GREATEST(c.amount_minor - c.paid_minor, 0) END), 0) FROM payment_charges c WHERE c.item_id = i.id) AS outstanding_calc,
                (SELECT COALESCE(SUM(p.amount_minor), 0) FROM payments p WHERE p.item_id = i.id AND p.status = 'approved') AS collected_calc
           FROM payment_items i) t
          WHERE t.expected_calc <> t.expected_minor OR t.outstanding_calc <> t.outstanding_minor OR t.collected_calc <> t.collected_minor"
    );
    foreach ($items as $i) {
        $drift[] = sprintf('item #%d: expected %d (ledger %d), collected %d (ledger %d), outstanding %d (ledger %d)', $i['id'], $i['expected_minor'], $i['expected_calc'], $i['collected_minor'], $i['collected_calc'], $i['outstanding_minor'], $i['outstanding_calc']);
        if ($fix) {
            q('UPDATE payment_items SET expected_minor = ?, collected_minor = ?, outstanding_minor = ? WHERE id = ?', [(int) $i['expected_calc'], (int) $i['collected_calc'], (int) $i['outstanding_calc'], (int) $i['id']]);
        }
    }
    return $drift;
}

// ---- Reports ----------------------------------------------------------------------------------------

/**
 * Totals of approved entries (reversals included) by item, by month and
 * by method for a date range (dates paid). ONLY_FULL_GROUP_BY safe.
 */
function payment_report(string $from, string $to): array
{
    $byItem = [];
    foreach (q_all("SELECT item_id, other_name, SUM(amount_minor) AS total, COUNT(*) AS n FROM payments WHERE status = 'approved' AND paid_on BETWEEN ? AND ? GROUP BY item_id, other_name", [$from, $to]) as $r) {
        $key = $r['item_id'] !== null ? 'i' . $r['item_id'] : 'other';
        $byItem[$key] ??= ['item_id' => $r['item_id'] !== null ? (int) $r['item_id'] : null, 'total' => 0, 'n' => 0];
        $byItem[$key]['total'] += (int) $r['total'];
        $byItem[$key]['n'] += (int) $r['n'];
    }
    $names = [];
    $ids = array_values(array_filter(array_column($byItem, 'item_id')));
    if ($ids) {
        $names = array_column(q_all('SELECT id, name FROM payment_items WHERE id IN (' . db_placeholders(count($ids)) . ')', $ids), 'name', 'id');
    }
    foreach ($byItem as &$row) {
        $row['name'] = $row['item_id'] !== null ? (string) ($names[$row['item_id']] ?? 'Item #' . $row['item_id']) : 'Other';
    }
    unset($row);
    usort($byItem, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
    $byMonth = q_all("SELECT DATE_FORMAT(paid_on, '%Y-%m') AS ym, SUM(amount_minor) AS total, COUNT(*) AS n FROM payments WHERE status = 'approved' AND paid_on BETWEEN ? AND ? GROUP BY DATE_FORMAT(paid_on, '%Y-%m') ORDER BY ym", [$from, $to]);
    $byMethod = q_all("SELECT method, SUM(amount_minor) AS total, COUNT(*) AS n FROM payments WHERE status = 'approved' AND paid_on BETWEEN ? AND ? GROUP BY method ORDER BY total DESC", [$from, $to]);
    $total = (int) q_val("SELECT COALESCE(SUM(amount_minor), 0) FROM payments WHERE status = 'approved' AND paid_on BETWEEN ? AND ?", [$from, $to]);
    return ['items' => $byItem, 'months' => $byMonth, 'methods' => $byMethod, 'total' => $total];
}

/** Overview card: pending, collected this month, top outstanding open items. */
function payment_dashboard(?DateTimeImmutable $now = null): array
{
    $local = ($now ?? new DateTimeImmutable('now', utc()))->setTimezone(site_tz());
    return [
        'pending' => payment_pending_count(),
        'month' => (int) q_val("SELECT COALESCE(SUM(amount_minor), 0) FROM payments WHERE status = 'approved' AND paid_on BETWEEN ? AND ?", [$local->format('Y-m-01'), $local->format('Y-m-t')]),
        'outstanding' => q_all("SELECT id, name, outstanding_minor, expected_minor, collected_minor FROM payment_items WHERE status = 'open' AND outstanding_minor > 0 ORDER BY outstanding_minor DESC LIMIT 3"),
    ];
}

// ---- Privacy: export, account deletion, retention ---------------------------------------------------

/** The member's charges and payment records for the data export. */
function payments_export(int $userId): array
{
    $m = q_one('SELECT id FROM members WHERE user_id = ?', [$userId]);
    if ($m === null) {
        return ['charges' => [], 'records' => []];
    }
    $charges = array_map(static fn (array $c): array => [
        'item' => $c['item_name'], 'amount' => money_input((int) $c['amount_minor']), 'paid' => money_input((int) $c['paid_minor']),
        'status' => $c['charge_status'], 'waived_reason' => $c['waive_reason'], 'due_date' => $c['due_date'],
    ], payment_member_charges((int) $m['id']));
    $records = [];
    foreach (q_all('SELECT p.*, i.name AS item_name FROM payments p LEFT JOIN payment_items i ON i.id = p.item_id WHERE p.member_id = ? ORDER BY p.id', [(int) $m['id']]) as $p) {
        $records[] = [
            'id' => (int) $p['id'], 'type' => $p['entry_type'], 'for' => payment_what($p), 'amount' => money_input((int) $p['amount_minor'], (string) $p['currency']), 'currency' => $p['currency'],
            'date_paid' => $p['paid_on'], 'method' => PAYMENT_METHODS[$p['method']] ?? $p['method'], 'reference' => $p['reference'], 'paid_by' => $p['payer_name'],
            'note' => (string) $p['note'], 'status' => $p['status'], 'receipt_number' => $p['receipt_number'], 'reported_at' => $p['created_at'], 'reviewed_at' => $p['reviewed_at'],
            'timeline' => payment_timeline((int) $p['id'], true),
        ];
    }
    return ['currency' => site_currency(), 'charges' => $charges, 'records' => $records];
}

/**
 * Account deletion (inside account_delete()'s transaction): records are kept
 * for the organisation's accounts but anonymised ("Former member #<number>",
 * payer name cleared); waiting reports are withdrawn; unpaid charges with
 * no records are removed and other balances waived. Receipts stay until the
 * retention period ends (cron_payment_retention).
 */
function payments_account_purge(int $userId): void
{
    $m = q_one('SELECT id, member_number FROM members WHERE user_id = ?', [$userId]);
    if ($m === null) {
        return;
    }
    $label = 'Former member #' . ($m['member_number'] !== null && $m['member_number'] !== '' ? $m['member_number'] : $m['id']);
    foreach (q_all("SELECT id FROM payments WHERE member_id = ? AND status IN ('pending','needs_info') FOR UPDATE", [(int) $m['id']]) as $p) {
        q("UPDATE payments SET status = 'withdrawn' WHERE id = ?", [(int) $p['id']]);
        payment_event((int) $p['id'], null, null, 'system', 'account_deleted', 'Withdrawn: the account was deleted.');
    }
    q("UPDATE payments SET former_label = ?, payer_name = '' WHERE member_id = ?", [$label, (int) $m['id']]);
    foreach (q_all('SELECT * FROM payment_charges WHERE member_id = ? FOR UPDATE', [(int) $m['id']]) as $c) {
        if ((int) $c['paid_minor'] === 0 && q_val('SELECT 1 FROM payments WHERE charge_id = ? LIMIT 1', [(int) $c['id']]) === null) {
            [$e, $o] = payment_charge_contrib($c);
            q('DELETE FROM payment_charges WHERE id = ?', [(int) $c['id']]);
            payment_item_bump((int) $c['item_id'], -$e, 0, -$o);
            continue;
        }
        q('UPDATE payment_charges SET former_label = ? WHERE id = ?', [$label, (int) $c['id']]);
        if ((int) $c['waived'] === 0 && payment_charge_balance($c) > 0) {
            payment_charge_save($c, ['waived' => 1, 'waive_reason' => 'Account deleted'] + $c);
            payment_event(null, (int) $c['id'], null, 'system', 'waived', 'Account deleted');
        }
    }
    payment_pending_count(true);
}

/** Cron (daily): delete receipt files of former members' records once the retention period has passed. */
function cron_payment_retention(?DateTimeImmutable $now = null): string
{
    $years = payment_settings()['retention_years'];
    $cutoff = ($now ?? new DateTimeImmutable('now', utc()))->modify("-$years years")->format('Y-m-d H:i:s');
    $n = 0;
    foreach (q_all('SELECT id, receipt_media_id FROM payments WHERE member_id IS NULL AND receipt_media_id IS NOT NULL AND created_at < ? ORDER BY id LIMIT 500', [$cutoff]) as $p) {
        q('UPDATE payments SET receipt_media_id = NULL WHERE id = ?', [(int) $p['id']]);
        payment_event((int) $p['id'], null, null, 'system', 'receipt_deleted', 'Receipt file deleted after the ' . $years . '-year retention period.');
        payment_receipt_release((int) $p['receipt_media_id']);
        $n++;
    }
    return $n . ' receipt file(s) deleted';
}

/** Verification page lookup: [valid, payment] for a receipt code. */
function payment_verify(string $code): ?array
{
    if (!preg_match('/^[a-f0-9]{24}$/', $code)) {
        return null;
    }
    return q_one(
        "SELECT p.id, p.amount_minor, p.currency, p.paid_on, p.status, p.receipt_number, m.first_name, m.last_name, p.former_label,
                (SELECT r.id FROM payments r WHERE r.reverses_id = p.id) AS reversal_id
           FROM payments p LEFT JOIN members m ON m.id = p.member_id WHERE p.receipt_code = ? AND p.entry_type = 'payment'",
        [$code]
    );
}

/** Initials only ("A. O.") for the public verification page. */
function payment_initials(array $p): string
{
    if (($p['first_name'] ?? null) === null) {
        return 'Former member';
    }
    $i = static fn (string $s): string => $s !== '' ? mb_strtoupper(mb_substr($s, 0, 1)) . '.' : '';
    return trim($i((string) $p['first_name']) . ' ' . $i((string) $p['last_name']));
}
