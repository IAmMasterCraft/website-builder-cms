<?php
declare(strict_types=1);

/*
 * Audiences for campaigns and notices (§5): all active members, a
 * membership type, a region, a custom field value, birthdays this month,
 * event registrants, groups, admins.
 * Bulk email honours preferences: members only receive it with
 * "newsletters" switched on, and unsubscribed addresses are always skipped.
 */

function audience_types(): array
{
    return [
        'all_members' => 'All active members',
        'membership_type' => 'Members of one membership type',
        'region' => 'Members in a region',
        'custom_field' => 'Members with a custom field value',
        'birthday_month' => 'Members with a birthday this month',
        'event' => 'People registered for an event',
        'groups' => 'Group accounts',
        'admins' => 'Admins',
    ];
}

function audience_default(): array
{
    return ['type' => 'all_members', 'value' => '', 'field_id' => 0];
}

/** @return array{0:array,1:array<string,string>} */
function audience_validate(array $in): array
{
    $type = (string) ($in['audience_type'] ?? $in['type'] ?? 'all_members');
    $a = ['type' => $type, 'value' => '', 'field_id' => 0];
    $errors = [];
    $s = member_settings();
    switch ($type) {
        case 'membership_type':
            $a['value'] = (string) ($in['audience_membership_type'] ?? $in['value'] ?? '');
            if (!in_array($a['value'], $s['types'], true)) {
                $errors['audience'] = 'Choose a membership type.';
            }
            break;
        case 'region':
            $a['value'] = (string) ($in['audience_region'] ?? $in['value'] ?? '');
            if (!in_array($a['value'], $s['regions'], true)) {
                $errors['audience'] = 'Choose a region.';
            }
            break;
        case 'custom_field':
            $a['field_id'] = (int) ($in['audience_field_id'] ?? $in['field_id'] ?? 0);
            $a['value'] = trim((string) ($in['audience_field_value'] ?? $in['value'] ?? ''));
            if (q_val("SELECT id FROM custom_fields WHERE id = ? AND applies_to = 'member'", [$a['field_id']]) === null) {
                $errors['audience'] = 'Choose a custom field.';
            } elseif ($a['value'] === '' || mb_strlen($a['value']) > 200) {
                $errors['audience'] = 'Enter the field value to match.';
            }
            break;
        case 'event':
            $a['value'] = (string) (int) ($in['audience_event_id'] ?? $in['value'] ?? 0);
            if (q_val('SELECT id FROM events WHERE id = ?', [(int) $a['value']]) === null) {
                $errors['audience'] = 'Choose an event.';
            }
            break;
        case 'all_members':
        case 'birthday_month':
        case 'groups':
        case 'admins':
            break;
        default:
            $a = audience_default();
            $errors['audience'] = 'Choose who should receive this.';
    }
    return [$a, $errors];
}

function audience_decode(?string $json): array
{
    $a = json_decode((string) $json, true);
    if (!is_array($a) || !array_key_exists((string) ($a['type'] ?? ''), audience_types())) {
        return audience_default();
    }
    return ['type' => (string) $a['type'], 'value' => (string) ($a['value'] ?? ''), 'field_id' => (int) ($a['field_id'] ?? 0)];
}

function audience_label(array $a): string
{
    return match ($a['type']) {
        'membership_type' => 'Members: ' . $a['value'],
        'region' => 'Members in ' . $a['value'],
        'custom_field' => 'Members where ' . (string) (q_val('SELECT label FROM custom_fields WHERE id = ?', [$a['field_id']]) ?? 'field') . ' = ' . $a['value'],
        'event' => 'Registered for ' . (string) (q_val('SELECT title FROM events WHERE id = ?', [(int) $a['value']]) ?? 'an event'),
        default => audience_types()[$a['type']] ?? 'Unknown audience',
    };
}

/**
 * SELECT for an audience with columns user_id, email, first_name, last_name, prefs, is_member.
 * @return array{0:string,1:list<mixed>}
 */
function audience_sql(array $a, ?DateTimeImmutable $now = null): array
{
    $member = "SELECT u.id AS user_id, u.email, m.first_name, m.last_name, m.prefs, 1 AS is_member
                 FROM users u JOIN members m ON m.user_id = u.id WHERE u.role = 'member' AND u.status = 'active'";
    switch ($a['type']) {
        case 'membership_type':
            return [$member . ' AND m.membership_type = ?', [$a['value']]];
        case 'region':
            return [$member . ' AND m.region = ?', [$a['value']]];
        case 'custom_field':
            return [$member . ' AND EXISTS (SELECT 1 FROM member_field_values v WHERE v.member_id = m.id AND v.field_id = ? AND v.value = ?)', [$a['field_id'], $a['value']]];
        case 'birthday_month':
            $month = (int) ($now ?? new DateTimeImmutable('now', utc()))->setTimezone(site_tz())->format('n');
            return [$member . ' AND m.birth_month = ?', [$month]];
        case 'event':
            return ["SELECT r.user_id, r.email, r.name AS first_name, '' AS last_name, m.prefs, IF(m.id IS NULL, 0, 1) AS is_member
                       FROM event_registrations r LEFT JOIN users u ON u.id = r.user_id LEFT JOIN members m ON m.user_id = r.user_id
                      WHERE r.event_id = ? AND r.status <> 'cancelled' AND r.email <> '' AND (u.id IS NULL OR u.status = 'active')", [(int) $a['value']]];
        case 'groups':
            return ["SELECT u.id AS user_id, u.email, COALESCE(NULLIF(g.contact_person, ''), u.display_name) AS first_name, '' AS last_name, NULL AS prefs, 0 AS is_member
                       FROM users u LEFT JOIN groups_orgs g ON g.user_id = u.id WHERE u.role = 'group' AND u.status = 'active'", []];
        case 'admins':
            return ["SELECT u.id AS user_id, u.email, u.display_name AS first_name, '' AS last_name, NULL AS prefs, 0 AS is_member
                       FROM users u WHERE u.role IN ('super_admin','admin') AND u.status = 'active'", []];
        default:
            return [$member, []];
    }
}

/**
 * Who receives a bulk email (campaign, notice copy). Deduplicated by email.
 * @return array{recipients:list<array{user_id:?int,email:string,name:string,first_name:string}>, excluded:int, total:int}
 */
function audience_resolve(array $a, bool $bulk = true, ?DateTimeImmutable $now = null): array
{
    [$sql, $params] = audience_sql($a, $now);
    $suppressed = array_flip(array_column(q_all('SELECT email FROM email_suppressions'), 'email'));
    $seen = [];
    $out = [];
    $excluded = 0;
    foreach (q_all($sql, $params) as $r) {
        $email = strtolower(trim((string) $r['email']));
        if ($email === '' || isset($seen[$email]) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $seen[$email] = true;
        if ($bulk && (isset($suppressed[$email]) || ((int) $r['is_member'] === 1 && !member_pref($r, 'newsletters')))) {
            $excluded++;
            continue;
        }
        $first = trim((string) $r['first_name']);
        $out[] = [
            'user_id' => $r['user_id'] !== null ? (int) $r['user_id'] : null,
            'email' => $email,
            'name' => trim($first . ' ' . (string) $r['last_name']),
            'first_name' => $first !== '' ? explode(' ', $first)[0] : '',
        ];
    }
    return ['recipients' => $out, 'excluded' => $excluded, 'total' => count($seen)];
}

/** Is this user in the audience (for dashboard notices; preferences do not apply)? */
function audience_includes_user(array $a, int $userId, ?DateTimeImmutable $now = null): bool
{
    [$sql, $params] = audience_sql($a, $now);
    $params[] = $userId;
    return q_val('SELECT 1 FROM (' . $sql . ') x WHERE x.user_id = ? LIMIT 1', $params) !== null;
}

/** Options for the audience picker. */
function audience_choices(): array
{
    $s = member_settings();
    return [
        'types' => audience_types(),
        'membership_types' => $s['types'],
        'regions' => $s['regions'],
        'fields' => q_all("SELECT id, label, type, options FROM custom_fields WHERE applies_to = 'member' ORDER BY sort, label"),
        'events' => q_all('SELECT id, title, start_at FROM events ORDER BY start_at DESC LIMIT 60'),
    ];
}
