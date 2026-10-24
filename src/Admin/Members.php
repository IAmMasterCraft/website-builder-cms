<?php
declare(strict_types=1);

namespace Admin;

use Core\UploadException;

/** Members screen (§7): search/filters, edit, suspend, remove, reset link, CSV, ID cards, notes. */
final class Members
{
    private const PER = 30;

    private static function filters(): array
    {
        $f = [];
        foreach (['q', 'status', 'type', 'region', 'field', 'value'] as $k) {
            $f[$k] = mb_substr(query_param($k), 0, 100);
        }
        return $f;
    }

    private static function member(array $p): array
    {
        $m = q_one("SELECT m.*, u.email, u.status, u.role, u.last_login_at, u.created_at AS joined FROM members m JOIN users u ON u.id = m.user_id WHERE m.id = ? AND u.role = 'member'", [(int) $p['id']]);
        if ($m === null) {
            abort(404);
        }
        return $m;
    }

    public static function index(array $p): void
    {
        require_permission('members');
        $f = self::filters();
        [$where, $params] = members_filter_sql($f);
        $page = max(1, input_int('page', 1, true));
        $total = (int) q_val("SELECT COUNT(*) FROM members m JOIN users u ON u.id = m.user_id WHERE $where", $params);
        $rows = q_all(
            "SELECT m.*, u.email, u.status, u.created_at AS joined FROM members m JOIN users u ON u.id = m.user_id WHERE $where ORDER BY u.created_at DESC, m.id DESC LIMIT " . self::PER . ' OFFSET ' . (($page - 1) * self::PER),
            $params
        );
        media_preload(array_column($rows, 'photo_media_id'));
        $s = member_settings();
        render('admin/members_index', [
            'title' => 'Members', 'rows' => $rows, 'filters' => $f, 'total' => $total, 'page' => $page, 'per' => self::PER,
            'settings' => $s, 'fields' => array_values(array_filter(custom_fields('member'), static fn (array $cf): bool => $cf['type'] !== 'date')),
            'counts' => [
                'active' => (int) q_val("SELECT COUNT(*) FROM members m JOIN users u ON u.id = m.user_id WHERE u.status = 'active' AND u.role = 'member'"),
                'unverified' => (int) q_val("SELECT COUNT(*) FROM users WHERE status = 'unverified' AND role = 'member'"),
                'deletion' => (int) q_val('SELECT COUNT(*) FROM members WHERE deletion_requested_at IS NOT NULL'),
            ],
        ], 'admin');
    }

    public static function csv(array $p): void
    {
        require_permission('members');
        $rows = members_csv_rows(self::filters());
        audit('export', 'members', null, (count($rows) - 1) . ' rows');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="members-' . gmdate('Y-m-d') . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($rows as $r) {
            fputcsv($out, $r, ',', '"', '');
        }
        fclose($out);
    }

    public static function importForm(array $p): void
    {
        require_permission('members');
        $report = $_SESSION['import_report'] ?? null;
        unset($_SESSION['import_report']);
        render('admin/members_import', ['title' => 'Import members', 'report' => is_array($report) ? $report : null, 'columns' => MEMBER_CSV_COLUMNS, 'fields' => custom_fields('member')], 'admin');
    }

    public static function import(array $p): void
    {
        csrf_check();
        require_permission('members');
        $file = $_FILES['csv'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            flash('Choose a CSV file to import.', 'error');
            redirect('/admin/members/import');
        }
        if ((int) $file['size'] > 5 * 1024 * 1024) {
            flash('The file is larger than 5 MB.', 'error');
            redirect('/admin/members/import');
        }
        $head = (string) file_get_contents((string) $file['tmp_name'], false, null, 0, 4096);
        if (preg_match('/[\x00-\x08\x0E-\x1F]/', str_replace(["\xEF\xBB\xBF"], '', $head))) {
            flash('That does not look like a CSV text file.', 'error');
            redirect('/admin/members/import');
        }
        $report = members_import_csv((string) $file['tmp_name'], input('update') === '1');
        if (input('notify') === '1') {
            foreach ($report['new_user_ids'] as $uid) {
                $u = q_one('SELECT u.email, m.first_name, m.member_number FROM users u JOIN members m ON m.user_id = u.id WHERE u.id = ?', [$uid]);
                if ($u !== null) {
                    send_template_mail((string) $u['email'], (string) $u['first_name'], 'welcome_import', ['member_number' => (string) $u['member_number'], 'login_url' => base_url('/forgot-password')]);
                }
            }
        }
        unset($report['new_user_ids']);
        $report['errors'] = array_slice($report['errors'], 0, 100);
        $_SESSION['import_report'] = $report;
        audit('import', 'members', null, "created {$report['created']}, updated {$report['updated']}, skipped {$report['skipped']}");
        redirect('/admin/members/import');
    }

    public static function edit(array $p): void
    {
        require_permission('members');
        $m = self::member($p);
        $old = take_old_input();
        $values = $m;
        $cv = custom_values_for_member((int) $m['id']);
        foreach (custom_fields('member') as $f) {
            $values['cf_' . $f['field_key']] = $cv[(int) $f['id']] ?? '';
        }
        render('admin/member_edit', [
            'title' => member_full_name($m), 'm' => $m, 'values' => $old['values'] + $values, 'errors' => $old['errors'],
            'settings' => member_settings(), 'fields' => custom_fields('member'),
            'photo' => media_find($m['photo_media_id'] ? (int) $m['photo_media_id'] : null),
            'notes' => q_all('SELECT n.*, u.display_name AS author FROM member_notes n LEFT JOIN users u ON u.id = n.author_id WHERE n.member_id = ? ORDER BY n.created_at DESC, n.id DESC', [$m['id']]),
            'registrations' => (int) q_val('SELECT COUNT(*) FROM event_registrations WHERE user_id = ?', [$m['user_id']]),
            'verify_url' => $m['member_number'] && $m['card_code'] ? card_verify_url($m) : null,
        ], 'admin');
    }

    public static function update(array $p): void
    {
        csrf_check();
        $admin = require_permission('members');
        $m = self::member($p);
        [$profile, $errors] = member_profile_validate($_POST, true);
        [$custom, $cErrors] = custom_fields_validate(custom_fields('member'), $_POST);
        $errors += $cErrors;
        $s = member_settings();
        $email = strtolower(input('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif (q_val('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $m['user_id']]) !== null) {
            $errors['email'] = 'Another account uses this email.';
        }
        $type = input('membership_type');
        if (!in_array($type, $s['types'], true) && $type !== $m['membership_type']) {
            $errors['membership_type'] = 'Choose a membership type.';
        }
        $fee = input('membership_fee_status');
        if (!in_array($fee, ['', 'free', 'due', 'paid', 'waived'], true)) {
            $errors['membership_fee_status'] = 'Choose a fee status.';
        }
        $valid = input('card_valid_until');
        if ($valid !== '' && (($d = \DateTimeImmutable::createFromFormat('!Y-m-d', $valid)) === false || $d->format('Y-m-d') !== $valid)) {
            $errors['card_valid_until'] = 'Enter a date, or leave empty for no expiry.';
        }
        $photoId = null;
        $file = $_FILES['photo'] ?? null;
        if (!$errors && is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $u = media_upload_from_files($file);
                $photoId = member_photo_store($u['tmp'], $u['name'], $u['size'], (int) $m['user_id']);
            } catch (UploadException $e) {
                $errors['photo'] = $e->getMessage();
            }
        }
        if ($errors) {
            remember_input($_POST, $errors);
            flash('Please fix the highlighted fields.', 'error');
            redirect('/admin/members/' . $m['id']);
        }
        db_tx(static function () use ($m, $profile, $custom, $email, $type, $fee, $valid, $photoId): void {
            db_update('members', (int) $m['id'], $profile + [
                'membership_type' => $type, 'membership_fee_status' => $fee !== '' ? $fee : null, 'card_valid_until' => $valid !== '' ? $valid : null,
            ] + ($photoId !== null ? ['photo_media_id' => $photoId] : []));
            custom_values_save('member_field_values', 'member_id', (int) $m['id'], $custom);
            q('UPDATE users SET email = ?, display_name = ? WHERE id = ?', [$email, mb_substr($profile['first_name'] . ' ' . $profile['last_name'], 0, 120), $m['user_id']]);
        });
        if ($photoId !== null && $m['photo_media_id']) {
            $old = media_find((int) $m['photo_media_id']);
            if ($old !== null) {
                media_delete_files($old);
                q('DELETE FROM media WHERE id = ?', [$old['id']]);
            }
        }
        audit('update', 'member', (int) $m['id'], member_full_name($profile + $m) . ' by ' . $admin['display_name']);
        flash('Member saved.');
        redirect('/admin/members/' . $m['id']);
    }

    public static function status(array $p): void
    {
        csrf_check();
        require_permission('members');
        $m = self::member($p);
        $action = input('action');
        if ($action === 'suspend' && $m['status'] !== 'suspended') {
            q("UPDATE users SET status = 'suspended' WHERE id = ?", [$m['user_id']]);
            flash('Member suspended: they cannot log in and their card shows as not valid.');
        } elseif ($action === 'activate' && $m['status'] === 'suspended') {
            q("UPDATE users SET status = ? WHERE id = ?", [$m['verified_at'] ? 'active' : 'unverified', $m['user_id']]);
            flash('Member reactivated.');
        } elseif ($action === 'verify' && $m['status'] === 'unverified') {
            account_verify(user_token_create((int) $m['user_id'], 'verify'));
            flash('Email marked as verified: the member is active and has a member number.');
        } else {
            abort(422);
        }
        audit($action, 'member', (int) $m['id'], member_full_name($m));
        redirect('/admin/members/' . $m['id']);
    }

    public static function delete(array $p): void
    {
        csrf_check();
        require_permission('members');
        $m = self::member($p);
        if (input('confirm') !== 'DELETE') {
            flash('Type DELETE to confirm removing this member.', 'error');
            redirect('/admin/members/' . $m['id']);
        }
        account_delete((int) $m['user_id']);
        audit('delete', 'member', (int) $m['id'], member_full_name($m) . ' ' . ($m['member_number'] ?? ''));
        flash(member_full_name($m) . ' was removed. Their member number ' . ($m['member_number'] ?? '') . ' is retired.');
        redirect('/admin/members');
    }

    public static function resetLink(array $p): void
    {
        csrf_check();
        require_permission('members');
        $m = self::member($p);
        $token = create_password_reset((int) $m['user_id']);
        send_template_mail((string) $m['email'], (string) $m['first_name'], 'password_reset', ['reset_url' => base_url('/reset/' . $token)]);
        audit('reset_link', 'member', (int) $m['id'], (string) $m['email']);
        flash('A password link was emailed to ' . $m['email'] . ' (valid for one hour).');
        redirect('/admin/members/' . $m['id']);
    }

    public static function regenerateCode(array $p): void
    {
        csrf_check();
        require_permission('members');
        $m = self::member($p);
        card_regenerate((int) $m['id']);
        audit('regenerate_card', 'member', (int) $m['id'], (string) $m['member_number']);
        flash('New card code created. The old QR code now shows “not valid”; the member should download or print the new card.');
        redirect('/admin/members/' . $m['id']);
    }

    public static function addNote(array $p): void
    {
        csrf_check();
        $admin = require_permission('members');
        $m = self::member($p);
        $body = input('body');
        if ($body === '' || mb_strlen($body) > 2000) {
            flash('Write a note (up to 2,000 characters).', 'error');
            redirect('/admin/members/' . $m['id'] . '#notes');
        }
        db_insert('member_notes', ['member_id' => $m['id'], 'author_id' => $admin['id'], 'body' => $body]);
        flash('Note added.');
        redirect('/admin/members/' . $m['id'] . '#notes');
    }

    /** One member's card (print layout). */
    public static function card(array $p): void
    {
        require_permission('members');
        $m = self::member($p);
        if ($m['member_number'] === null) {
            flash('This member has no number yet (email not verified).', 'error');
            redirect('/admin/members/' . $m['id']);
        }
        render('account/card_print', ['title' => 'Card: ' . member_full_name($m), 'cards' => [card_data($m)], 'mode' => 'cr80'], 'print');
    }

    /** Bulk A4 print sheet (8 per page) for selected ids, or for the current filters. */
    public static function cards(array $p): void
    {
        require_permission('members');
        $ids = array_values(array_filter(array_map('intval', (array) ($_GET['ids'] ?? [])), static fn (int $i): bool => $i > 0));
        if ($ids) {
            $rows = q_all('SELECT m.* FROM members m JOIN users u ON u.id = m.user_id WHERE m.id IN (' . db_placeholders(count($ids)) . ") AND m.member_number IS NOT NULL AND u.status = 'active' ORDER BY m.last_name, m.first_name LIMIT 400", $ids);
        } else {
            [$where, $params] = members_filter_sql(self::filters());
            $rows = q_all("SELECT m.* FROM members m JOIN users u ON u.id = m.user_id WHERE $where AND m.member_number IS NOT NULL AND u.status = 'active' ORDER BY m.last_name, m.first_name LIMIT 400", $params);
        }
        if (!$rows) {
            flash('No active members with a number to print.', 'error');
            redirect('/admin/members');
        }
        media_preload(array_column($rows, 'photo_media_id'));
        render('account/card_print', ['title' => 'Member cards (' . count($rows) . ')', 'cards' => array_map('card_data', $rows), 'mode' => 'a4'], 'print');
    }
}
