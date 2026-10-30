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

/** The newsletter card comes back at most this often after it was dismissed (A2). */
const NEWSLETTER_PROMPT_DAYS = 90;

/**
 * Should the dashboard ask this member to switch news emails on? Only when
 * they have not opted in, did not dismiss the card in the last 90 days, and
 * their address is not suppressed for a complaint or bounce (they would not
 * get the emails; the bounce banner asks them to fix the address instead).
 */
function newsletter_prompt_due(array $member, string $email, ?int $now = null): bool
{
    if (member_pref($member, 'newsletters')) {
        return false;
    }
    $dismissed = $member['optin_prompt_dismissed_at'] ?? null;
    if ($dismissed !== null && strtotime($dismissed . ' UTC') > ($now ?? time()) - NEWSLETTER_PROMPT_DAYS * 86400) {
        return false;
    }
    $sup = email_suppression($email);
    return $sup === null || $sup['reason'] === 'unsubscribe';
}

/** Switch news emails on (explicit consent): also lifts the member's own unsubscribe. */
function newsletter_opt_in(array $member, string $email): void
{
    $prefs = member_prefs($member);
    $prefs['newsletters'] = true;
    q('UPDATE members SET prefs = ?, optin_prompt_dismissed_at = NULL WHERE id = ?', [json_encode($prefs, JSON_THROW_ON_ERROR), $member['id']]);
    email_resubscribe($email);
}

// ---- Settings -------------------------------------------------------------------

/** Membership rules and ID format (super admin, Membership settings). */
function member_settings(): array
{
    $d = [
        'prefix' => 'ORG', 'group_prefix' => 'GRP', 'number_format' => '{PREFIX}-{YY}-{0000}',
        'card_months' => 12, 'free_first_n' => 0, 'terms_url' => '/privacy-policy', 'signatory' => 'Secretary',
        'genders' => ['Female', 'Male', 'Non-binary', 'I describe myself another way'],
        'types' => ['Standard', 'Life', 'Student', 'Honorary'],
        // A2: whether the sign-up newsletter box starts ticked. Off by default: a pre-ticked
        // box is not valid consent under UK GDPR/PECR.
        'newsletter_default' => false,
        // C4: location lines on the ID card (up to two lines fit, shared with custom fields).
        'card_locations' => [],
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

/** Location lines the ID card can show (Membership settings). @return array<string,array{0:string,1:string,2:string}> key => [label, slot, level] */
function card_location_options(): array
{
    $o = geo_origin_settings();
    $out = [
        'res_country' => ['Country of residence', 'residence', 'country'],
        'res_admin1' => ['Region or state of residence', 'residence', 'admin1'],
        'res_city' => ['Town or city', 'residence', 'locality'],
        'nat_country' => ['Nationality', 'nationality', 'country'],
    ];
    if ($o['enabled']) {
        $out['origin_country'] = [$o['country_label'], 'origin', 'country'];
        $out['origin_admin1'] = [$o['l1_label'] !== '' ? $o['l1_label'] : 'State of origin', 'origin', 'admin1'];
        $out['origin_admin2'] = [$o['l2_label'] !== '' ? $o['l2_label'] : 'Local area of origin', 'origin', 'admin2'];
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
    q('INSERT IGNORE INTO number_sequences (scope, period, `last_value`) VALUES (?, ?, 0)', [$scope, $period]);
    $seq = (int) q_val('SELECT `last_value` FROM number_sequences WHERE scope = ? AND period = ? FOR UPDATE', [$scope, $period]);
    for ($i = 0; $i < 1000; $i++) {
        $seq++;
        $number = member_number_build($format, $prefix, $year, $seq);
        // Skip numbers ever issued, and numbers typed in by hand (imports, group records).
        $taken = q_val('SELECT number FROM issued_numbers WHERE number = ?', [$number]) !== null
            || q_val('SELECT id FROM members WHERE member_number = ?', [$number]) !== null
            || q_val('SELECT id FROM groups_orgs WHERE group_number = ?', [$number]) !== null;
        if (!$taken) {
            q('UPDATE number_sequences SET `last_value` = ? WHERE scope = ? AND period = ?', [$seq, $scope, $period]);
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
 * Validate custom field input (name="cf_<key>"; location fields use
 * cf_<key>_country, _state, _area...). Location values are returned by slot
 * (cf<id>) as the third element; their display text is the stored value.
 * @return array{0:array<int,string>,1:array<string,string>,2:array<string,?array>} [field id => value, errors by input name, locations]
 */
function custom_fields_validate(array $fields, array $input): array
{
    $values = [];
    $errors = [];
    $locations = [];
    foreach ($fields as $f) {
        $name = 'cf_' . $f['field_key'];
        $raw = is_string($input[$name] ?? null) ? trim($input[$name]) : '';
        $required = (int) $f['is_required'] === 1;
        if ($f['type'] === 'location') {
            [$loc, $lErrors] = geo_validate($input, $name, custom_field_location_config($f));
            $errors += $lErrors;
            $locations['cf' . $f['id']] = $loc;
            $values[(int) $f['id']] = mb_substr(geo_display($loc), 0, 500);
            continue;
        }
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
    return [$values, $errors, $locations];
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
 * Validate the standard member profile fields. Locations (C4) come back as
 * the third element (slot => location) and their display names are copied
 * into the snapshot columns (country, region, city, nationality).
 * @return array{0:array,1:array<string,string>,2:array<string,?array>}
 */
function member_profile_validate(array $in, bool $forAdmin = false): array
{
    $s = member_settings();
    $str = static fn (string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : '';
    $d = [
        'first_name' => $str('first_name'), 'last_name' => $str('last_name'), 'phone' => $str('phone'),
        'gender' => $str('gender'), 'address' => $str('address'),
    ];
    $errors = [];
    foreach (['first_name' => 'First name', 'last_name' => 'Last name'] as $k => $label) {
        if ($d[$k] === '' || mb_strlen($d[$k]) > 80) {
            $errors[$k] = 'Enter your ' . strtolower($label) . ' (up to 80 characters).';
        }
    }
    foreach (['phone' => 40, 'address' => 300] as $k => $max) {
        if (mb_strlen($d[$k]) > $max) {
            $errors[$k] = "Keep this under $max characters.";
        }
    }
    if ($d['gender'] !== '' && !in_array($d['gender'], $s['genders'], true)) {
        $errors['gender'] = 'Choose one of the options.';
    }
    $locations = [];
    foreach (member_location_configs($forAdmin) as $slot => $cfg) {
        [$loc, $lErrors] = geo_validate($in, geo_prefix($slot), $cfg);
        $locations[$slot] = $loc;
        $errors += $lErrors;
    }
    $d += member_location_snapshot($locations);
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
    foreach (['phone', 'gender', 'address'] as $k) {
        $d[$k] = $d[$k] === '' ? null : $d[$k];
    }
    return [$d, $errors, $locations];
}

/** Form values for a member's stored locations (all slots, custom location fields too). */
function member_location_form_values(int $memberId): array
{
    $out = [];
    $locs = member_locations($memberId);
    foreach (GEO_SLOTS as $slot) {
        $out += geo_form_values($locs[$slot] ?? null, geo_prefix($slot));
    }
    foreach (custom_fields('member') as $f) {
        if ($f['type'] === 'location') {
            $out += geo_form_values($locs['cf' . $f['id']] ?? null, geo_prefix('cf', (string) $f['field_key']));
        }
    }
    return $out;
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
function member_signup(string $email, string $password, array $profile, array $customValues, bool $newsletters, ?array $photoUpload, bool $isUpload = true, array $locations = []): array
{
    $display = trim($profile['first_name'] . ' ' . $profile['last_name']);
    $userId = create_user($email, $password, 'member', $display, 'unverified');
    try {
        $photoId = $photoUpload !== null ? member_photo_store($photoUpload['tmp'], $photoUpload['name'], $photoUpload['size'], $userId, $isUpload) : null;
    } catch (Throwable $e) {
        q('DELETE FROM users WHERE id = ?', [$userId]);
        throw $e;
    }
    return db_tx(static function () use ($userId, $profile, $customValues, $newsletters, $photoId, $locations): array {
        $memberId = db_insert('members', $profile + [
            'user_id' => $userId, 'membership_type' => member_settings()['types'][0] ?? 'Standard', 'photo_media_id' => $photoId,
            'prefs' => json_encode(['newsletters' => $newsletters] + member_pref_defaults(), JSON_THROW_ON_ERROR), 'consent_at' => utc_now(),
        ]);
        custom_values_save('member_field_values', 'member_id', $memberId, $customValues);
        member_locations_save($memberId, $locations);
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
    // D2: open dues that apply to people who join later.
    $memberId = q_val("SELECT m.id FROM members m JOIN users u ON u.id = m.user_id WHERE m.user_id = ? AND u.status = 'active'", [$userId]);
    if ($memberId !== null) {
        payment_charges_for_new_member((int) $memberId);
    }
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
    $messageMedia = [];
    $deleted = db_tx(static function () use ($userId, $onlyIfUnverified, &$photos, &$logos, &$messageMedia): bool {
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
        // C3: their messages lose their content ("Former member" · "message deleted").
        $messageMedia = msg_account_purge($userId);
        // D2: payment records are kept, anonymised ("Former member #<number>").
        payments_account_purge($userId);
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
    foreach ($messageMedia as $mediaId) {
        msg_delete_media($mediaId);
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
        // C3: the messages this person sent (other people's messages are theirs).
        'messages' => msg_export($userId),
        'message_notifications' => (int) q_val('SELECT message_emails FROM users WHERE id = ?', [$userId]) === 1,
        'findable_in_messages' => (int) q_val('SELECT message_findable FROM users WHERE id = ?', [$userId]) === 1,
        // D2: dues and payment records.
        'payments' => payments_export($userId),
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
    // C4: location filter "slot|CC|admin1|admin2".
    if (($f['loc'] ?? '') !== '' && ($loc = geo_filter_decode((string) $f['loc'])) !== null) {
        [$cond, $p] = geo_filter_sql($loc);
        $where[] = $cond;
        array_push($params, ...$p);
    }
    $field = (int) ($f['field'] ?? 0);
    if ($field > 0 && ($f['value'] ?? '') !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM member_field_values v WHERE v.member_id = m.id AND v.field_id = ? AND v.value = ?)';
        array_push($params, $field, (string) $f['value']);
    }
    return [implode(' AND ', $where), $params];
}

const MEMBER_CSV_COLUMNS = ['member_number', 'email', 'first_name', 'last_name', 'phone', 'birth_day', 'birth_month', 'birth_year', 'gender', 'address',
    'nationality_code', 'nationality', 'country_code', 'country', 'region_code', 'region', 'city',
    'origin_country_code', 'origin_country', 'origin_state_code', 'origin_state', 'origin_area_code', 'origin_area',
    'membership_type', 'status', 'card_valid_until', 'joined'];

/**
 * CSV columns of each location slot: [country code, country name, level-1 code, level-1 name,
 * level-2 code, level-2 name, city] (null = the slot has no such column).
 */
const MEMBER_CSV_LOCATIONS = [
    'residence' => ['country_code', 'country', 'region_code', 'region', null, null, 'city'],
    'nationality' => ['nationality_code', 'nationality', null, null, null, null, null],
    'origin' => ['origin_country_code', 'origin_country', 'origin_state_code', 'origin_state', 'origin_area_code', 'origin_area', null],
];

/** "NG.54.12345" for a location (custom location fields in CSV files). */
function geo_code_path(?array $loc): string
{
    if ($loc === null || ($loc['country_code'] ?? null) === null) {
        return '';
    }
    return implode('.', array_filter([(string) $loc['country_code'], (string) ($loc['admin1_code'] ?? ''), (string) ($loc['admin2_code'] ?? '')], static fn (string $s): bool => $s !== ''));
}

/** Rows for the members CSV (header first). Birth year included: admins only. */
function members_csv_rows(array $filters): array
{
    [$where, $params] = members_filter_sql($filters);
    $fields = custom_fields('member');
    $header = MEMBER_CSV_COLUMNS;
    foreach ($fields as $f) {
        $header[] = 'cf_' . $f['field_key'];
        if ($f['type'] === 'location') {
            $header[] = 'cf_' . $f['field_key'] . '_code';
        }
    }
    $rows = [$header];
    foreach (q_all("SELECT m.*, u.email, u.status, u.created_at AS joined FROM members m JOIN users u ON u.id = m.user_id WHERE $where ORDER BY m.last_name, m.first_name", $params) as $m) {
        $vals = custom_values_for_member((int) $m['id']);
        $locs = member_locations((int) $m['id']);
        $cells = [];
        foreach (MEMBER_CSV_LOCATIONS as $slot => $cols) {
            $loc = $locs[$slot] ?? null;
            foreach ([(string) ($loc['country_code'] ?? ''), geo_display($loc, 'country'), (string) ($loc['admin1_code'] ?? ''), (string) ($loc['admin1_name'] ?? ''),
                (string) ($loc['admin2_code'] ?? ''), (string) ($loc['admin2_name'] ?? ''), (string) ($loc['locality'] ?? '')] as $i => $v) {
                if ($cols[$i] !== null) {
                    $cells[$cols[$i]] = $v;
                }
            }
            if ($loc === null && $slot !== 'origin') {
                // Members not upgraded yet (or typed text only): fall back to the snapshot columns.
                foreach ($slot === 'residence' ? ['country', 'region', 'city'] : ['nationality'] as $c) {
                    $cells[$c] = (string) ($m[$c] ?? '');
                }
            }
        }
        $line = [];
        foreach (MEMBER_CSV_COLUMNS as $c) {
            $line[] = (string) ($cells[$c] ?? $m[$c] ?? '');
        }
        foreach ($fields as $f) {
            $line[] = $vals[(int) $f['id']] ?? '';
            if ($f['type'] === 'location') {
                $line[] = geo_code_path($locs['cf' . $f['id']] ?? null);
            }
        }
        $rows[] = array_map('csv_safe', $line);
    }
    return $rows;
}

/**
 * Turn the location columns of an import row into form input for
 * geo_validate() (codes win over names; names are matched like the upgrade
 * does). Levels without a column keep the member's current value (update
 * mode), so a file with only a "city" column does not clear the country.
 * Returns the input and the slots the file has columns for.
 * @param array<string,array> $current the member's stored locations (update mode)
 * @return array{0:array<string,string>,1:list<string>}
 */
function member_import_location_input(array $in, array $header, array $current = []): array
{
    $out = [];
    $slots = [];
    $present = array_flip($header);
    $has = static fn (?string $c): bool => $c !== null && isset($present[$c]);
    foreach (MEMBER_CSV_LOCATIONS as $slot => [$ccCol, $nameCol, $a1Code, $a1Name, $a2Code, $a2Name, $cityCol]) {
        if (!$has($ccCol) && !$has($nameCol) && !$has($a1Code) && !$has($a1Name) && !$has($a2Code) && !$has($a2Name) && !$has($cityCol)) {
            continue;
        }
        $slots[] = $slot;
        $p = geo_prefix($slot);
        $cur = $current[$slot] ?? geo_empty();
        $get = static fn (?string $c): string => $c !== null ? trim((string) ($in[$c] ?? '')) : '';
        // Country
        if ($has($ccCol) || $has($nameCol)) {
            $cc = strtoupper($get($ccCol));
            $cc = geo_country_exists($cc) ? $cc : geo_match_country($get($nameCol) !== '' ? $get($nameCol) : $get($ccCol));
            if ($cc === null && ($get($ccCol) !== '' || $get($nameCol) !== '')) {
                $out[$p . '_country'] = 'XX?'; // unknown country -> validation error
                continue;
            }
        } else {
            $cc = $cur['country_code'] ?? null;
        }
        if ($cc === null && $get($a1Name) !== '') {
            // Older files have a region but no country ("Wales"): a UK nation implies the country.
            $cc = geo_from_names('', $get($a1Name))['country_code'] ?? null;
            if ($cc === null) {
                $out[$p . '_country'] = 'XX?'; // a region without a country cannot be placed
                continue;
            }
        }
        $out[$p . '_country'] = (string) $cc;
        // First and second level
        $a1 = null;
        foreach ([1 => [$a1Code, $a1Name, '_state', 'admin1'], 2 => [$a2Code, $a2Name, '_area', 'admin2']] as $level => [$codeCol, $nCol, $key, $curKey]) {
            if ($codeCol === null && $nCol === null) {
                continue;
            }
            if ($has($codeCol) || $has($nCol)) {
                $code = $get($codeCol);
                $name = $get($nCol);
            } else {
                $code = (string) ($cur[$curKey . '_code'] ?? '');
                $name = (string) ($cur[$curKey . '_name'] ?? '');
            }
            if ($code === '' && $name === '') {
                continue;
            }
            $options = $cc === null ? [] : ($level === 1 ? geo_admin1_options($cc) : ($a1 !== null ? geo_admin2_options($cc, $a1) : []));
            if ($options === []) {
                $out[$p . $key . '_text'] = $name !== '' ? $name : $code;
                continue;
            }
            $match = isset($options[$code]) ? $code : geo_match_in($options, $name);
            $out[$p . $key] = $match ?? ('?' . ($name !== '' ? $name : $code)); // unknown -> validation error
            if ($level === 1) {
                $a1 = $match;
            }
        }
        if ($cityCol !== null) {
            $out[$p . '_city'] = $has($cityCol) ? $get($cityCol) : (string) ($cur['locality'] ?? '');
        }
    }
    return [$out, $slots];
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

/** Form input for a custom location field from its CSV cells ("NG.54.123" code path, or "Ado-Ekiti, Ekiti, Nigeria"). */
function custom_location_import_input(array $field, string $name, string $codePath, string $text): array
{
    $cfg = custom_field_location_config($field);
    $out = [];
    if ($codePath !== '') {
        $parts = explode('.', $codePath);
        $out[$name . '_country'] = strtoupper($parts[0]);
        $out[$name . '_state'] = $parts[1] ?? '';
        $out[$name . '_area'] = $parts[2] ?? '';
        return $out;
    }
    // Names: most specific first ("area, state, country") as exported.
    $bits = array_values(array_filter(array_map('trim', explode(',', $text)), static fn (string $b): bool => $b !== ''));
    if ($bits === []) {
        return $out;
    }
    $country = $cfg['country'] !== '' ? $cfg['country'] : geo_match_country((string) end($bits));
    if ($cfg['country'] === '') {
        array_pop($bits);
    }
    $out[$name . '_country'] = $country ?? 'XX?';
    $bits = array_reverse($bits);
    if ($country !== null && isset($bits[0]) && $cfg['levels'] >= 2) {
        $a1 = geo_match_admin1($country, $bits[0]);
        $out[$name . ($a1 !== null || geo_admin1_options($country) === [] ? ($a1 !== null ? '_state' : '_state_text') : '_state')] = $a1 ?? ($bits[0] . (geo_admin1_options($country) === [] ? '' : '?'));
        if ($a1 !== null && isset($bits[1]) && $cfg['levels'] >= 3) {
            $a2 = geo_match_admin2($country, $a1, $bits[1]);
            $out[$name . ($a2 !== null || geo_admin2_options($country, $a1) === [] ? ($a2 !== null ? '_area' : '_area_text') : '_area')] = $a2 ?? ($bits[1] . (geo_admin2_options($country, $a1) === [] ? '' : '?'));
        }
    }
    return $out;
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
        if (email_domain_status(email_domain($email)) === 'none') {
            // A5 list hygiene: never import an address whose domain cannot receive mail.
            $report['errors'][] = "Row $line: “{$email}”: the domain has no mail server.";
            $report['skipped']++;
            continue;
        }
        if (!isset($in['birth_day']) || $in['birth_day'] === '') {
            $in['birth_day'] = '1';
            $in['birth_month'] = $in['birth_month'] ?? '1';
            $noBirthday = true;
        }
        $existing = q_one('SELECT u.id AS user_id, u.role, m.id AS member_id FROM users u LEFT JOIN members m ON m.user_id = u.id WHERE u.email = ?', [$email]);
        $currentLocs = $update && $existing !== null && $existing['member_id'] !== null ? member_locations((int) $existing['member_id']) : [];
        [$locInput, $locSlots] = member_import_location_input($in, $header, $currentLocs);
        [$profile, $errors, $locations] = member_profile_validate($locInput + $in, true);
        // Only the location slots the file has columns for are changed (and their snapshot columns).
        $locations = array_intersect_key($locations, array_flip($locSlots));
        foreach (['residence' => ['country', 'region', 'city'], 'nationality' => ['nationality']] as $slot => $cols) {
            if (!in_array($slot, $locSlots, true)) {
                foreach ($cols as $col) {
                    unset($profile[$col]);
                }
            }
        }
        if (!empty($noBirthday)) {
            $profile['birth_day'] = null;
            $profile['birth_month'] = null;
            unset($errors['birth_day']);
        }
        $noBirthday = false;
        foreach ($fieldsByKey as $k => $f) {
            if ($f['type'] === 'location' && (array_key_exists($k, $in) || array_key_exists($k . '_code', $in))) {
                $in += custom_location_import_input($f, $k, (string) ($in[$k . '_code'] ?? ''), (string) ($in[$k] ?? ''));
                $in[$k] = '';
            }
        }
        [$custom, $cErrors, $cLocations] = custom_fields_validate(array_values(array_filter($fieldsByKey, static fn (array $f, string $k): bool => array_key_exists($k, $in), ARRAY_FILTER_USE_BOTH)), $in);
        $errors += $cErrors;
        $locations += $cLocations;
        $type = $in['membership_type'] ?? '';
        if ($type !== '' && !in_array($type, $s['types'], true)) {
            $errors['membership_type'] = 'unknown membership type';
        }
        $number = $in['member_number'] ?? '';
        if ($number !== '' && !preg_match('/^[A-Za-z0-9\/._-]{1,40}$/', $number)) {
            $errors['member_number'] = 'invalid member number';
        }
        if ($errors) {
            // Name location problems after the CSV columns, not the form fields.
            $colNames = ['res_country' => 'country', 'res_state' => 'region', 'res_city' => 'city', 'nat_country' => 'nationality'];
            $report['errors'][] = "Row $line: " . implode('; ', array_map(static fn ($k, $v): string => ($colNames[$k] ?? $k) . ": $v", array_keys($errors), $errors));
            $report['skipped']++;
            continue;
        }
        try {
            if ($existing !== null) {
                if (!$update || $existing['role'] !== 'member' || $existing['member_id'] === null) {
                    $report['skipped']++;
                    continue;
                }
                // Only the columns the file has are changed: a CSV with just email + names
                // must not wipe phone numbers, birthdays or addresses.
                $changes = member_import_changes($profile, array_merge($header, array_keys(member_location_snapshot(array_fill_keys($locSlots, null)))));
                db_tx(static function () use ($existing, $changes, $type, $custom, $locations): void {
                    db_update('members', (int) $existing['member_id'], $changes + ($type !== '' ? ['membership_type' => $type] : []));
                    custom_values_save('member_field_values', 'member_id', (int) $existing['member_id'], $custom);
                    member_locations_save((int) $existing['member_id'], $locations);
                });
                $report['updated']++;
                continue;
            }
            $userId = create_user($email, bin2hex(random_bytes(16)), 'member', trim($profile['first_name'] . ' ' . $profile['last_name']), 'active');
            db_tx(static function () use ($userId, $profile, $type, $custom, $number, $in, $s, $locations): void {
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
                member_locations_save($memberId, $locations);
            });
            $report['created']++;
            $report['new_user_ids'][] = $userId;
            // D2: open dues that apply to people who join later.
            payment_charges_for_new_member((int) q_val('SELECT id FROM members WHERE user_id = ?', [$userId]));
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
