<?php
declare(strict_types=1);

/*
 * Membership (§4). Birthdays are here because the birthday-celebrants
 * section (§3.1) and the dashboard share them.
 */

/**
 * Month/day pairs that count as a birthday on the given local days.
 * 29 February is celebrated on 28 February in non-leap years (§4.5).
 * @return list<array{0:int,1:int}>
 */
function birthday_days(string $range, ?DateTimeImmutable $now = null): array
{
    $today = ($now ?? new DateTimeImmutable('now', site_tz()))->setTimezone(site_tz())->setTime(0, 0);
    $n = $range === 'week' ? 7 : 1;
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $d = $today->modify("+$i days");
        $m = (int) $d->format('n');
        $day = (int) $d->format('j');
        $out[] = [$m, $day];
        if ($m === 2 && $day === 28 && $d->format('L') === '0') {
            $out[] = [2, 29];
        }
    }
    return $out;
}

/**
 * Active members who opted in to the celebrants list, birthday within range.
 * Returns display names only (first name + last initial): no year, no photo.
 * @return list<array{name:string, day:int, month:int, initial:string}>
 */
function birthday_celebrants(string $range, ?DateTimeImmutable $now = null): array
{
    $days = birthday_days($range, $now);
    $cond = implode(' OR ', array_fill(0, count($days), '(m.birth_month = ? AND m.birth_day = ?)'));
    $rows = q_all(
        "SELECT m.first_name, m.last_name, m.birth_day, m.birth_month, m.prefs FROM members m JOIN users u ON u.id = m.user_id
          WHERE u.status = 'active' AND ($cond) ORDER BY m.birth_month, m.birth_day, m.first_name LIMIT 200",
        array_merge(...$days)
    );
    $out = [];
    foreach ($rows as $r) {
        if (!member_pref($r, 'celebrants')) {
            continue;
        }
        $last = trim((string) $r['last_name']);
        $out[] = [
            'name' => trim($r['first_name'] . ($last !== '' ? ' ' . mb_substr($last, 0, 1) . '.' : '')),
            'initial' => mb_strtoupper(mb_substr((string) $r['first_name'], 0, 1)),
            'day' => (int) $r['birth_day'], 'month' => (int) $r['birth_month'],
        ];
    }
    return $out;
}

/** Email and privacy preferences (§4.4) with their defaults. */
function member_pref_defaults(): array
{
    return ['newsletters' => false, 'birthday_greeting' => true, 'celebrants' => false];
}

function member_prefs(array $memberRow): array
{
    $p = json_decode((string) ($memberRow['prefs'] ?? ''), true);
    $out = member_pref_defaults();
    foreach ($out as $k => $v) {
        if (is_array($p) && array_key_exists($k, $p)) {
            $out[$k] = (bool) $p[$k];
        }
    }
    return $out;
}

function member_pref(array $memberRow, string $key): bool
{
    return member_prefs($memberRow)[$key] ?? false;
}

// ---- Settings -------------------------------------------------------------------

/** Membership rules and ID format (super admin, Membership settings). */
function member_settings(): array
{
    $d = [
        'prefix' => 'ORG', 'group_prefix' => 'GRP', 'number_format' => '{PREFIX}-{YY}-{0000}',
        'card_months' => 12, 'free_first_n' => 0, 'terms_url' => '/privacy-policy', 'signatory' => 'Secretary',
        'regions' => ['England', 'Scotland', 'Wales', 'Northern Ireland', 'Outside the UK'],
        'genders' => ['Female', 'Male', 'Non-binary', 'I describe myself another way'],
        'types' => ['Standard', 'Life', 'Student', 'Honorary'],
    ];
    $saved = setting('members', []);
    $out = $d;
    foreach ($d as $k => $v) {
        if (is_array($saved) && array_key_exists($k, $saved) && gettype($saved[$k]) === gettype($v)) {
            $out[$k] = $saved[$k];
        }
    }
    return $out;
}

/** "Lines\nof text" => trimmed unique non-empty list. */
function lines_to_list(string $text, int $max = 60, int $maxLen = 80): array
{
    $out = [];
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '' && mb_strlen($line) <= $maxLen && !in_array($line, $out, true)) {
            $out[] = $line;
        }
    }
    return array_slice($out, 0, $max);
}

// ---- Member numbers ---------------------------------------------------------------

/** Problem with a number format, or null. Tokens: {PREFIX} {YY} {YYYY} {0000} (2–8 zeros). */
function member_number_format_problem(string $format): ?string
{
    if (mb_strlen($format) > 30) {
        return 'Keep the format under 30 characters.';
    }
    if (preg_match_all('/\{0{2,8}\}/', $format) !== 1) {
        return 'Include exactly one sequence token such as {0000}.';
    }
    $rest = preg_replace('/\{(PREFIX|YY|YYYY|0{2,8})\}/', '', $format) ?? '';
    if (!preg_match('/^[A-Za-z0-9\/._-]*$/', $rest)) {
        return 'Use letters, numbers, dashes, dots or slashes around the tokens.';
    }
    return null;
}

function member_prefix_problem(string $prefix): ?string
{
    return preg_match('/^[A-Z0-9]{1,8}$/', $prefix) ? null : 'Use 1–8 capital letters or digits.';
}

/** Build a number from the format. */
function member_number_build(string $format, string $prefix, int $year, int $seq): string
{
    return (string) preg_replace_callback('/\{(PREFIX|YY|YYYY|0{2,8})\}/', static function (array $m) use ($prefix, $year, $seq): string {
        return match ($m[1]) {
            'PREFIX' => $prefix,
            'YYYY' => (string) $year,
            'YY' => substr((string) $year, -2),
            default => str_pad((string) $seq, strlen($m[1]), '0', STR_PAD_LEFT),
        };
    }, $format);
}

/**
 * Issue the next number for 'member' or 'group'. Call inside a transaction:
 * the sequence row is locked, and every issued number is recorded so it is
 * never handed out again, even after deletion or a format change.
 */
function member_issue_number(string $scope, ?DateTimeImmutable $now = null): string
{
    if (!db()->inTransaction()) {
        throw new LogicException('member_issue_number() must run inside a transaction');
    }
    $s = member_settings();
    $format = $s['number_format'];
    $prefix = $scope === 'group' ? $s['group_prefix'] : $s['prefix'];
    $year = (int) ($now ?? new DateTimeImmutable('now', site_tz()))->setTimezone(site_tz())->format('Y');
    $period = preg_match('/\{YY(YY)?\}/', $format) ? (string) $year : 'all';
    q('INSERT IGNORE INTO number_sequences (scope, period, last_value) VALUES (?, ?, 0)', [$scope, $period]);
    $seq = (int) q_val('SELECT last_value FROM number_sequences WHERE scope = ? AND period = ? FOR UPDATE', [$scope, $period]);
    for ($i = 0; $i < 1000; $i++) {
        $seq++;
        $number = member_number_build($format, $prefix, $year, $seq);
        // Skip numbers ever issued, and numbers typed in by hand (imports, group records).
        $taken = q_val('SELECT number FROM issued_numbers WHERE number = ?', [$number]) !== null
            || q_val('SELECT id FROM members WHERE member_number = ?', [$number]) !== null
            || q_val('SELECT id FROM groups_orgs WHERE group_number = ?', [$number]) !== null;
        if (!$taken) {
            q('UPDATE number_sequences SET last_value = ? WHERE scope = ? AND period = ?', [$seq, $scope, $period]);
            q('INSERT INTO issued_numbers (number, scope) VALUES (?, ?)', [$number, $scope]);
            return $number;
        }
    }
    throw new RuntimeException('Could not find a free member number.');
}

/** Record a number set by hand (CSV import) so it is never reissued. */
function member_reserve_number(string $number, string $scope): bool
{
    return q('INSERT IGNORE INTO issued_numbers (number, scope) VALUES (?, ?)', [$number, $scope])->rowCount() === 1;
}

// ---- ID card codes -----------------------------------------------------------------

function card_code_new(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < 12; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

function card_verify_url(array $member): string
{
    return base_url('/verify/' . rawurlencode((string) $member['member_number']) . '/' . rawurlencode((string) $member['card_code']));
}

/**
 * Public card check (§4.3): valid / expired / invalid (unknown number, wrong
 * or regenerated code, suspended or unverified account).
 * @return array{state:string, member:?array}
 */
function card_lookup(string $number, string $code, ?string $today = null): array
{
    $m = q_one('SELECT m.*, u.status AS user_status FROM members m JOIN users u ON u.id = m.user_id WHERE m.member_number = ?', [$number]);
    if ($m === null || $m['card_code'] === null || !hash_equals((string) $m['card_code'], $code) || $m['user_status'] !== 'active') {
        return ['state' => 'invalid', 'member' => null];
    }
    $today ??= (new DateTimeImmutable('now', site_tz()))->format('Y-m-d');
    if ($m['card_valid_until'] !== null && (string) $m['card_valid_until'] < $today) {
        return ['state' => 'expired', 'member' => $m];
    }
    return ['state' => 'valid', 'member' => $m];
}

function card_regenerate(int $memberId): string
{
    $code = card_code_new();
    q('UPDATE members SET card_code = ? WHERE id = ?', [$code, $memberId]);
    return $code;
}

// ---- Custom profile fields ----------------------------------------------------------

/** @return list<array> fields for 'member' or 'group', optionally only those members see. */
function custom_fields(string $appliesTo = 'member', bool $memberVisibleOnly = false): array
{
    $rows = q_all('SELECT * FROM custom_fields WHERE applies_to = ?' . ($memberVisibleOnly ? ' AND visible_to_members = 1' : '') . ' ORDER BY sort, id', [$appliesTo]);
    foreach ($rows as &$r) {
        $o = json_decode((string) $r['options'], true);
        $r['options'] = is_array($o) ? array_values(array_filter($o, 'is_string')) : [];
    }
    unset($r);
    return $rows;
}

/**
 * Validate custom field input (name="cf_<key>").
 * @return array{0:array<int,string>,1:array<string,string>} [field id => value, errors by input name]
 */
function custom_fields_validate(array $fields, array $input): array
{
    $values = [];
    $errors = [];
    foreach ($fields as $f) {
        $name = 'cf_' . $f['field_key'];
        $raw = is_string($input[$name] ?? null) ? trim($input[$name]) : '';
        $required = (int) $f['is_required'] === 1;
        switch ($f['type']) {
            case 'checkbox':
                $v = $raw === '1' ? '1' : '0';
                if ($required && $v !== '1') {
                    $errors[$name] = 'Please tick “' . $f['label'] . '”.';
                }
                break;
            case 'select':
                $v = $raw;
                if ($v !== '' && !in_array($v, $f['options'], true)) {
                    $errors[$name] = 'Choose one of the options.';
                    $v = '';
                }
                break;
            case 'date':
                $v = $raw;
                $d = $v !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $v) : null;
                if ($v !== '' && ($d === false || $d->format('Y-m-d') !== $v)) {
                    $errors[$name] = 'Enter a date.';
                    $v = '';
                }
                break;
            default:
                $v = $raw;
                if (mb_strlen($v) > 200) {
                    $errors[$name] = 'Keep this under 200 characters.';
                }
        }
        if ($required && $f['type'] !== 'checkbox' && $v === '' && !isset($errors[$name])) {
            $errors[$name] = $f['label'] . ' is required.';
        }
        $values[(int) $f['id']] = $v;
    }
    return [$values, $errors];
}

/** @param array<int,string> $values */
function custom_values_save(string $table, string $ownerCol, int $ownerId, array $values): void
{
    $table = $table === 'group_field_values' ? 'group_field_values' : 'member_field_values';
    $ownerCol = $ownerCol === 'group_id' ? 'group_id' : 'member_id';
    foreach ($values as $fieldId => $v) {
        q('INSERT INTO ' . $table . ' (' . $ownerCol . ', field_id, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$ownerId, $fieldId, $v]);
    }
}

/** @return array<int,string> field id => value */
function custom_values_for_member(int $memberId): array
{
    $out = [];
    foreach (q_all('SELECT field_id, value FROM member_field_values WHERE member_id = ?', [$memberId]) as $r) {
        $out[(int) $r['field_id']] = (string) $r['value'];
    }
    return $out;
}

function custom_value_display(array $field, string $value): string
{
    return match ($field['type']) {
        'checkbox' => $value === '1' ? 'Yes' : 'No',
        'date' => $value !== '' ? (new DateTimeImmutable($value))->format('j M Y') : '',
        default => $value,
    };
}

// ---- Profile fields (join, account, admin) ------------------------------------------------

/**
 * Validate the standard member profile fields.
 * @return array{0:array,1:array<string,string>}
 */
function member_profile_validate(array $in, bool $forAdmin = false): array
{
    $s = member_settings();
    $str = static fn (string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : '';
    $d = [
        'first_name' => $str('first_name'), 'last_name' => $str('last_name'), 'phone' => $str('phone'),
        'gender' => $str('gender'), 'address' => $str('address'), 'nationality' => $str('nationality'),
        'country' => $str('country'), 'region' => $str('region'), 'city' => $str('city'),
    ];
    $errors = [];
    foreach (['first_name' => 'First name', 'last_name' => 'Last name'] as $k => $label) {
        if ($d[$k] === '' || mb_strlen($d[$k]) > 80) {
            $errors[$k] = 'Enter your ' . strtolower($label) . ' (up to 80 characters).';
        }
    }
    foreach (['phone' => 40, 'address' => 300, 'nationality' => 80, 'country' => 80, 'city' => 80] as $k => $max) {
        if (mb_strlen($d[$k]) > $max) {
            $errors[$k] = "Keep this under $max characters.";
        }
    }
    if ($d['country'] === '' && !$forAdmin) {
        $errors['country'] = 'Enter the country you live in.';
    }
    if ($d['gender'] !== '' && !in_array($d['gender'], $s['genders'], true)) {
        $errors['gender'] = 'Choose one of the options.';
    }
    if ($d['region'] !== '' && !in_array($d['region'], $s['regions'], true)) {
        $errors['region'] = 'Choose one of the options.';
    }
    $day = (int) $str('birth_day');
    $month = (int) $str('birth_month');
    $year = $str('birth_year') === '' ? null : (int) $str('birth_year');
    $thisYear = (int) date('Y');
    if ($day < 1 || $day > 31 || $month < 1 || $month > 12 || !checkdate($month, $day, $year ?? 2000)) {
        $errors['birth_day'] = 'Choose the day and month of your birthday.';
    }
    if ($year !== null && ($year < $thisYear - 120 || $year > $thisYear)) {
        $errors['birth_year'] = 'Enter a four-digit year, or leave it empty.';
    }
    $d['birth_day'] = $day ?: null;
    $d['birth_month'] = $month ?: null;
    $d['birth_year'] = $year;
    foreach (['phone', 'gender', 'address', 'nationality', 'region', 'city'] as $k) {
        $d[$k] = $d[$k] === '' ? null : $d[$k];
    }
    return [$d, $errors];
}

/** Square-crop (centre) and shrink a photo, then store it privately for its owner. */
function member_photo_store(string $tmp, string $name, int $size, int $ownerId, bool $isUpload = true): int
{
    $info = media_validate($tmp, $name, $size, 'image');
    $img = image_orient(image_load($tmp, $info['mime']), exif_orientation($tmp));
    $w = imagesx($img);
    $h = imagesy($img);
    if ($w < 200 || $h < 200) {
        throw new Core\UploadException('The photo is too small: use one at least 200 × 200 pixels.');
    }
    $side = min($w, $h);
    $out = min(800, $side);
    $square = imagecreatetruecolor($out, $out);
    imagecopyresampled($square, $img, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), $out, $out, $side, $side);
    $file = tempnam(sys_get_temp_dir(), 'gphoto');
    imagejpeg($square, $file, 88);
    if ($isUpload && is_uploaded_file($tmp)) {
        unlink($tmp);
    }
    try {
        return media_store($file, 'member-photo.jpg', (int) filesize($file), $ownerId, false, ['visibility' => 'private', 'folder' => 'member-photos', 'kind' => 'image', 'title' => 'Member photo']);
    } finally {
        if (is_file($file)) {
            unlink($file);
        }
    }
}

// ---- Sign-up and verification ---------------------------------------------------------------

const VERIFY_DAYS = 14;

function user_token_create(int $userId, string $purpose, int $days = VERIFY_DAYS): string
{
    $token = bin2hex(random_bytes(32));
    q('DELETE FROM user_tokens WHERE user_id = ? AND purpose = ?', [$userId, $purpose]);
    q('INSERT INTO user_tokens (user_id, purpose, token_hash, expires_at) VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL ' . (int) $days . ' DAY)', [$userId, $purpose, hash('sha256', $token)]);
    return $token;
}

function user_token_find(string $token, string $purpose): ?int
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $id = q_val('SELECT user_id FROM user_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()', [hash('sha256', $token), $purpose]);
    return $id === null ? null : (int) $id;
}

/**
 * Create an unverified member account from validated sign-up data.
 * @return array{user_id:int, member_id:int, token:string}
 */
function member_signup(string $email, string $password, array $profile, array $customValues, bool $newsletters, ?array $photoUpload, bool $isUpload = true): array
{
    $display = trim($profile['first_name'] . ' ' . $profile['last_name']);
    $userId = create_user($email, $password, 'member', $display, 'unverified');
    try {
        $photoId = $photoUpload !== null ? member_photo_store($photoUpload['tmp'], $photoUpload['name'], $photoUpload['size'], $userId, $isUpload) : null;
    } catch (Throwable $e) {
        q('DELETE FROM users WHERE id = ?', [$userId]);
        throw $e;
    }
    return db_tx(static function () use ($userId, $profile, $customValues, $newsletters, $photoId): array {
        $memberId = db_insert('members', $profile + [
            'user_id' => $userId, 'membership_type' => member_settings()['types'][0] ?? 'Standard', 'photo_media_id' => $photoId,
            'prefs' => json_encode(['newsletters' => $newsletters] + member_pref_defaults(), JSON_THROW_ON_ERROR), 'consent_at' => utc_now(),
        ]);
        custom_values_save('member_field_values', 'member_id', $memberId, $customValues);
        return ['user_id' => $userId, 'member_id' => $memberId, 'token' => user_token_create($userId, 'verify')];
    });
}

/**
 * Verify an email (D7: no admin approval): the account becomes active, the
 * member (or group) number is assigned and the card code created.
 * Returns the user id, or null for an unknown/used/expired token.
 */
function account_verify(string $token, ?DateTimeImmutable $now = null): ?int
{
    $userId = user_token_find($token, 'verify');
    if ($userId === null) {
        return null;
    }
    db_tx(static function () use ($userId, $token, $now): void {
        $user = q_one('SELECT * FROM users WHERE id = ? FOR UPDATE', [$userId]);
        q('UPDATE user_tokens SET used_at = UTC_TIMESTAMP() WHERE token_hash = ?', [hash('sha256', $token)]);
        if ($user === null || $user['status'] !== 'unverified') {
            return;
        }
        q("UPDATE users SET status = 'active' WHERE id = ?", [$userId]);
        // Sessions opened before verification (e.g. by whoever signed up with this address) end here.
        user_end_sessions($userId);
        if ($user['role'] === 'group') {
            $g = q_one('SELECT * FROM groups_orgs WHERE user_id = ?', [$userId]);
            if ($g !== null) {
                $number = $g['group_number'] ?: member_issue_number('group', $now);
                q("UPDATE groups_orgs SET group_number = ?, status = 'active', verified_at = UTC_TIMESTAMP() WHERE id = ?", [$number, $g['id']]);
            }
            return;
        }
        $m = q_one('SELECT * FROM members WHERE user_id = ?', [$userId]);
        if ($m === null) {
            return;
        }
        $s = member_settings();
        $number = $m['member_number'] ?: member_issue_number('member', $now);
        $validUntil = card_valid_until(($now ?? new DateTimeImmutable('now', site_tz()))->setTimezone(site_tz()), (int) $s['card_months']);
        $fee = null;
        if ((int) $s['free_first_n'] > 0) {
            $verified = (int) q_val('SELECT COUNT(*) FROM members WHERE verified_at IS NOT NULL');
            $fee = $verified < (int) $s['free_first_n'] ? 'free' : 'due';
        }
        q('UPDATE members SET member_number = ?, card_code = COALESCE(card_code, ?), card_valid_until = COALESCE(card_valid_until, ?), verified_at = UTC_TIMESTAMP(), membership_fee_status = COALESCE(membership_fee_status, ?) WHERE id = ?',
            [$number, card_code_new(), $validUntil, $fee, $m['id']]);
    });
    return $userId;
}

/**
 * "Valid until" date $months after $from, clamped to the end of the month
 * (31 Jan + 1 month = 28/29 Feb, 29 Feb + 12 months = 28 Feb), where PHP's
 * "+N months" would roll over into the next month.
 */
function card_valid_until(DateTimeImmutable $from, int $months): string
{
    $months = max(1, $months);
    $first = $from->setDate((int) $from->format('Y'), (int) $from->format('n'), 1)->modify('+' . $months . ' months');
    $day = min((int) $from->format('j'), (int) $first->format('t'));
    return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), $day)->format('Y-m-d');
}

/** Places left in the "free for the first N members" offer, or null when off. */
function free_places_left(): ?int
{
    $n = (int) member_settings()['free_first_n'];
    if ($n <= 0) {
        return null;
    }
    return max(0, $n - (int) q_val('SELECT COUNT(*) FROM members WHERE verified_at IS NOT NULL'));
}

/** Delete accounts that never verified their email within 14 days (cron). */
function purge_unverified(?string $nowUtc = null): int
{
    $now = $nowUtc ?? utc_now();
    $cutoff = (new DateTimeImmutable($now, utc()))->modify('-' . VERIFY_DAYS . ' days')->format('Y-m-d H:i:s');
    // 14 days after sign-up AND no verification link still usable: a link sent again
    // ("resend", email change) gives the account its full 14 days from that link.
    $ids = array_map('intval', array_column(q_all(
        "SELECT u.id FROM users u WHERE u.status = 'unverified' AND u.role IN ('member','group') AND u.created_at < ?
            AND NOT EXISTS (SELECT 1 FROM user_tokens t WHERE t.user_id = u.id AND t.purpose = 'verify' AND t.used_at IS NULL AND t.expires_at > ?)",
        [$cutoff, $now]
    ), 'id'));
    $n = 0;
    foreach ($ids as $id) {
        // Re-checked under a row lock: someone verifying right now keeps their account.
        $n += account_delete($id, true) ? 1 : 0;
    }
    return $n;
}

/**
 * Remove an account (§12): event registrations are kept but anonymised,
 * comments stay as "former member" (user_id set to NULL), photo files are
 * deleted, and the member number stays retired in issued_numbers.
 */
function account_delete(int $userId, bool $onlyIfUnverified = false): bool
{
    $photos = [];
    $logos = [];
    $deleted = db_tx(static function () use ($userId, $onlyIfUnverified, &$photos, &$logos): bool {
        $user = q_one('SELECT email, status FROM users WHERE id = ? FOR UPDATE', [$userId]);
        if ($user === null || ($onlyIfUnverified && $user['status'] !== 'unverified')) {
            return false;
        }
        $photos = array_map('intval', array_column(q_all('SELECT photo_media_id FROM members WHERE user_id = ? AND photo_media_id IS NOT NULL', [$userId]), 'photo_media_id'));
        // Logos of groups that never verified go with them (verified groups stay listed, logo included).
        $logos = array_map('intval', array_column(q_all('SELECT logo_media_id FROM groups_orgs WHERE user_id = ? AND verified_at IS NULL AND logo_media_id IS NOT NULL', [$userId]), 'logo_media_id'));
        // Emails still waiting in the queue for this address are dropped with the account.
        $email = (string) $user['email'];
        if ($email !== '') {
            q("DELETE FROM email_queue WHERE to_email = ? AND status = 'queued'", [$email]);
            q("DELETE FROM campaign_recipients WHERE email = ? AND status = 'queued'", [$email]);
            // Guest registrations made with the same address (before joining, or logged out) are anonymised too.
            q("UPDATE event_registrations SET name = 'Former member', email = '', phone = '' WHERE user_id IS NULL AND email = ?", [$email]);
        }
        q("UPDATE event_registrations SET name = 'Former member', email = '', phone = '', user_id = NULL WHERE user_id = ?", [$userId]);
        // A group that never verified was never listed: remove it with the account.
        q('DELETE FROM groups_orgs WHERE user_id = ? AND verified_at IS NULL', [$userId]);
        q('UPDATE groups_orgs SET user_id = NULL WHERE user_id = ?', [$userId]);
        q('DELETE FROM users WHERE id = ?', [$userId]);
        return true;
    });
    if (!$deleted) {
        return false;
    }
    foreach ($photos as $mediaId) {
        $m = media_find($mediaId);
        if ($m !== null) {
            media_delete_files($m);
            q('DELETE FROM media WHERE id = ?', [$mediaId]);
        }
    }
    foreach ($logos as $mediaId) {
        media_forget($mediaId);
        if (media_find($mediaId) !== null && media_usage($mediaId) === []) {
            media_delete($mediaId);
        }
    }
    return true;
}

/** Everything held about a member, for the JSON data export (§4.4, §11). */
function member_export(int $userId): array
{
    $user = q_one('SELECT id, email, role, status, display_name, last_login_at, created_at FROM users WHERE id = ?', [$userId]);
    $m = q_one('SELECT * FROM members WHERE user_id = ?', [$userId]);
    $custom = [];
    if ($m !== null) {
        foreach (q_all('SELECT f.label, f.type, v.value FROM member_field_values v JOIN custom_fields f ON f.id = v.field_id WHERE v.member_id = ?', [$m['id']]) as $r) {
            $custom[$r['label']] = $r['value'];
        }
        $m['prefs'] = member_prefs($m);
        unset($m['card_code'], $m['user_id']);
    }
    $group = q_one('SELECT name, group_number, contact_person, email, phone, address, country, website, status, created_at FROM groups_orgs WHERE user_id = ?', [$userId]);
    return [
        'exported_at' => gmdate('c'),
        'site' => (string) setting('site.name'),
        'account' => $user,
        'member' => $m,
        'custom_fields' => $custom,
        'group' => $group,
        'event_registrations' => q_all('SELECT e.title AS event, r.name, r.email, r.phone, r.quantity, r.status, r.created_at FROM event_registrations r JOIN events e ON e.id = r.event_id WHERE r.user_id = ? ORDER BY r.created_at', [$userId]),
        'likes' => q_all('SELECT item_type, item_id, created_at FROM likes WHERE user_id = ?', [$userId]),
        'comments' => q_all('SELECT item_type, item_id, body, status, created_at FROM comments WHERE user_id = ?', [$userId]),
        'jobs' => q_all('SELECT title, organisation, status, created_at FROM jobs WHERE user_id = ?', [$userId]),
    ];
}

/** The member row for a user (null for staff and groups). */
function member_for_user(int $userId): ?array
{
    return q_one('SELECT * FROM members WHERE user_id = ?', [$userId]);
}

function member_full_name(array $m): string
{
    return trim($m['first_name'] . ' ' . $m['last_name']);
}

// ---- Group accounts (§4.2) ---------------------------------------------------------------------

/**
 * Create an unverified group account; it is listed once the email is verified.
 * @return array{user_id:int, group_id:int, token:string}
 */
function group_signup(string $email, string $password, array $group, array $customValues, ?int $logoMediaId): array
{
    $userId = create_user($email, $password, 'group', $group['name'], 'unverified');
    return db_tx(static function () use ($userId, $group, $customValues, $logoMediaId, $email): array {
        $groupId = db_insert('groups_orgs', [
            'user_id' => $userId, 'name' => $group['name'], 'slug' => unique_slug('groups_orgs', slugify($group['name']) ?: 'group'),
            'contact_person' => $group['contact_person'], 'email' => strtolower($email), 'phone' => $group['phone'], 'address' => $group['address'],
            'country' => $group['country'], 'logo_media_id' => $logoMediaId, 'status' => 'hidden', 'sort' => 0,
        ]);
        custom_values_save('group_field_values', 'group_id', $groupId, $customValues);
        return ['user_id' => $userId, 'group_id' => $groupId, 'token' => user_token_create($userId, 'verify')];
    });
}

// ---- Admin list, CSV export and import --------------------------------------------------------------

/**
 * WHERE clause for the admin Members list (§7): q, status, type, region, custom field.
 * @return array{0:string,1:list<mixed>}
 */
function members_filter_sql(array $f): array
{
    $where = ["u.role = 'member'"];
    $params = [];
    $q = trim((string) ($f['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $where[] = "(CONCAT(m.first_name, ' ', m.last_name) LIKE ? OR u.email LIKE ? OR m.member_number LIKE ? OR m.phone LIKE ?)";
        array_push($params, $like, $like, $like, $like);
    }
    $status = (string) ($f['status'] ?? '');
    if (in_array($status, ['unverified', 'active', 'suspended'], true)) {
        $where[] = 'u.status = ?';
        $params[] = $status;
    } elseif ($status === 'deletion') {
        $where[] = 'm.deletion_requested_at IS NOT NULL';
    } elseif ($status === 'expired') {
        $where[] = "u.status = 'active' AND m.card_valid_until < CURDATE()";
    }
    foreach (['type' => 'membership_type', 'region' => 'region'] as $k => $col) {
        if (($f[$k] ?? '') !== '') {
            $where[] = 'm.' . $col . ' = ?';
            $params[] = (string) $f[$k];
        }
    }
    $field = (int) ($f['field'] ?? 0);
    if ($field > 0 && ($f['value'] ?? '') !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM member_field_values v WHERE v.member_id = m.id AND v.field_id = ? AND v.value = ?)';
        array_push($params, $field, (string) $f['value']);
    }
    return [implode(' AND ', $where), $params];
}

const MEMBER_CSV_COLUMNS = ['member_number', 'email', 'first_name', 'last_name', 'phone', 'birth_day', 'birth_month', 'birth_year', 'gender', 'address',
    'nationality', 'country', 'region', 'city', 'membership_type', 'status', 'card_valid_until', 'joined'];

/** Rows for the members CSV (header first). Birth year included: admins only. */
function members_csv_rows(array $filters): array
{
    [$where, $params] = members_filter_sql($filters);
    $fields = custom_fields('member');
    $rows = [array_merge(MEMBER_CSV_COLUMNS, array_map(static fn (array $f): string => 'cf_' . $f['field_key'], $fields))];
    foreach (q_all("SELECT m.*, u.email, u.status, u.created_at AS joined FROM members m JOIN users u ON u.id = m.user_id WHERE $where ORDER BY m.last_name, m.first_name", $params) as $m) {
        $vals = custom_values_for_member((int) $m['id']);
        $line = [];
        foreach (MEMBER_CSV_COLUMNS as $c) {
            $line[] = (string) ($m[$c] ?? '');
        }
        foreach ($fields as $f) {
            $line[] = $vals[(int) $f['id']] ?? '';
        }
        $rows[] = array_map('csv_safe', $line);
    }
    return $rows;
}

/** Neutralise spreadsheet formulas in exported cells. */
function csv_safe(string $v): string
{
    return $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) && !preg_match('/^-?\d+(\.\d+)?$/', $v) ? "'" . $v : $v;
}

/**
 * Undo csv_safe() on import: drop one leading ' when the rest is a value
 * csv_safe() would have escaped (so "'+44 7700" comes back as "+44 7700",
 * but a name like "'Ofa" is kept as typed).
 */
function csv_unsafe(string $v): string
{
    if (str_starts_with($v, "'")) {
        $rest = substr($v, 1);
        if ($rest !== '' && csv_safe($rest) === "'" . $rest) {
            return $rest;
        }
    }
    return $v;
}

/** Profile columns to update from an import row: only those whose CSV column is present. */
function member_import_changes(array $profile, array $header): array
{
    $present = array_flip($header);
    $out = [];
    foreach ($profile as $col => $value) {
        // A birthday needs its day and month columns together.
        $needs = in_array($col, ['birth_day', 'birth_month'], true) ? ['birth_day', 'birth_month'] : [$col];
        if (array_diff_key(array_flip($needs), $present) === []) {
            $out[$col] = $value;
        }
    }
    return $out;
}

/**
 * Import members from CSV (header row with column names as in the export;
 * email, first_name and last_name required). New rows become active members
 * with a number (unless member_number is given) and a random password: they
 * use "Forgot password" (or the optional welcome email) to log in.
 * Existing emails are updated when $update is true, otherwise skipped.
 * @return array{created:int, updated:int, skipped:int, errors:list<string>, new_user_ids:list<int>}
 */
function members_import_csv(string $path, bool $update = false): array
{
    $report = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [], 'new_user_ids' => []];
    $fh = fopen($path, 'r');
    if ($fh === false) {
        $report['errors'][] = 'The file could not be read.';
        return $report;
    }
    $header = fgetcsv($fh, 0, ',', '"', '');
    if (!is_array($header)) {
        $report['errors'][] = 'The file is empty.';
        return $report;
    }
    $header = array_map(static fn ($h): string => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h) ?? '')), $header);
    foreach (['email', 'first_name', 'last_name'] as $need) {
        if (!in_array($need, $header, true)) {
            $report['errors'][] = "Missing column: $need.";
        }
    }
    if ($report['errors']) {
        return $report;
    }
    $s = member_settings();
    $fieldsByKey = [];
    foreach (custom_fields('member') as $f) {
        $fieldsByKey['cf_' . $f['field_key']] = $f;
    }
    $line = 1;
    while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        $line++;
        if ($line > 5001) {
            $report['errors'][] = 'Only the first 5,000 rows were imported.';
            break;
        }
        if ($row === [null] || count(array_filter($row, static fn ($v): bool => trim((string) $v) !== '')) === 0) {
            continue;
        }
        $in = [];
        foreach ($header as $i => $col) {
            $in[$col] = csv_unsafe(trim((string) ($row[$i] ?? '')));
        }
        $email = strtolower($in['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $report['errors'][] = "Row $line: invalid email “{$in['email']}”.";
            $report['skipped']++;
            continue;
        }
        if (!isset($in['birth_day']) || $in['birth_day'] === '') {
            $in['birth_day'] = '1';
            $in['birth_month'] = $in['birth_month'] ?? '1';
            $noBirthday = true;
        }
        [$profile, $errors] = member_profile_validate($in, true);
        if (!empty($noBirthday)) {
            $profile['birth_day'] = null;
            $profile['birth_month'] = null;
            unset($errors['birth_day']);
        }
        $noBirthday = false;
        [$custom, $cErrors] = custom_fields_validate(array_values(array_filter($fieldsByKey, static fn (array $f, string $k): bool => array_key_exists($k, $in), ARRAY_FILTER_USE_BOTH)), $in);
        $errors += $cErrors;
        $type = $in['membership_type'] ?? '';
        if ($type !== '' && !in_array($type, $s['types'], true)) {
            $errors['membership_type'] = 'unknown membership type';
        }
        $number = $in['member_number'] ?? '';
        if ($number !== '' && !preg_match('/^[A-Za-z0-9\/._-]{1,40}$/', $number)) {
            $errors['member_number'] = 'invalid member number';
        }
        if ($errors) {
            $report['errors'][] = "Row $line: " . implode('; ', array_map(static fn ($k, $v): string => "$k: $v", array_keys($errors), $errors));
            $report['skipped']++;
            continue;
        }
        $existing = q_one('SELECT u.id AS user_id, u.role, m.id AS member_id FROM users u LEFT JOIN members m ON m.user_id = u.id WHERE u.email = ?', [$email]);
        try {
            if ($existing !== null) {
                if (!$update || $existing['role'] !== 'member' || $existing['member_id'] === null) {
                    $report['skipped']++;
                    continue;
                }
                // Only the columns the file has are changed: a CSV with just email + names
                // must not wipe phone numbers, birthdays or addresses.
                $changes = member_import_changes($profile, $header);
                db_tx(static function () use ($existing, $changes, $type, $custom): void {
                    db_update('members', (int) $existing['member_id'], $changes + ($type !== '' ? ['membership_type' => $type] : []));
                    custom_values_save('member_field_values', 'member_id', (int) $existing['member_id'], $custom);
                });
                $report['updated']++;
                continue;
            }
            $userId = create_user($email, bin2hex(random_bytes(16)), 'member', trim($profile['first_name'] . ' ' . $profile['last_name']), 'active');
            db_tx(static function () use ($userId, $profile, $type, $custom, $number, $in, $s): void {
                if ($number !== '') {
                    if (!member_reserve_number($number, 'member')) {
                        throw new DomainException("member number $number was already issued");
                    }
                } else {
                    $number = member_issue_number('member');
                }
                $valid = ($in['card_valid_until'] ?? '') !== '' && DateTimeImmutable::createFromFormat('!Y-m-d', $in['card_valid_until']) !== false
                    ? $in['card_valid_until'] : card_valid_until(new DateTimeImmutable('now', site_tz()), (int) $s['card_months']);
                $memberId = db_insert('members', $profile + [
                    'user_id' => $userId, 'member_number' => $number, 'membership_type' => $type !== '' ? $type : ($s['types'][0] ?? 'Standard'),
                    'card_code' => card_code_new(), 'card_valid_until' => $valid, 'verified_at' => utc_now(), 'prefs' => json_encode(member_pref_defaults(), JSON_THROW_ON_ERROR),
                ]);
                custom_values_save('member_field_values', 'member_id', $memberId, $custom);
            });
            $report['created']++;
            $report['new_user_ids'][] = $userId;
        } catch (Throwable $e) {
            if (isset($userId) && $existing === null) {
                q('DELETE FROM users WHERE id = ? AND id NOT IN (SELECT user_id FROM members)', [$userId]);
            }
            $report['errors'][] = "Row $line: " . ($e instanceof DomainException || $e instanceof InvalidArgumentException ? $e->getMessage() : 'could not be saved');
            $report['skipped']++;
        }
        unset($userId);
    }
    fclose($fh);
    return $report;
}

// ---- Changing the login email (members and groups) ---------------------------

const EMAIL_CHANGE_DAYS = 2;

/** Partly hidden address for notices sent to the old one: am***@example.org */
function email_mask(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    return mb_substr($local, 0, 2) . str_repeat('*', max(3, mb_strlen($local) - 2)) . '@' . $domain;
}

/** Problem with a requested new address, or null. */
function email_change_problem(int $userId, string $newEmail): ?string
{
    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($newEmail) > 190) {
        return 'Enter a valid email address.';
    }
    $current = (string) q_val('SELECT email FROM users WHERE id = ?', [$userId]);
    if ($newEmail === $current) {
        return 'That is already your email address.';
    }
    if (q_val('SELECT id FROM users WHERE email = ? AND id <> ?', [$newEmail, $userId]) !== null) {
        // Same wording whether or not the address exists elsewhere would hide accounts, but a
        // logged-in member needs to know why it failed; they had to give their password first.
        return 'Another account already uses that address.';
    }
    return null;
}

/**
 * Start an email change. Verified accounts keep the old address until the
 * link sent to the new one is confirmed (and the old address is told).
 * Unverified accounts switch at once and get a fresh verification link,
 * so someone who mistyped their address at sign-up can fix it.
 * @return string 'pending' or 'changed'
 */
function email_change_request(array $user, string $newEmail): string
{
    $newEmail = strtolower(trim($newEmail));
    if (($p = email_change_problem((int) $user['id'], $newEmail)) !== null) {
        throw new InvalidArgumentException($p);
    }
    $name = $user['role'] === 'group'
        ? (string) q_val('SELECT contact_person FROM groups_orgs WHERE user_id = ?', [$user['id']])
        : (string) (member_for_user((int) $user['id'])['first_name'] ?? $user['display_name']);
    if ($user['status'] === 'unverified') {
        q('UPDATE users SET email = ?, pending_email = NULL WHERE id = ?', [$newEmail, $user['id']]);
        user_end_sessions((int) $user['id']);
        send_template_mail($newEmail, $name, 'verify_email', ['verify_url' => base_url('/join/verify/' . user_token_create((int) $user['id'], 'verify')), 'days' => VERIFY_DAYS, 'account_label' => $user['role'] === 'group' ? 'your group account' : 'your membership']);
        return 'changed';
    }
    q('UPDATE users SET pending_email = ? WHERE id = ?', [$newEmail, $user['id']]);
    $token = user_token_create((int) $user['id'], 'email_change', EMAIL_CHANGE_DAYS);
    send_template_mail($newEmail, $name, 'email_change', ['new_email' => $newEmail, 'confirm_url' => base_url('/account/email/confirm/' . $token), 'days' => (string) EMAIL_CHANGE_DAYS]);
    send_template_mail((string) $user['email'], $name, 'email_change_notice', ['new_email' => email_mask($newEmail)]);
    return 'pending';
}

/** Account id for a usable email-change link, or null. */
function email_change_find(string $token): ?int
{
    $id = user_token_find($token, 'email_change');
    return $id !== null && q_val('SELECT pending_email FROM users WHERE id = ?', [$id]) !== null ? $id : null;
}

/**
 * Confirm the change: the pending address becomes the login. Outstanding
 * password reset links (sent to the old address) stop working.
 * @return array{id:int, old:string, new:string}|null
 */
function email_change_confirm(string $token): ?array
{
    $id = email_change_find($token);
    if ($id === null) {
        return null;
    }
    return db_tx(static function () use ($id, $token): ?array {
        $u = q_one('SELECT id, email, pending_email FROM users WHERE id = ? FOR UPDATE', [$id]);
        $new = (string) ($u['pending_email'] ?? '');
        if ($new === '' || q_val('SELECT id FROM users WHERE email = ? AND id <> ?', [$new, $id]) !== null) {
            q('UPDATE users SET pending_email = NULL WHERE id = ?', [$id]);
            return null;
        }
        q('UPDATE users SET email = ?, pending_email = NULL WHERE id = ?', [$new, $id]);
        user_end_sessions($id);
        q('UPDATE user_tokens SET used_at = UTC_TIMESTAMP() WHERE token_hash = ?', [hash('sha256', $token)]);
        q('DELETE FROM password_resets WHERE user_id = ?', [$id]);
        return ['id' => $id, 'old' => (string) $u['email'], 'new' => $new];
    });
}

/**
 * Group logos uploaded at sign-up that no group uses any more (sign-up that
 * failed half-way, or a group purged before an earlier fix). Admin uploads
 * are never touched: only files with no uploader or a group uploader.
 */
function purge_orphan_group_logos(?string $nowUtc = null): int
{
    $cutoff = (new DateTimeImmutable($nowUtc ?? utc_now(), utc()))->modify('-1 day')->format('Y-m-d H:i:s');
    $ids = array_map('intval', array_column(q_all(
        "SELECT m.id FROM media m LEFT JOIN users u ON u.id = m.uploaded_by
          WHERE m.folder = 'groups' AND m.created_at < ? AND (m.uploaded_by IS NULL OR u.role = 'group')
            AND NOT EXISTS (SELECT 1 FROM groups_orgs g WHERE g.logo_media_id = m.id)",
        [$cutoff]
    ), 'id'));
    $n = 0;
    foreach ($ids as $id) {
        if (media_usage($id) === []) {
            media_delete($id);
            $n++;
        }
    }
    return $n;
}
