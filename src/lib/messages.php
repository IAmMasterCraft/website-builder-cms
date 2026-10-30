<?php
declare(strict_types=1);

use Core\Mail\Message;
use Core\UploadException;

/*
 * Direct messages (Addendum C3). Conversations are 1-to-1 ("direct"),
 * groups of up to MSG_GROUP_MAX people, admin "announcement" groups (only
 * staff post) and "team" threads: a member writing to the organisation,
 * answered by any admin with the `messages` permission (the Team inbox).
 *
 * Who may start a conversation (msg_can_message()):
 * - staff: anyone active (members, groups, other staff);
 * - members and groups (active = verified): other members and groups (unless
 *   the super admin switched member-to-member messaging off), the admins
 *   listed as "message the team" recipients, and the Team inbox. During the
 *   first MSG_NEW_ACCOUNT_HOURS after verification only staff and the Team
 *   inbox. Nobody can message someone who blocked them.
 *
 * Privacy: admins only read conversations they take part in, Team inbox
 * threads (addressed to the organisation) and, with the messages
 * permission, a reported message with MSG_REPORT_CONTEXT messages around it
 * (each view is audit-logged). Delivery: the thread page polls a JSON
 * endpoint; unread messages older than MSG_DIGEST_MINUTES are emailed by
 * cron (one digest per conversation) through the email queue.
 */

const MSG_MAX = 4000;
const MSG_GROUP_MAX = 20;
const MSG_ANNOUNCEMENT_MAX = 5000;
const MSG_TITLE_MAX = 120;
const MSG_EDIT_MINUTES = 15;
/** Members: at most this many messages in MSG_RATE_WINDOW seconds. */
const MSG_RATE_MAX = 30;
const MSG_RATE_WINDOW = 600;
/** Members: new conversations a day. */
const MSG_NEW_PER_DAY = 10;
/** Accounts verified less than this many hours ago may only message admins. */
const MSG_NEW_ACCOUNT_HOURS = 24;
const MSG_REPORTS_PER_DAY = 10;
/** Messages shown before and after a reported one. */
const MSG_REPORT_CONTEXT = 5;
const MSG_DIGEST_MINUTES = 15;
const MSG_ATTACH_MAX_BYTES = 5 * 1024 * 1024;
const MSG_ATTACH_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
const MSG_PAGE = 50;
/** Members search with at least this many characters (or an exact member/group number). */
const MSG_SEARCH_MIN = 3;
/** Members: recipient searches per 10 minutes. */
const MSG_SEARCHES_PER_WINDOW = 60;
/** Members: people they had no conversation with, contacted per day (new conversations and additions together). */
const MSG_NEW_RECIPIENTS_PER_DAY = 40;
/** Members: "add people" actions per day. */
const MSG_ADDS_PER_DAY = 20;
/** One answer for every account a member may not pick (no hint whether it exists). */
const MSG_NOT_AVAILABLE = 'One of the people you chose is not available.';

/** Super admin settings (Settings → Messages). */
function msg_settings(): array
{
    $admins = setting('messages.team_admins', []);
    return [
        'member_to_member' => (bool) setting('messages.member_to_member', true),
        'team_inbox' => (bool) setting('messages.team_inbox', true),
        'team_admins' => is_array($admins) ? array_values(array_unique(array_map('intval', $admins))) : [],
        'retention_months' => max(0, min(120, (int) setting('messages.retention_months', 0))),
    ];
}

function msg_is_staff_role(string $role): bool
{
    return in_array($role, ['super_admin', 'admin'], true);
}

// ---- Accounts and names --------------------------------------------------------------

/**
 * Account rows for messaging (name parts, member number, verification time).
 * @param list<int> $ids
 * @return array<int,array>
 */
function msg_accounts(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
    if ($ids === []) {
        return [];
    }
    $out = [];
    $rows = q_all(
        'SELECT u.id, u.role, u.status, u.email, u.display_name, u.message_emails, u.message_findable, u.created_at,
                m.first_name, m.last_name, m.member_number, m.verified_at AS member_verified_at,
                g.name AS group_name, g.group_number, g.verified_at AS group_verified_at
           FROM users u LEFT JOIN members m ON m.user_id = u.id LEFT JOIN groups_orgs g ON g.user_id = u.id
          WHERE u.id IN (' . db_placeholders(count($ids)) . ')',
        $ids
    );
    foreach ($rows as $r) {
        $r['name'] = msg_account_name($r);
        $out[(int) $r['id']] = $r;
    }
    return $out;
}

function msg_account(int $id): ?array
{
    return msg_accounts([$id])[$id] ?? null;
}

function msg_account_name(?array $a): string
{
    if ($a === null) {
        return 'Former member';
    }
    if (($a['first_name'] ?? null) !== null) {
        return trim($a['first_name'] . ' ' . $a['last_name']);
    }
    if (($a['group_name'] ?? null) !== null) {
        return (string) $a['group_name'];
    }
    return (string) ($a['display_name'] ?? 'Member');
}

/** Hours since the account was verified (members and groups), or since it was created. */
function msg_account_age_hours(array $a, ?int $now = null): float
{
    $at = $a['member_verified_at'] ?? $a['group_verified_at'] ?? $a['created_at'] ?? null;
    if ($at === null) {
        return 0.0;
    }
    return (($now ?? time()) - (int) strtotime($at . ' UTC')) / 3600;
}

/** Active staff ids that may answer the Team inbox (super admins and admins with `messages`). */
function msg_team_staff_ids(): array
{
    return array_map('intval', array_column(q_all(
        "SELECT u.id FROM users u WHERE u.status = 'active' AND (u.role = 'super_admin'
            OR (u.role = 'admin' AND EXISTS (SELECT 1 FROM admin_permissions p WHERE p.user_id = u.id AND p.permission = 'messages')))
          ORDER BY u.id"
    ), 'id'));
}

/** Is $blocker blocking $blocked? */
function msg_blocks(int $blocker, int $blocked): bool
{
    return q_val('SELECT 1 FROM message_blocks WHERE blocker_id = ? AND blocked_id = ?', [$blocker, $blocked]) !== null;
}

/**
 * Why $sender may not start a conversation with (or add) $recipient, or null when allowed.
 * Both are rows from msg_accounts().
 */
function msg_can_message(array $sender, array $recipient, ?int $now = null): ?string
{
    if ((int) $sender['id'] === (int) $recipient['id']) {
        return 'You cannot message yourself.';
    }
    if ($sender['status'] !== 'active') {
        return 'Please verify your email before sending messages.';
    }
    if ($recipient['status'] !== 'active') {
        return msg_account_name($recipient) . ' cannot receive messages at the moment.';
    }
    if (msg_blocks((int) $sender['id'], (int) $recipient['id'])) {
        return 'You blocked ' . msg_account_name($recipient) . '. Unblock them first.';
    }
    if (msg_blocks((int) $recipient['id'], (int) $sender['id'])) {
        return 'You cannot message ' . msg_account_name($recipient) . '.';
    }
    if (msg_is_staff_role((string) $sender['role'])) {
        return null; // admins can message anyone
    }
    $s = msg_settings();
    if (msg_is_staff_role((string) $recipient['role'])) {
        return in_array((int) $recipient['id'], $s['team_admins'], true) ? null : 'Use the Team inbox to contact the organisation.';
    }
    if (!$s['member_to_member']) {
        return 'Messages between members are switched off. You can still message the team.';
    }
    if (msg_account_age_hours($sender, $now) < MSG_NEW_ACCOUNT_HOURS) {
        return 'During your first day as a member you can message the team only.';
    }
    return null;
}

/** Do two accounts already share a conversation (current or past)? */
function msg_share_conversation(int $a, int $b): bool
{
    return q_val('SELECT 1 FROM conversation_participants x JOIN conversation_participants y ON y.conversation_id = x.conversation_id AND y.user_id = ? WHERE x.user_id = ? LIMIT 1', [$b, $a]) !== null;
}

/**
 * May the viewer see and pick this account (review: no enumeration)? Staff
 * see everyone; members see staff they may message, members and groups that
 * let others find them, and people they already share a conversation with.
 */
function msg_visible_to(array $viewerAcct, array $a): bool
{
    if (msg_is_staff_role((string) $viewerAcct['role']) || msg_is_staff_role((string) $a['role'])) {
        return true;
    }
    return (int) ($a['message_findable'] ?? 1) === 1 || msg_share_conversation((int) $viewerAcct['id'], (int) $a['id']);
}

/** msg_can_message() plus visibility: why the viewer may not start with or add this account, or null. */
function msg_can_start_with(array $viewerAcct, array $a, ?int $now = null): ?string
{
    if (!msg_visible_to($viewerAcct, $a)) {
        return MSG_NOT_AVAILABLE;
    }
    return msg_can_message($viewerAcct, $a, $now);
}

/**
 * Recipient search with the members' search budget (MSG_SEARCHES_PER_WINDOW
 * per 10 minutes). Null when the budget is used up.
 */
function msg_recipient_search_limited(array $viewer, string $q, int $limit = 20): ?array
{
    if (!msg_is_staff_role((string) $viewer['role']) && trim($q) !== '' && rate_take('msgsearch:' . $viewer['id'], MSG_SEARCHES_PER_WINDOW, 600) === null) {
        return null;
    }
    return msg_recipient_search($viewer, $q, $limit);
}

/**
 * People the viewer may message whose name or member number matches (for the
 * recipient search; members only see people they may message).
 * @return list<array{id:int,name:string,number:string,kind:string}>
 */
function msg_recipient_search(array $viewer, string $q, int $limit = 20): array
{
    $q = trim(mb_substr($q, 0, 80));
    $viewerAcct = msg_account((int) $viewer['id']);
    if ($viewerAcct === null || $viewerAcct['status'] !== 'active') {
        return [];
    }
    $isStaff = msg_is_staff_role((string) $viewerAcct['role']);
    $s = msg_settings();
    $where = ["u.status = 'active'", 'u.id <> ?',
        'NOT EXISTS (SELECT 1 FROM message_blocks b WHERE (b.blocker_id = u.id AND b.blocked_id = ?) OR (b.blocker_id = ? AND b.blocked_id = u.id))'];
    $params = [$viewer['id'], $viewer['id'], $viewer['id']];
    if (!$isStaff) {
        // Members and groups who opted out of being found never appear (staff always do).
        $where[] = "(u.role IN ('super_admin','admin') OR u.message_findable = 1)";
        $roles = [];
        $memberOk = $s['member_to_member'] && msg_account_age_hours($viewerAcct) >= MSG_NEW_ACCOUNT_HOURS;
        if ($memberOk) {
            $roles[] = "u.role IN ('member','group')";
        }
        if ($s['team_admins'] !== []) {
            $roles[] = "(u.role IN ('super_admin','admin') AND u.id IN (" . db_placeholders(count($s['team_admins'])) . '))';
            array_push($params, ...$s['team_admins']);
        }
        if ($roles === []) {
            return [];
        }
        $where[] = '(' . implode(' OR ', $roles) . ')';
    }
    if ($q !== '' && !$isStaff && mb_strlen($q) < MSG_SEARCH_MIN) {
        // Short terms only match a member or group number exactly (no browsing by initials).
        $where[] = '(m.member_number = ? OR g.group_number = ?)';
        array_push($params, $q, $q);
    } elseif ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $where[] = "(u.display_name LIKE ? OR CONCAT(COALESCE(m.first_name, ''), ' ', COALESCE(m.last_name, '')) LIKE ? OR m.member_number LIKE ? OR g.name LIKE ? OR g.group_number LIKE ?)";
        array_push($params, $like, $like, $like, $like, $like);
    } elseif (!$isStaff) {
        // Without a search term members see the listed team admins only (no member directory).
        $where[] = "u.role IN ('super_admin','admin')";
    }
    $rows = q_all(
        'SELECT u.id FROM users u LEFT JOIN members m ON m.user_id = u.id LEFT JOIN groups_orgs g ON g.user_id = u.id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY (u.role IN (\'super_admin\',\'admin\')) DESC, COALESCE(m.first_name, g.name, u.display_name), u.id LIMIT ' . max(1, min(50, $limit)),
        $params
    );
    $accts = msg_accounts(array_map('intval', array_column($rows, 'id')));
    $out = [];
    foreach ($rows as $r) {
        $a = $accts[(int) $r['id']] ?? null;
        if ($a === null || msg_can_message($viewerAcct, $a) !== null) {
            continue;
        }
        $out[] = msg_recipient_item($a);
    }
    return $out;
}

/** @return array{id:int,name:string,number:string,kind:string} */
function msg_recipient_item(array $a): array
{
    $kind = msg_is_staff_role((string) $a['role']) ? 'staff' : ((string) $a['role'] === 'group' ? 'group' : 'member');
    return ['id' => (int) $a['id'], 'name' => msg_account_name($a), 'number' => (string) ($a['member_number'] ?? $a['group_number'] ?? ''), 'kind' => $kind];
}

// ---- Text --------------------------------------------------------------------------

/** Normalise a message body (line breaks, trimmed). */
function msg_clean_body(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body);
    return trim($body);
}

/**
 * Split plain text into parts for display: ['text', s], ['link', url] and
 * ['br', '']. URLs (http, https, www.) become links; nothing else is markup.
 * @return list<array{0:string,1:string}>
 */
function msg_body_parts(string $body): array
{
    $parts = [];
    foreach (explode("\n", $body) as $i => $line) {
        if ($i > 0) {
            $parts[] = ['br', ''];
        }
        $offset = 0;
        if (preg_match_all('~\b(?:https?://|www\.)[^\s<>"\']+~iu', $line, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$url, $pos]) {
                // Trailing punctuation belongs to the sentence (a closing bracket only when unbalanced).
                $trail = '';
                while ($url !== '' && preg_match('/[.,;:!?\'")\]]$/', $url)) {
                    $last = substr($url, -1);
                    if (($last === ')' && substr_count($url, '(') >= substr_count($url, ')')) || ($last === ']' && substr_count($url, '[') >= substr_count($url, ']'))) {
                        break;
                    }
                    $trail = $last . $trail;
                    $url = substr($url, 0, -1);
                }
                if ($pos > $offset) {
                    $parts[] = ['text', substr($line, $offset, $pos - $offset)];
                }
                $parts[] = ['link', $url];
                $offset = $pos + strlen($url);
            }
        }
        if ($offset < strlen($line)) {
            $parts[] = ['text', substr($line, $offset)];
        }
    }
    return $parts;
}

/** Link target for a URL found in a message (www. gets https://). */
function msg_link_href(string $url): string
{
    return preg_match('~^https?://~i', $url) ? $url : 'https://' . $url;
}

/** Escaped HTML for a message body: line breaks and links only. */
function msg_body_html(string $body): string
{
    $html = '';
    foreach (msg_body_parts($body) as [$type, $value]) {
        $html .= match ($type) {
            'br' => '<br>',
            'link' => '<a href="' . e(msg_link_href($value)) . '" rel="nofollow noopener noreferrer ugc" target="_blank">' . e($value) . '</a>',
            default => e($value),
        };
    }
    return $html;
}

// ---- Conversations ---------------------------------------------------------------------

/** SQL (aliases c, p) that hides 1-to-1 conversations with someone the participant blocked. */
function msg_hidden_sql(): string
{
    return "NOT (c.kind = 'direct' AND EXISTS (SELECT 1 FROM conversation_participants o JOIN message_blocks b ON b.blocked_id = o.user_id AND b.blocker_id = p.user_id
                WHERE o.conversation_id = c.id AND o.user_id <> p.user_id))";
}

/**
 * A conversation the user takes part in (and has not left or hidden by a block),
 * with the participant's row as p_* keys; null otherwise.
 */
function msg_conversation_for(int $convId, int $userId): ?array
{
    return q_one(
        'SELECT c.*, p.part_role AS p_role, p.last_read_message_id AS p_read, p.muted AS p_muted, p.notified_message_id AS p_notified, p.first_visible_id AS p_first
           FROM conversations c JOIN conversation_participants p ON p.conversation_id = c.id AND p.user_id = ?
          WHERE c.id = ? AND p.left_at IS NULL AND ' . msg_hidden_sql(),
        [$userId, $convId]
    );
}

/** A Team inbox conversation, for staff with the messages permission. */
function msg_team_conversation(int $convId, array $viewer): ?array
{
    if (!user_can($viewer, 'messages')) {
        return null;
    }
    return q_one("SELECT c.* FROM conversations c WHERE c.id = ? AND c.kind = 'team'", [$convId]);
}

/**
 * Active participants of conversations.
 * @param list<int> $convIds
 * @return array<int,list<array>> conversation id => participant rows with 'account'
 */
function msg_participants(array $convIds): array
{
    $convIds = array_values(array_unique(array_map('intval', $convIds)));
    if ($convIds === []) {
        return [];
    }
    $rows = q_all('SELECT conversation_id, user_id, part_role, joined_at FROM conversation_participants WHERE left_at IS NULL AND conversation_id IN (' . db_placeholders(count($convIds)) . ') ORDER BY joined_at, user_id', $convIds);
    $accts = msg_accounts(array_map('intval', array_column($rows, 'user_id')));
    $out = [];
    foreach ($rows as $r) {
        $r['account'] = $accts[(int) $r['user_id']] ?? null;
        $out[(int) $r['conversation_id']][] = $r;
    }
    return $out;
}

/** Display title of a conversation for a viewer (the other person in 1-to-1s). */
function msg_conversation_title(array $conv, array $participants, int $viewerId, bool $teamView = false): string
{
    if ($conv['kind'] === 'team') {
        if ($teamView) {
            foreach ($participants as $p) {
                return msg_account_name($p['account']);
            }
            return 'Former member';
        }
        return 'Team inbox · ' . setting('site.name', 'Gather');
    }
    if (($conv['title'] ?? '') !== '' && $conv['title'] !== null) {
        return (string) $conv['title'];
    }
    $names = [];
    foreach ($participants as $p) {
        if ((int) $p['user_id'] !== $viewerId) {
            $names[] = msg_account_name($p['account']);
        }
    }
    return $names === [] ? 'Former member' : implode(', ', array_slice($names, 0, 4)) . (count($names) > 4 ? ' +' . (count($names) - 4) : '');
}

/**
 * The viewer's conversation list: newest first, unread counts, last message
 * preview. $q searches titles, people's names and message text.
 */
function msg_conversations(int $userId, string $q = '', int $limit = 100): array
{
    $params = [$userId, $userId];
    $where = ['p.left_at IS NULL', 'c.last_message_id IS NOT NULL', msg_hidden_sql()];
    $q = trim(mb_substr($q, 0, 100));
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $where[] = "(c.title LIKE ?
            OR EXISTS (SELECT 1 FROM conversation_participants o JOIN users ou ON ou.id = o.user_id LEFT JOIN members om ON om.user_id = ou.id
                        WHERE o.conversation_id = c.id AND o.user_id <> p.user_id AND (ou.display_name LIKE ? OR CONCAT(COALESCE(om.first_name, ''), ' ', COALESCE(om.last_name, '')) LIKE ?))
            OR EXISTS (SELECT 1 FROM messages s WHERE s.conversation_id = c.id AND s.id >= p.first_visible_id AND s.deleted_at IS NULL AND s.note_kind IS NULL AND s.body LIKE ?))";
        array_push($params, $like, $like, $like, $like);
    }
    $rows = q_all(
        'SELECT c.*, p.muted AS p_muted, p.part_role AS p_role,
                (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id AND m.id > p.last_read_message_id
                    AND (m.sender_id IS NULL OR m.sender_id <> ?) AND m.deleted_at IS NULL AND m.note_kind IS NULL) AS unread
           FROM conversation_participants p JOIN conversations c ON c.id = p.conversation_id
          WHERE p.user_id = ? AND ' . implode(' AND ', $where) . '
          ORDER BY c.last_message_at DESC, c.id DESC LIMIT ' . max(1, min(500, $limit)),
        $params
    );
    return msg_decorate_list($rows, $userId, false);
}

/** Team inbox list (staff with the messages permission). $show: '' (all) or 'unread'. */
function msg_team_conversations(string $show = '', string $q = '', int $limit = 200): array
{
    $where = ["c.kind = 'team'", 'c.last_message_id IS NOT NULL'];
    $params = [];
    if ($show === 'unread') {
        $where[] = 'EXISTS (SELECT 1 FROM messages m JOIN users su ON su.id = m.sender_id WHERE m.conversation_id = c.id AND m.id > c.team_read_message_id AND su.role IN (\'member\',\'group\') AND m.deleted_at IS NULL)';
    }
    $q = trim(mb_substr($q, 0, 100));
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $where[] = "(EXISTS (SELECT 1 FROM conversation_participants o JOIN users ou ON ou.id = o.user_id LEFT JOIN members om ON om.user_id = ou.id
                        WHERE o.conversation_id = c.id AND (ou.display_name LIKE ? OR om.member_number LIKE ? OR CONCAT(COALESCE(om.first_name, ''), ' ', COALESCE(om.last_name, '')) LIKE ?))
            OR EXISTS (SELECT 1 FROM messages s WHERE s.conversation_id = c.id AND s.deleted_at IS NULL AND s.body LIKE ?))";
        array_push($params, $like, $like, $like, $like);
    }
    $rows = q_all(
        "SELECT c.*, 0 AS p_muted, 'member' AS p_role,
                (SELECT COUNT(*) FROM messages m JOIN users su ON su.id = m.sender_id WHERE m.conversation_id = c.id AND m.id > c.team_read_message_id
                    AND su.role IN ('member','group') AND m.deleted_at IS NULL AND m.note_kind IS NULL) AS unread
           FROM conversations c WHERE " . implode(' AND ', $where) . '
          ORDER BY c.last_message_at DESC, c.id DESC LIMIT ' . max(1, min(500, $limit)),
        $params
    );
    return msg_decorate_list($rows, 0, true);
}

/** Adds participants, title and last-message preview to list rows. */
function msg_decorate_list(array $rows, int $viewerId, bool $teamView): array
{
    if ($rows === []) {
        return [];
    }
    $parts = msg_participants(array_map('intval', array_column($rows, 'id')));
    $lastIds = array_values(array_filter(array_map('intval', array_column($rows, 'last_message_id'))));
    $last = [];
    if ($lastIds !== []) {
        foreach (q_all('SELECT id, sender_id, body, note_kind, attachment_media_id, deleted_at FROM messages WHERE id IN (' . db_placeholders(count($lastIds)) . ')', $lastIds) as $m) {
            $last[(int) $m['id']] = $m;
        }
    }
    $senders = msg_accounts(array_map(static fn (array $m): int => (int) ($m['sender_id'] ?? 0), $last));
    foreach ($rows as &$r) {
        $r['participants'] = $parts[(int) $r['id']] ?? [];
        $r['display_title'] = msg_conversation_title($r, $r['participants'], $viewerId, $teamView);
        $m = $last[(int) ($r['last_message_id'] ?? 0)] ?? null;
        $preview = '';
        if ($m !== null) {
            if ($m['deleted_at'] !== null) {
                $preview = 'Message deleted';
            } elseif ($m['note_kind'] !== null) {
                $preview = (string) $m['body'];
            } else {
                $who = (int) ($m['sender_id'] ?? 0) === $viewerId ? 'You' : msg_account_name($senders[(int) ($m['sender_id'] ?? 0)] ?? null);
                $text = $m['body'] !== '' ? excerpt((string) $m['body'], 90) : 'Sent an attachment';
                $senderAcct = $senders[(int) ($m['sender_id'] ?? 0)] ?? null;
                // 1-to-1s and the member's own lines in the Team inbox need no name in front.
                $plain = ($r['kind'] === 'direct' && $who !== 'You') || ($teamView && $senderAcct !== null && !msg_is_staff_role((string) $senderAcct['role']));
                $preview = ($plain ? '' : $who . ': ') . $text;
            }
        }
        $r['preview'] = $preview;
        $r['unread'] = (int) $r['unread'];
    }
    unset($r);
    return $rows;
}

/** Unread messages for the badge (muted conversations and blocked people not counted). */
function msg_unread_count(int $userId): int
{
    return (int) q_val(
        'SELECT COUNT(*) FROM conversation_participants p JOIN conversations c ON c.id = p.conversation_id
           JOIN messages m ON m.conversation_id = c.id AND m.id > p.last_read_message_id
          WHERE p.user_id = ? AND p.left_at IS NULL AND p.muted = 0 AND (m.sender_id IS NULL OR m.sender_id <> ?)
            AND m.deleted_at IS NULL AND m.note_kind IS NULL AND ' . msg_hidden_sql(),
        [$userId, $userId]
    );
}

/** Team inbox threads with a member message no admin has read yet. */
function msg_team_unread_count(): int
{
    return (int) q_val(
        "SELECT COUNT(*) FROM conversations c WHERE c.kind = 'team' AND EXISTS (SELECT 1 FROM messages m JOIN users su ON su.id = m.sender_id
            WHERE m.conversation_id = c.id AND m.id > c.team_read_message_id AND su.role IN ('member','group') AND m.deleted_at IS NULL AND m.note_kind IS NULL)"
    );
}

/** What the admin top bar shows: own unread plus unread Team inbox threads (with the permission). */
function msg_admin_badge(array $user): int
{
    return msg_unread_count((int) $user['id']) + (user_can($user, 'messages') ? msg_team_unread_count() : 0);
}

/**
 * Messages of a conversation in order. Default: the newest MSG_PAGE; with
 * $beforeId the page before it; with $afterId everything newer (polling).
 * @return array{0:list<array>,1:bool} [rows, has earlier]
 */
function msg_thread_rows(int $convId, int $afterId = 0, int $beforeId = 0, int $firstVisible = 0): array
{
    if ($afterId > 0) {
        return [q_all('SELECT * FROM messages WHERE conversation_id = ? AND id > ? AND id >= ? ORDER BY id LIMIT 200', [$convId, $afterId, $firstVisible]), false];
    }
    $rows = $beforeId > 0
        ? q_all('SELECT * FROM messages WHERE conversation_id = ? AND id < ? AND id >= ? ORDER BY id DESC LIMIT ' . (MSG_PAGE + 1), [$convId, $beforeId, $firstVisible])
        : q_all('SELECT * FROM messages WHERE conversation_id = ? AND id >= ? ORDER BY id DESC LIMIT ' . (MSG_PAGE + 1), [$convId, $firstVisible]);
    $more = count($rows) > MSG_PAGE;
    return [array_reverse(array_slice($rows, 0, MSG_PAGE)), $more];
}

/**
 * One message ready for the templates and the polling JSON.
 * $viewer: the user looking; $canAct: may edit/delete/report (false when
 * impersonating or for moderators); $names: msg_accounts() of senders.
 */
function msg_present(array $m, array $viewer, array $names, bool $canAct, ?int $now = null): array
{
    $now ??= time();
    $mine = (int) ($m['sender_id'] ?? 0) === (int) $viewer['id'];
    $sender = $names[(int) ($m['sender_id'] ?? 0)] ?? null;
    $deleted = $m['deleted_at'] !== null;
    $note = $m['note_kind'] !== null;
    $created = (int) strtotime($m['created_at'] . ' UTC');
    $att = null;
    if (!$deleted && $m['attachment_media_id'] !== null && ($media = media_find((int) $m['attachment_media_id'])) !== null) {
        $isImage = $media['kind'] === 'image';
        $att = [
            'url' => media_original_url($media), 'name' => (string) $media['original_name'], 'image' => $isImage,
            'thumb' => $isImage && isset($media['variants'][400]) ? '/media/' . (int) $media['id'] . '/w/400' : null,
            'size' => (int) $media['size'],
        ];
    }
    return [
        'id' => (int) $m['id'],
        'mine' => $mine,
        'note' => $note,
        'sender' => $sender === null ? ($m['sender_id'] === null ? 'Former member' : 'Member') : msg_account_name($sender),
        'staff' => $sender !== null && msg_is_staff_role((string) $sender['role']),
        'deleted' => $deleted,
        'deleted_label' => !$deleted ? '' : ($m['deleted_by'] === 'moderator' ? 'Message removed by a moderator' : 'Message deleted'),
        'body' => $deleted ? '' : (string) $m['body'],
        'parts' => $deleted ? [] : msg_body_parts((string) $m['body']),
        'edited' => !$deleted && $m['edited_at'] !== null,
        'created_iso' => gmdate('c', $created),
        'time_label' => fmt_datetime((string) $m['created_at']),
        'attachment' => $att,
        'can_edit' => $canAct && $mine && !$deleted && !$note && $m['body'] !== '' && $now - $created < MSG_EDIT_MINUTES * 60,
        'can_delete' => $canAct && $mine && !$deleted && !$note,
        'can_report' => $canAct && !$mine && !$deleted && !$note && $sender !== null,
    ];
}

/** May the viewer post in this conversation? Returns a reason when not. */
function msg_post_problem(array $conv, array $viewer, bool $teamView = false): ?string
{
    if ($viewer['status'] !== 'active') {
        return 'Please verify your email before sending messages.';
    }
    if ($teamView) {
        return user_can($viewer, 'messages') ? null : 'You cannot answer the Team inbox.';
    }
    if (($conv['archived_at'] ?? null) !== null) {
        return 'This announcement group is closed: no admin is left in it.';
    }
    if ($conv['kind'] === 'announcement' && !msg_is_staff_role((string) $viewer['role'])) {
        return 'Only admins post in this announcement group.';
    }
    if (($p = msg_new_account_post_problem($conv, $viewer)) !== null) {
        return $p;
    }
    if ($conv['kind'] === 'direct') {
        $other = q_val('SELECT user_id FROM conversation_participants WHERE conversation_id = ? AND user_id <> ? LIMIT 1', [$conv['id'], $viewer['id']]);
        $acct = $other !== null ? msg_account((int) $other) : null;
        if ($acct === null) {
            return 'This person is no longer a member.';
        }
        if ($acct['status'] !== 'active') {
            return msg_account_name($acct) . ' cannot receive messages at the moment.';
        }
        if (msg_blocks((int) $viewer['id'], (int) $acct['id'])) {
            return 'You blocked ' . msg_account_name($acct) . '. Unblock them to reply.';
        }
        if (msg_blocks((int) $acct['id'], (int) $viewer['id'])) {
            return 'You cannot message ' . msg_account_name($acct) . '.';
        }
    }
    return null;
}

/**
 * The 24-hour rule for posting (review): a member or group verified less
 * than MSG_NEW_ACCOUNT_HOURS ago may post only in Team inbox threads,
 * 1-to-1s with staff, 1-to-1s someone else started with them (replies), and
 * groups started by staff or whose other people are all staff. Not in a
 * members' group they were added to, nor in 1-to-1s with members they
 * started themselves (they cannot start those anyway).
 */
function msg_new_account_post_problem(array $conv, array $viewer): ?string
{
    if (msg_is_staff_role((string) $viewer['role']) || $conv['kind'] === 'team') {
        return null;
    }
    $acct = msg_account((int) $viewer['id']);
    if ($acct === null || msg_account_age_hours($acct) >= MSG_NEW_ACCOUNT_HOURS) {
        return null;
    }
    $others = array_map('intval', array_column(q_all('SELECT user_id FROM conversation_participants WHERE conversation_id = ? AND user_id <> ? AND left_at IS NULL', [$conv['id'], $viewer['id']]), 'user_id'));
    $roles = array_column(msg_accounts($others), 'role');
    $allStaff = $roles !== [] && array_filter($roles, static fn (string $r): bool => !msg_is_staff_role($r)) === [];
    if ($allStaff) {
        return null;
    }
    $creator = $conv['created_by'] !== null ? msg_account((int) $conv['created_by']) : null;
    if ($conv['kind'] === 'direct' && (int) ($conv['created_by'] ?? 0) !== (int) $viewer['id']) {
        return null; // a reply in a 1-to-1 someone else started
    }
    if ($conv['kind'] === 'group' && $creator !== null && msg_is_staff_role((string) $creator['role'])) {
        return null;
    }
    return 'During your first day as a member you can only reply to messages sent to you and message the team.';
}

/**
 * Store an uploaded attachment (images and PDF, MSG_ATTACH_MAX_BYTES) as
 * private media in the "messages" folder. Returns the media id, null when
 * no file was chosen; throws UploadException when the file is refused.
 */
function msg_store_attachment(?array $file, int $userId, bool $isUpload = true): ?int
{
    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $u = $isUpload ? media_upload_from_files($file) : ['tmp' => (string) $file['tmp_name'], 'name' => (string) $file['name'], 'size' => (int) $file['size']];
    $info = media_validate($u['tmp'], $u['name'], $u['size']);
    if (!in_array($info['mime'], MSG_ATTACH_MIMES, true)) {
        throw new UploadException('Attach a photo (JPG, PNG, WebP or GIF) or a PDF.');
    }
    if ($u['size'] > MSG_ATTACH_MAX_BYTES) {
        throw new UploadException('Attachments can be up to ' . (MSG_ATTACH_MAX_BYTES / 1048576) . ' MB.');
    }
    return media_store($u['tmp'], $u['name'], $u['size'], $userId, $isUpload, ['visibility' => 'private', 'folder' => 'messages', 'alt' => 'Attachment']);
}

/**
 * While an admin views the site as a member (B2), the first time in that
 * session they open any message area (list, thread, polling, paging,
 * attachments) is audited once; thread views are audited each time.
 */
function msg_impersonation_audit(): void
{
    $imp = impersonation();
    if ($imp === null || !empty($_SESSION['imp']['msg_audited'])) {
        return;
    }
    $_SESSION['imp']['msg_audited'] = true;
    audit('message_access', 'account', (int) $imp['member_id'], 'opened the member\'s messages while logged in as the member');
}

/**
 * May this user open a message attachment? The uploader, active participants
 * of the conversation, Team inbox staff for team threads, and staff with the
 * messages permission when the message was reported.
 */
function msg_attachment_can_view(int $mediaId, ?array $user): bool
{
    if ($user === null || $user['status'] !== 'active') {
        return false;
    }
    $rows = q_all('SELECT m.id, m.conversation_id, m.sender_id, c.kind FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE m.attachment_media_id = ? AND m.deleted_at IS NULL', [$mediaId]);
    foreach ($rows as $r) {
        if ((int) ($r['sender_id'] ?? 0) === (int) $user['id']) {
            return true;
        }
        if (q_val('SELECT 1 FROM conversation_participants WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL AND first_visible_id <= ?', [$r['conversation_id'], $user['id'], $r['id']]) !== null) {
            msg_impersonation_audit();
            return true;
        }
        if (user_can($user, 'messages') && ($r['kind'] === 'team' || q_val('SELECT 1 FROM message_reports WHERE message_id = ? LIMIT 1', [$r['id']]) !== null)) {
            return true;
        }
    }
    return false;
}

/**
 * Insert a message (caller has checked the rules). Updates the conversation
 * and marks it read for the sender.
 */
function msg_insert(int $convId, ?int $senderId, string $body, ?int $mediaId = null, ?string $note = null, bool $teamReply = false): int
{
    $id = db_insert('messages', ['conversation_id' => $convId, 'sender_id' => $senderId, 'body' => $body, 'attachment_media_id' => $mediaId, 'note_kind' => $note]);
    $now = gmdate('Y-m-d H:i:s');
    q('UPDATE conversations SET last_message_id = ?, last_message_at = ? WHERE id = ?', [$id, $now, $convId]);
    if ($senderId !== null) {
        q('UPDATE conversation_participants SET last_read_message_id = GREATEST(last_read_message_id, ?), notified_message_id = GREATEST(notified_message_id, ?), last_read_at = ? WHERE conversation_id = ? AND user_id = ?', [$id, $id, $now, $convId, $senderId]);
    }
    if ($teamReply) {
        q('UPDATE conversations SET team_read_message_id = GREATEST(team_read_message_id, ?), team_notified_message_id = GREATEST(team_notified_message_id, ?) WHERE id = ?', [$id, $id, $convId]);
    }
    return $id;
}

/** Validate a body (attachments may go without text). */
function msg_body_problem(string $body, bool $hasAttachment): ?string
{
    if ($body === '' && !$hasAttachment) {
        return 'Write a message.';
    }
    if (mb_strlen($body) > MSG_MAX) {
        return 'Messages can be up to ' . number_format(MSG_MAX) . ' characters.';
    }
    return null;
}

/** Message rate limit for members and groups (staff are not limited). */
function msg_rate_problem(array $sender): ?string
{
    if (msg_is_staff_role((string) $sender['role'])) {
        return null;
    }
    if (rate_limited('msg:' . $sender['id'], MSG_RATE_MAX, MSG_RATE_WINDOW)) {
        return 'You have sent a lot of messages in a short time. Please wait a few minutes.';
    }
    return null;
}

/**
 * Take one message from the member's budget (atomic, review fix). Staff
 * are not limited (empty list). Null when the limit is reached.
 * @return ?list<int>
 */
function msg_rate_take(array $sender): ?array
{
    return msg_is_staff_role((string) $sender['role']) ? [] : rate_take('msg:' . $sender['id'], MSG_RATE_MAX, MSG_RATE_WINDOW);
}

/**
 * Members: people they never shared a conversation with count towards
 * MSG_NEW_RECIPIENTS_PER_DAY (new conversations and additions together),
 * so messages cannot be used to reach a large part of the membership.
 * Returns the hits taken (empty for staff or nobody new), or null when over.
 * @param list<int> $ids
 * @return ?list<int>
 */
function msg_new_recipients_take(array $sender, array $ids): ?array
{
    if (msg_is_staff_role((string) $sender['role'])) {
        return [];
    }
    $new = array_values(array_filter($ids, static fn (int $id): bool => !msg_share_conversation((int) $sender['id'], $id)));
    return rate_take('msgnew:' . $sender['id'], MSG_NEW_RECIPIENTS_PER_DAY, 86400, count($new));
}

/**
 * Send a message in an existing conversation.
 * @return array{0:?int,1:?string} [message id, error]
 */
function msg_post(array $sender, int $convId, string $body, ?array $file = null, bool $teamView = false, bool $isUpload = true): array
{
    $conv = $teamView ? msg_team_conversation($convId, $sender) : msg_conversation_for($convId, (int) $sender['id']);
    if ($conv === null) {
        return [null, 'This conversation is not available.'];
    }
    if (($p = msg_post_problem($conv, $sender, $teamView)) !== null) {
        return [null, $p];
    }
    $body = msg_clean_body($body);
    $hasFile = $file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if (($p = msg_body_problem($body, $hasFile)) !== null) {
        return [null, $p];
    }
    $hit = msg_rate_take($sender);
    if ($hit === null) {
        return [null, 'You have sent a lot of messages in a short time. Please wait a few minutes.'];
    }
    try {
        $mediaId = msg_store_attachment($file, (int) $sender['id'], $isUpload);
    } catch (UploadException $e) {
        rate_release($hit);
        return [null, $e->getMessage()];
    }
    return [msg_insert($convId, (int) $sender['id'], $body, $mediaId, null, $teamView), null];
}

/**
 * Start a conversation (or continue the existing 1-to-1 / Team inbox thread).
 * $opts: title, team (bool: message the Team inbox), announcement (staff),
 * everyone (staff announcements: add every active member and group).
 * @param list<int> $recipientIds
 * @return array{0:?int,1:?string} [conversation id, error]
 */
function msg_start(array $sender, array $recipientIds, string $body, array $opts = [], ?array $file = null, bool $isUpload = true): array
{
    $senderAcct = msg_account((int) $sender['id']);
    if ($senderAcct === null || $senderAcct['status'] !== 'active') {
        return [null, 'Please verify your email before sending messages.'];
    }
    $isStaff = msg_is_staff_role((string) $senderAcct['role']);
    $body = msg_clean_body($body);
    $hasFile = $file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if (($p = msg_body_problem($body, $hasFile)) !== null) {
        return [null, $p];
    }
    $title = trim((string) preg_replace('/\s+/u', ' ', (string) ($opts['title'] ?? '')));
    if (mb_strlen($title) > MSG_TITLE_MAX) {
        return [null, 'Keep the group name under ' . MSG_TITLE_MAX . ' characters.'];
    }
    $recipientIds = array_values(array_unique(array_filter(array_map('intval', $recipientIds), static fn (int $i): bool => $i > 0 && $i !== (int) $sender['id'])));
    $team = !empty($opts['team']);
    $announcement = $isStaff && !empty($opts['announcement']);
    if ($team) {
        if ($isStaff) {
            return [null, 'Admins answer the Team inbox from Messages → Team inbox.'];
        }
        if (!msg_settings()['team_inbox']) {
            return [null, 'The Team inbox is switched off. Choose a person to message.'];
        }
        if ($recipientIds !== []) {
            return [null, 'A message to the team goes to the team only. Remove the other people or send two messages.'];
        }
    } else {
        if ($announcement && !empty($opts['everyone'])) {
            $all = array_map('intval', array_column(q_all("SELECT id FROM users WHERE role IN ('member','group') AND status = 'active' AND id <> ? ORDER BY id LIMIT " . MSG_ANNOUNCEMENT_MAX, [$sender['id']]), 'id'));
            $recipientIds = array_values(array_unique(array_merge($recipientIds, $all)));
        }
        if ($recipientIds === []) {
            return [null, 'Choose who to message.'];
        }
        $max = $announcement ? MSG_ANNOUNCEMENT_MAX : MSG_GROUP_MAX;
        if (count($recipientIds) + 1 > $max) {
            return [null, $announcement ? 'Announcement groups can have up to ' . number_format(MSG_ANNOUNCEMENT_MAX) . ' people.' : 'Group conversations can have up to ' . MSG_GROUP_MAX . ' people (including you).'];
        }
        $accts = msg_accounts($recipientIds);
        foreach ($recipientIds as $rid) {
            if (!isset($accts[$rid])) {
                return [null, MSG_NOT_AVAILABLE];
            }
            // Announcements to "everyone" skip people who blocked the admin; a hand-picked one is refused.
            if (($p = msg_can_start_with($senderAcct, $accts[$rid])) !== null) {
                if ($announcement && !empty($opts['everyone'])) {
                    $recipientIds = array_values(array_diff($recipientIds, [$rid]));
                    continue;
                }
                return [null, $p];
            }
        }
        if ($recipientIds === []) {
            return [null, 'Nobody can receive this announcement.'];
        }
    }
    $group = !$team && ($announcement || count($recipientIds) > 1 || $title !== '');
    if ($group && $title === '') {
        return [null, $announcement ? 'Give the announcement group a name.' : 'Give the group conversation a name.'];
    }
    $pairKey = $team ? 't:' . (int) $sender['id'] : (!$group ? 'd:' . min((int) $sender['id'], $recipientIds[0]) . ':' . max((int) $sender['id'], $recipientIds[0]) : null);
    $existing = $pairKey !== null ? (int) q_val('SELECT id FROM conversations WHERE pair_key = ?', [$pairKey]) : 0;
    // Review fix: limits are taken atomically (hit first), and given back when a later step fails.
    $hits = [];
    $release = static function () use (&$hits): void {
        foreach ($hits as $h) {
            rate_release($h);
        }
    };
    if (($hits[] = msg_rate_take($senderAcct)) === null) {
        return [null, 'You have sent a lot of messages in a short time. Please wait a few minutes.'];
    }
    if ($existing === 0 && !$isStaff && ($hits[] = rate_take('msgconv:' . $sender['id'], MSG_NEW_PER_DAY, 86400)) === null) {
        $release();
        return [null, 'You have started ' . MSG_NEW_PER_DAY . ' conversations today. Please continue an existing one or try again tomorrow.'];
    }
    if (($hits[] = msg_new_recipients_take($senderAcct, $recipientIds)) === null) {
        $release();
        return [null, 'You have contacted many new people today. Please try again tomorrow.'];
    }
    try {
        $mediaId = msg_store_attachment($file, (int) $sender['id'], $isUpload);
    } catch (UploadException $e) {
        $release();
        return [null, $e->getMessage()];
    }
    $convId = db_tx(static function () use ($existing, $pairKey, $team, $group, $announcement, $title, $sender, $recipientIds): int {
        if ($existing > 0) {
            // A 1-to-1 or Team thread already exists: both people are (back) in it.
            foreach (array_merge([(int) $sender['id']], $recipientIds) as $uid) {
                q('INSERT INTO conversation_participants (conversation_id, user_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE left_at = NULL', [$existing, $uid]);
            }
            return $existing;
        }
        $kind = $team ? 'team' : ($announcement ? 'announcement' : ($group ? 'group' : 'direct'));
        try {
            $id = db_insert('conversations', ['kind' => $kind, 'title' => $group ? $title : null, 'pair_key' => $pairKey, 'created_by' => $sender['id']]);
        } catch (PDOException $e) {
            // Two first messages to the same person at once: use the conversation the other request made.
            $id = $pairKey !== null ? (int) q_val('SELECT id FROM conversations WHERE pair_key = ?', [$pairKey]) : 0;
            if ($id === 0) {
                throw $e;
            }
        }
        q("INSERT IGNORE INTO conversation_participants (conversation_id, user_id, part_role) VALUES (?, ?, 'owner')", [$id, $sender['id']]);
        foreach ($recipientIds as $uid) {
            q('INSERT IGNORE INTO conversation_participants (conversation_id, user_id) VALUES (?, ?)', [$id, $uid]);
        }
        return $id;
    });
    msg_insert($convId, (int) $sender['id'], $body, $mediaId);
    return [$convId, null];
}

/** Edit an own message within MSG_EDIT_MINUTES. Returns an error or null. */
function msg_edit(array $user, int $messageId, string $body, ?int $now = null): ?string
{
    $m = q_one('SELECT * FROM messages WHERE id = ?', [$messageId]);
    if ($m === null || msg_conversation_for((int) $m['conversation_id'], (int) $user['id']) === null) {
        return 'This message is not available.';
    }
    $created = (int) strtotime($m['created_at'] . ' UTC');
    if ((int) ($m['sender_id'] ?? 0) !== (int) $user['id'] || $m['deleted_at'] !== null || $m['note_kind'] !== null || ($now ?? time()) - $created >= MSG_EDIT_MINUTES * 60) {
        return 'Messages can be edited for ' . MSG_EDIT_MINUTES . ' minutes after sending.';
    }
    $body = msg_clean_body($body);
    if (($p = msg_body_problem($body, $m['attachment_media_id'] !== null)) !== null) {
        return $p;
    }
    q('UPDATE messages SET body = ?, edited_at = ? WHERE id = ?', [$body, gmdate('Y-m-d H:i:s', $now ?? time()), $messageId]);
    return null;
}

/**
 * Blank a message ("message deleted") and delete its attachment.
 * $by: sender | moderator | account | retention.
 */
function msg_blank(int $messageId, string $by): void
{
    $m = q_one('SELECT attachment_media_id FROM messages WHERE id = ?', [$messageId]);
    if ($m === null) {
        return;
    }
    q("UPDATE messages SET body = '', attachment_media_id = NULL, deleted_at = COALESCE(deleted_at, ?), deleted_by = COALESCE(deleted_by, ?) WHERE id = ?", [gmdate('Y-m-d H:i:s'), $by, $messageId]);
    if ($m['attachment_media_id'] !== null) {
        msg_delete_media((int) $m['attachment_media_id']);
    }
}

function msg_delete_media(int $mediaId): void
{
    $media = media_find($mediaId);
    if ($media !== null && (string) $media['folder'] === 'messages') {
        q('DELETE FROM media WHERE id = ?', [$mediaId]);
        media_delete_files($media);
        media_forget($mediaId);
    }
}

/** Delete an own message (any time). */
function msg_delete_own(array $user, int $messageId): bool
{
    $m = q_one('SELECT * FROM messages WHERE id = ? AND deleted_at IS NULL AND note_kind IS NULL', [$messageId]);
    if ($m === null || (int) ($m['sender_id'] ?? 0) !== (int) $user['id'] || msg_conversation_for((int) $m['conversation_id'], (int) $user['id']) === null) {
        return false;
    }
    msg_blank($messageId, 'sender');
    return true;
}

/** Mark read up to the newest message (or $upTo). Returns true when something changed. */
function msg_mark_read(int $userId, int $convId, ?int $upTo = null): bool
{
    $upTo ??= (int) q_val('SELECT COALESCE(MAX(id), 0) FROM messages WHERE conversation_id = ?', [$convId]);
    return q('UPDATE conversation_participants SET last_read_message_id = ?, last_read_at = ? WHERE conversation_id = ? AND user_id = ? AND last_read_message_id < ?', [$upTo, gmdate('Y-m-d H:i:s'), $convId, $userId, $upTo])->rowCount() > 0;
}

/** Team inbox: mark a thread read (shared by all team admins). */
function msg_team_mark_read(int $convId, ?int $upTo = null): bool
{
    $upTo ??= (int) q_val('SELECT COALESCE(MAX(id), 0) FROM messages WHERE conversation_id = ?', [$convId]);
    return q("UPDATE conversations SET team_read_message_id = ? WHERE id = ? AND kind = 'team' AND team_read_message_id < ?", [$upTo, $convId, $upTo])->rowCount() > 0;
}

function msg_set_muted(int $userId, int $convId, bool $muted): void
{
    q('UPDATE conversation_participants SET muted = ? WHERE conversation_id = ? AND user_id = ?', [$muted ? 1 : 0, $convId, $userId]);
}

/** May this participant add or remove people? Group owners and staff, in groups and announcements. */
function msg_can_manage(array $conv, array $user): bool
{
    return in_array($conv['kind'], ['group', 'announcement'], true) && (($conv['p_role'] ?? '') === 'owner' || msg_is_staff_role((string) $user['role']));
}

/** Leave a group or announcement. Returns an error or null. */
function msg_leave(array $user, int $convId): ?string
{
    $conv = msg_conversation_for($convId, (int) $user['id']);
    if ($conv === null || !in_array($conv['kind'], ['group', 'announcement'], true)) {
        return 'Only group conversations can be left.';
    }
    db_tx(static function () use ($user, $convId, $conv): void {
        q('UPDATE conversation_participants SET left_at = ?, part_role = \'member\' WHERE conversation_id = ? AND user_id = ?', [gmdate('Y-m-d H:i:s'), $convId, $user['id']]);
        // Announcement groups stay in staff hands (review): only staff take over, and with no
        // staff left the group is closed (read-only). Normal groups pass to the longest-standing person.
        $staffOnly = $conv['kind'] === 'announcement' ? " AND EXISTS (SELECT 1 FROM users u WHERE u.id = p.user_id AND u.role IN ('super_admin','admin') AND u.status = 'active')" : '';
        $next = q_val('SELECT p.user_id FROM conversation_participants p WHERE p.conversation_id = ? AND p.left_at IS NULL' . $staffOnly . ' ORDER BY p.joined_at, p.user_id LIMIT 1', [$convId]);
        if ($next === null && $conv['kind'] === 'announcement') {
            q('UPDATE conversations SET archived_at = COALESCE(archived_at, ?) WHERE id = ?', [gmdate('Y-m-d H:i:s'), $convId]);
        } elseif ($conv['p_role'] === 'owner' && $next !== null) {
            q("UPDATE conversation_participants SET part_role = 'owner' WHERE conversation_id = ? AND user_id = ?", [$convId, $next]);
        }
    });
    msg_insert($convId, null, msg_account_name(msg_account((int) $user['id'])) . ' left the conversation.', null, 'left');
    return null;
}

/**
 * Add people to a group (owner or staff). Each must be someone the actor may message.
 * @param list<int> $ids
 */
function msg_add_people(array $actor, int $convId, array $ids): ?string
{
    $conv = msg_conversation_for($convId, (int) $actor['id']);
    if ($conv === null || !msg_can_manage($conv, $actor) || $conv['archived_at'] !== null) {
        return 'You cannot add people to this conversation.';
    }
    $actorAcct = msg_account((int) $actor['id']);
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
    $current = array_map('intval', array_column(q_all('SELECT user_id FROM conversation_participants WHERE conversation_id = ? AND left_at IS NULL', [$convId]), 'user_id'));
    $ids = array_values(array_diff($ids, $current));
    if ($ids === []) {
        return 'Choose someone who is not in the conversation yet.';
    }
    $max = $conv['kind'] === 'announcement' ? MSG_ANNOUNCEMENT_MAX : MSG_GROUP_MAX;
    if (count($current) + count($ids) > $max) {
        return 'This conversation can have up to ' . number_format($max) . ' people.';
    }
    $accts = msg_accounts($ids);
    foreach ($ids as $id) {
        if (!isset($accts[$id]) || $actorAcct === null || ($p = msg_can_start_with($actorAcct, $accts[$id])) !== null) {
            return $p ?? MSG_NOT_AVAILABLE;
        }
    }
    // Review: members add people at most MSG_ADDS_PER_DAY times a day, and new contacts count towards the daily cap.
    $hits = [];
    if (!msg_is_staff_role((string) $actor['role'])) {
        if (($hits[] = rate_take('msgadd:' . $actor['id'], MSG_ADDS_PER_DAY, 86400)) === null) {
            return 'You have added people many times today. Please try again tomorrow.';
        }
        if (($hits[] = msg_new_recipients_take($actor, $ids)) === null) {
            rate_release($hits[0]);
            return 'You have contacted many new people today. Please try again tomorrow.';
        }
    }
    $names = [];
    foreach ($ids as $id) {
        // Newcomers (and people who come back) see only what is written from now on.
        $from = (int) q_val('SELECT COALESCE(MAX(id), 0) FROM messages WHERE conversation_id = ?', [$convId]);
        q('INSERT INTO conversation_participants (conversation_id, user_id, last_read_message_id, notified_message_id, first_visible_id) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE left_at = NULL, joined_at = CURRENT_TIMESTAMP, last_read_message_id = VALUES(last_read_message_id),
                notified_message_id = VALUES(notified_message_id), first_visible_id = VALUES(first_visible_id), muted = 0', [$convId, $id, $from, $from, $from + 1]);
        $names[] = msg_account_name($accts[$id]);
    }
    msg_insert($convId, null, msg_account_name($actorAcct) . ' added ' . implode(', ', $names) . '.', null, 'added');
    return null;
}

/** Remove someone from a group (owner or staff; not themselves: they leave instead). */
function msg_remove_person(array $actor, int $convId, int $userId): ?string
{
    $conv = msg_conversation_for($convId, (int) $actor['id']);
    if ($conv === null || !msg_can_manage($conv, $actor) || $userId === (int) $actor['id']) {
        return 'You cannot remove this person.';
    }
    $n = q('UPDATE conversation_participants SET left_at = ?, part_role = \'member\' WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL', [gmdate('Y-m-d H:i:s'), $convId, $userId])->rowCount();
    if ($n === 0) {
        return 'This person is not in the conversation.';
    }
    msg_insert($convId, null, msg_account_name(msg_account((int) $actor['id'])) . ' removed ' . msg_account_name(msg_account($userId)) . '.', null, 'removed');
    return null;
}

/** Block a member or group account (staff cannot be blocked). Returns an error or null. */
function msg_block(int $blockerId, int $blockedId): ?string
{
    $a = msg_account($blockedId);
    // One answer for missing, own and staff accounts (staff cannot be blocked).
    if ($a === null || $blockedId === $blockerId || msg_is_staff_role((string) $a['role'])) {
        return 'This person cannot be blocked.';
    }
    q('INSERT IGNORE INTO message_blocks (blocker_id, blocked_id) VALUES (?, ?)', [$blockerId, $blockedId]);
    return null;
}

function msg_unblock(int $blockerId, int $blockedId): bool
{
    return q('DELETE FROM message_blocks WHERE blocker_id = ? AND blocked_id = ?', [$blockerId, $blockedId])->rowCount() > 0;
}

/** People the user blocked. */
function msg_blocked_list(int $userId): array
{
    $ids = array_map('intval', array_column(q_all('SELECT blocked_id FROM message_blocks WHERE blocker_id = ? ORDER BY created_at', [$userId]), 'blocked_id'));
    return array_values(msg_accounts($ids));
}

/**
 * Report a message (a participant, not the sender; once per message;
 * MSG_REPORTS_PER_DAY a day). Returns an error or null.
 */
function msg_report(array $user, int $messageId, string $reason): ?string
{
    $m = q_one('SELECT * FROM messages WHERE id = ? AND deleted_at IS NULL AND note_kind IS NULL', [$messageId]);
    $conv = $m !== null ? msg_conversation_for((int) $m['conversation_id'], (int) $user['id']) : null;
    if ($m === null || $conv === null || (int) $m['id'] < (int) $conv['p_first'] || (int) ($m['sender_id'] ?? 0) === (int) $user['id'] || $m['sender_id'] === null) {
        return 'This message cannot be reported.';
    }
    if (q_val('SELECT 1 FROM message_reports WHERE message_id = ? AND reporter_id = ?', [$messageId, $user['id']]) !== null) {
        return 'You have already reported this message. An admin will look at it.';
    }
    $hit = rate_take('msg-report:' . $user['id'], MSG_REPORTS_PER_DAY, 86400);
    if ($hit === null) {
        return 'You have reported several messages today. Please contact the organisation if something is urgent.';
    }
    if (q('INSERT IGNORE INTO message_reports (message_id, reporter_id, reason) VALUES (?, ?, ?)', [$messageId, $user['id'], mb_substr(trim($reason), 0, 500)])->rowCount() !== 1) {
        rate_release($hit);
        return 'You have already reported this message. An admin will look at it.';
    }
    return null;
}

/** Messages with open reports (Reports queue and the bell). */
function msg_reports_open_count(): int
{
    return (int) q_val('SELECT COUNT(DISTINCT message_id) FROM message_reports WHERE resolved_at IS NULL');
}

/**
 * Report queue rows: one per reported message (open or resolved), without
 * the message text (an admin opens a report to read it; that view is audited).
 */
function msg_reports_list(bool $open = true, int $limit = 200): array
{
    $rows = q_all(
        'SELECT r.message_id, MIN(r.created_at) AS first_at, COUNT(*) AS reports, MAX(r.resolved_at) AS resolved_at, MAX(r.outcome) AS outcome,
                m.conversation_id, m.sender_id, m.deleted_at, c.kind
           FROM message_reports r JOIN messages m ON m.id = r.message_id JOIN conversations c ON c.id = m.conversation_id
          WHERE r.resolved_at IS ' . ($open ? 'NULL' : 'NOT NULL') . '
          GROUP BY r.message_id, m.conversation_id, m.sender_id, m.deleted_at, c.kind
          ORDER BY first_at DESC LIMIT ' . max(1, min(500, $limit))
    );
    $accts = msg_accounts(array_map(static fn (array $r): int => (int) ($r['sender_id'] ?? 0), $rows));
    foreach ($rows as &$r) {
        $r['sender_name'] = msg_account_name($accts[(int) ($r['sender_id'] ?? 0)] ?? null);
        $r['reasons'] = q_all('SELECT r.reason, r.created_at, r.reporter_id FROM message_reports r WHERE r.message_id = ? ORDER BY r.created_at', [$r['message_id']]);
        $rep = msg_accounts(array_map(static fn (array $x): int => (int) ($x['reporter_id'] ?? 0), $r['reasons']));
        foreach ($r['reasons'] as &$x) {
            $x['reporter'] = msg_account_name($rep[(int) ($x['reporter_id'] ?? 0)] ?? null);
        }
        unset($x);
    }
    unset($r);
    return $rows;
}

/**
 * A reported message with MSG_REPORT_CONTEXT messages before and after it
 * (the only part of a private conversation an admin may read). Null when the
 * message has no report.
 */
function msg_report_context(int $messageId): ?array
{
    $m = q_one('SELECT m.*, c.kind, c.title FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE m.id = ?', [$messageId]);
    if ($m === null || q_val('SELECT 1 FROM message_reports WHERE message_id = ? LIMIT 1', [$messageId]) === null) {
        return null;
    }
    $before = array_reverse(q_all('SELECT * FROM messages WHERE conversation_id = ? AND id < ? ORDER BY id DESC LIMIT ' . MSG_REPORT_CONTEXT, [$m['conversation_id'], $messageId]));
    $after = q_all('SELECT * FROM messages WHERE conversation_id = ? AND id > ? ORDER BY id LIMIT ' . MSG_REPORT_CONTEXT, [$m['conversation_id'], $messageId]);
    $rows = array_merge($before, [$m], $after);
    return [
        'message' => $m,
        'rows' => $rows,
        'names' => msg_accounts(array_map(static fn (array $r): int => (int) ($r['sender_id'] ?? 0), $rows)),
        'participants' => msg_participants([(int) $m['conversation_id']])[(int) $m['conversation_id']] ?? [],
        'reports' => msg_reports_for($messageId),
    ];
}

function msg_reports_for(int $messageId): array
{
    $rows = q_all('SELECT * FROM message_reports WHERE message_id = ? ORDER BY created_at', [$messageId]);
    $accts = msg_accounts(array_merge(array_map(static fn (array $r): int => (int) ($r['reporter_id'] ?? 0), $rows), array_map(static fn (array $r): int => (int) ($r['resolved_by'] ?? 0), $rows)));
    foreach ($rows as &$r) {
        $r['reporter'] = msg_account_name($accts[(int) ($r['reporter_id'] ?? 0)] ?? null);
        $r['resolver'] = $r['resolved_by'] !== null ? msg_account_name($accts[(int) $r['resolved_by']] ?? null) : '';
    }
    unset($r);
    return $rows;
}

/** Resolve the reports of a message: keep (dismiss) or remove the message. */
function msg_report_resolve(int $messageId, string $action, int $adminId): bool
{
    if (!in_array($action, ['keep', 'remove'], true) || q_val('SELECT 1 FROM message_reports WHERE message_id = ? AND resolved_at IS NULL LIMIT 1', [$messageId]) === null) {
        return false;
    }
    if ($action === 'remove') {
        msg_blank($messageId, 'moderator');
    }
    q('UPDATE message_reports SET resolved_at = ?, resolved_by = ?, outcome = ? WHERE message_id = ? AND resolved_at IS NULL', [gmdate('Y-m-d H:i:s'), $adminId, $action === 'remove' ? 'removed' : 'kept', $messageId]);
    return true;
}

// ---- Email digests (cron) ---------------------------------------------------------------

/** Suppressions that stop message notification emails (all of them: bounces, complaints, unsubscribe-from-all). */
function msg_digest_blocked_address(string $email): ?string
{
    $sup = email_suppression($email);
    return $sup !== null ? 'Not sent: ' . email_suppression_label((string) $sup['reason']) . '.' : null;
}

/**
 * Queue one notification email per conversation for unread messages that
 * are at least MSG_DIGEST_MINUTES old and were not covered by an earlier
 * digest. Respects the "Message notifications" preference, muted
 * conversations, account status and every suppression. Team inbox threads
 * notify the admins who answer the Team inbox. Returns emails queued.
 */
function msg_digest_run(?int $now = null): int
{
    $now ??= time();
    $cutoff = gmdate('Y-m-d H:i:s', $now - MSG_DIGEST_MINUTES * 60);
    $queued = 0;
    // Participant digests: newest message older than the cut-off that the person has neither read nor been told about.
    $rows = q_all(
        "SELECT p.conversation_id, p.user_id, p.last_read_message_id, p.notified_message_id, u.email, c.kind,
                MAX(m.id) AS newest_id, COUNT(*) AS waiting
           FROM conversation_participants p
           JOIN users u ON u.id = p.user_id
           JOIN conversations c ON c.id = p.conversation_id
           JOIN messages m ON m.conversation_id = p.conversation_id AND m.id > p.last_read_message_id AND m.id > p.notified_message_id AND m.id >= p.first_visible_id
          WHERE p.left_at IS NULL AND p.muted = 0 AND u.status = 'active' AND u.message_emails = 1 AND c.archived_at IS NULL
            AND (m.sender_id IS NULL OR m.sender_id <> p.user_id) AND m.deleted_at IS NULL AND m.note_kind IS NULL AND m.created_at <= ?
            AND " . msg_hidden_sql() . '
          GROUP BY p.conversation_id, p.user_id, p.last_read_message_id, p.notified_message_id, u.email, c.kind
          ORDER BY p.conversation_id, p.user_id LIMIT 500',
        [$cutoff]
    );
    foreach ($rows as $r) {
        $newest = (int) $r['newest_id'];
        q('UPDATE conversation_participants SET notified_message_id = GREATEST(notified_message_id, ?) WHERE conversation_id = ? AND user_id = ?', [$newest, $r['conversation_id'], $r['user_id']]);
        if (msg_digest_blocked_address((string) $r['email']) !== null) {
            continue;
        }
        $queued += msg_digest_queue((int) $r['conversation_id'], (int) $r['user_id'], $newest, (int) $r['waiting'], false, $now) ? 1 : 0;
    }
    // Team inbox: member messages no admin has read, once per batch of new messages.
    $team = q_all(
        "SELECT c.id, MAX(m.id) AS newest_id, COUNT(*) AS waiting FROM conversations c
           JOIN messages m ON m.conversation_id = c.id AND m.id > c.team_read_message_id AND m.id > c.team_notified_message_id
           JOIN users su ON su.id = m.sender_id
          WHERE c.kind = 'team' AND su.role IN ('member','group') AND m.deleted_at IS NULL AND m.note_kind IS NULL AND m.created_at <= ?
          GROUP BY c.id ORDER BY c.id LIMIT 200",
        [$cutoff]
    );
    if ($team !== []) {
        $staff = msg_accounts(msg_team_staff_ids());
        foreach ($team as $t) {
            q('UPDATE conversations SET team_notified_message_id = GREATEST(team_notified_message_id, ?) WHERE id = ?', [(int) $t['newest_id'], $t['id']]);
            foreach ($staff as $s) {
                if ((int) $s['message_emails'] === 1 && msg_digest_blocked_address((string) $s['email']) === null) {
                    $queued += msg_digest_queue((int) $t['id'], (int) $s['id'], (int) $t['newest_id'], (int) $t['waiting'], true, $now) ? 1 : 0;
                }
            }
        }
    }
    return $queued;
}

/**
 * Should the digest hide who wrote and what (review: messages must not turn
 * into a spam channel)? Yes for 1-to-1s and groups a member or group
 * started, until the recipient has written in the conversation themselves.
 * Staff and Team inbox messages keep the sender and preview.
 */
function msg_digest_generic(array $conv, int $userId): bool
{
    if (!in_array($conv['kind'], ['direct', 'group'], true)) {
        return false;
    }
    $creator = $conv['created_by'] !== null ? msg_account((int) $conv['created_by']) : null;
    if ($creator !== null && msg_is_staff_role((string) $creator['role'])) {
        return false;
    }
    return q_val('SELECT 1 FROM messages WHERE conversation_id = ? AND sender_id = ? AND note_kind IS NULL LIMIT 1', [$conv['id'], $userId]) === null;
}

/**
 * The digest email for one person and conversation, built from the current
 * state (called when queued and again when sent, so a preview never shows
 * text that was edited or removed in between). Null when nothing to send.
 * @return ?array{email:string,name:string,subject:string,text:string,html:string}
 */
function msg_digest_compose(int $convId, int $userId, bool $teamView): ?array
{
    $acct = msg_account($userId);
    $conv = q_one('SELECT * FROM conversations WHERE id = ?', [$convId]);
    if ($acct === null || $conv === null) {
        return null;
    }
    if ($teamView) {
        $unread = q_all("SELECT m.id, m.sender_id, m.body FROM messages m JOIN users su ON su.id = m.sender_id
            WHERE m.conversation_id = ? AND m.id > ? AND su.role IN ('member','group') AND m.deleted_at IS NULL AND m.note_kind IS NULL ORDER BY m.id", [$convId, $conv['team_read_message_id']]);
    } else {
        $p = q_one('SELECT last_read_message_id, first_visible_id FROM conversation_participants WHERE conversation_id = ? AND user_id = ?', [$convId, $userId]);
        if ($p === null) {
            return null;
        }
        $unread = q_all('SELECT id, sender_id, body FROM messages WHERE conversation_id = ? AND id > ? AND id >= ? AND (sender_id IS NULL OR sender_id <> ?)
            AND deleted_at IS NULL AND note_kind IS NULL ORDER BY id', [$convId, $p['last_read_message_id'], $p['first_visible_id'], $userId]);
    }
    if ($unread === []) {
        return null;
    }
    $latest = $unread[count($unread) - 1];
    $waiting = count($unread);
    $isStaff = msg_is_staff_role((string) $acct['role']);
    $base = $isStaff ? ($teamView ? '/admin/messages/team/' : '/admin/messages/') : '/account/messages/';
    $vars = [
        'first_name' => (string) ($acct['first_name'] ?? explode(' ', msg_account_name($acct))[0]),
        'count' => (string) $waiting,
        'messages_label' => $waiting === 1 ? '1 unread message' : $waiting . ' unread messages',
        'thread_url' => base_url($base . $convId),
        'preferences_url' => base_url($isStaff ? '/admin/messages' : '/account/preferences'),
    ];
    if (!$teamView && msg_digest_generic($conv, $userId)) {
        $key = 'message_digest_new';
    } else {
        $key = 'message_digest';
        $parts = msg_participants([$convId])[$convId] ?? [];
        $vars += [
            'sender' => msg_account_name(msg_account((int) ($latest['sender_id'] ?? 0))),
            'conversation' => msg_conversation_title($conv, $parts, $userId, $teamView),
            'preview' => $latest['body'] !== '' ? excerpt((string) $latest['body'], 160) : 'An attachment',
        ];
    }
    $r = email_template_render($key, $vars);
    return ['email' => (string) $acct['email'], 'name' => msg_account_name($acct), 'subject' => $r['subject'], 'text' => $r['text'], 'html' => $r['html']];
}

/** Build and queue one digest email. */
function msg_digest_queue(int $convId, int $userId, int $newestId, int $waiting, bool $teamView, int $now): bool
{
    $c = msg_digest_compose($convId, $userId, $teamView);
    if ($c === null) {
        return false;
    }
    try {
        $m = new Message($c['email'], $c['name'], $c['subject'], $c['text'], $c['html']);
    } catch (InvalidArgumentException) {
        return false;
    }
    return mail_queue_message($m, 'message', 'msg:' . $convId . ':' . $userId . ':' . $newestId . ($teamView ? ':t' : ''), 0, '', $now);
}

/** Parse a digest dedupe key: [conversation, user, newest message, team view] or null. */
function msg_digest_key(?string $key): ?array
{
    return preg_match('/^msg:(\d+):(\d+):(\d+)(:t)?$/', (string) $key, $k) ? [(int) $k[1], (int) $k[2], (int) $k[3], ($k[4] ?? '') === ':t'] : null;
}

/**
 * Re-checked when the queued digest is sent: not sent when the address was
 * suppressed since, notifications were switched off, the person left, muted,
 * blocked or lost access, the newest message was deleted or removed, or
 * the messages were read in the meantime. Null = send.
 */
function msg_digest_block_reason(array $row): ?string
{
    if (($r = msg_digest_blocked_address((string) $row['to_email'])) !== null) {
        return $r;
    }
    $k = msg_digest_key($row['dedupe_key'] ?? null);
    if ($k === null) {
        return null;
    }
    [$convId, $userId, $newestId, $team] = $k;
    $u = q_one('SELECT id, role, status, message_emails FROM users WHERE id = ?', [$userId]);
    if ($u === null || $u['status'] !== 'active') {
        return 'Not sent: the account is no longer active.';
    }
    if ((int) $u['message_emails'] !== 1) {
        return 'Not sent: message notifications were switched off.';
    }
    if (q_val('SELECT 1 FROM messages WHERE id = ? AND deleted_at IS NULL', [$newestId]) === null) {
        return 'Not sent: the message was deleted or removed.';
    }
    if ($team) {
        $u['permissions'] = array_column(q_all('SELECT permission FROM admin_permissions WHERE user_id = ?', [$userId]), 'permission');
        if (!user_can($u, 'messages')) {
            return 'Not sent: no longer answers the Team inbox.';
        }
        $read = (int) q_val('SELECT team_read_message_id FROM conversations WHERE id = ?', [$convId]);
    } else {
        $conv = msg_conversation_for($convId, $userId); // left, removed or hidden by a block = null
        if ($conv === null) {
            return 'Not sent: the person no longer sees this conversation.';
        }
        if ((int) $conv['p_muted'] === 1) {
            return 'Not sent: the conversation was muted.';
        }
        $read = (int) $conv['p_read'];
    }
    return $read >= $newestId ? 'Not sent: the messages were read in the meantime.' : null;
}

/** Fresh subject and body for a queued digest at send time (null: keep the queued copy). */
function msg_digest_rebuild(array $row): ?array
{
    $k = msg_digest_key($row['dedupe_key'] ?? null);
    return $k === null ? null : msg_digest_compose($k[0], $k[1], $k[3]);
}

// ---- Retention, export, deletion ---------------------------------------------------------

/** Delete messages older than the retention setting (0 = keep forever). Returns messages deleted. */
function msg_retention_run(?int $now = null): int
{
    $months = msg_settings()['retention_months'];
    if ($months <= 0) {
        return 0;
    }
    $cutoff = (new DateTimeImmutable('@' . ($now ?? time())))->modify('-' . $months . ' months')->format('Y-m-d H:i:s');
    $n = 0;
    do {
        $rows = q_all('SELECT id, attachment_media_id FROM messages WHERE created_at < ? ORDER BY id LIMIT 500', [$cutoff]);
        foreach ($rows as $r) {
            q('DELETE FROM messages WHERE id = ?', [$r['id']]);
            if ($r['attachment_media_id'] !== null) {
                msg_delete_media((int) $r['attachment_media_id']);
            }
            $n++;
        }
    } while (count($rows) === 500);
    if ($n > 0) {
        // Conversations keep pointing at their newest remaining message; empty ones go.
        q('UPDATE conversations c SET c.last_message_id = (SELECT MAX(m.id) FROM messages m WHERE m.conversation_id = c.id)');
        q('DELETE FROM conversations WHERE last_message_id IS NULL AND created_at < ?', [$cutoff]);
    }
    return $n;
}

/** The person's own messages for the data export. */
function msg_export(int $userId): array
{
    $rows = q_all(
        'SELECT m.conversation_id, c.kind, c.title, m.body, m.created_at, m.edited_at, m.deleted_at, md.original_name AS attachment
           FROM messages m JOIN conversations c ON c.id = m.conversation_id LEFT JOIN media md ON md.id = m.attachment_media_id
          WHERE m.sender_id = ? AND m.note_kind IS NULL ORDER BY m.id',
        [$userId]
    );
    return array_map(static fn (array $r): array => [
        'conversation' => (int) $r['conversation_id'],
        'type' => $r['kind'] === 'team' ? 'team inbox' : (string) $r['kind'],
        'title' => $r['title'],
        'text' => $r['deleted_at'] !== null ? '(deleted)' : (string) $r['body'],
        'attachment' => $r['attachment'],
        'sent_at' => $r['created_at'],
        'edited_at' => $r['edited_at'],
    ], $rows);
}

/**
 * Account deletion: the person's messages lose their content (shown as
 * "message deleted" from "Former member" once the account row is gone).
 * Returns attachment media ids to delete after the transaction.
 */
function msg_account_purge(int $userId): array
{
    $media = array_map('intval', array_column(q_all('SELECT attachment_media_id FROM messages WHERE sender_id = ? AND attachment_media_id IS NOT NULL', [$userId]), 'attachment_media_id'));
    q("UPDATE messages SET body = '', attachment_media_id = NULL, deleted_at = COALESCE(deleted_at, UTC_TIMESTAMP()), deleted_by = COALESCE(deleted_by, 'account') WHERE sender_id = ? AND note_kind IS NULL", [$userId]);
    // Team inbox threads of a deleted member have nobody left to answer.
    $team = array_map('intval', array_column(q_all("SELECT id FROM conversations WHERE kind = 'team' AND pair_key = ?", ['t:' . $userId]), 'id'));
    foreach ($team as $id) {
        foreach (q_all('SELECT attachment_media_id FROM messages WHERE conversation_id = ? AND attachment_media_id IS NOT NULL', [$id]) as $r) {
            $media[] = (int) $r['attachment_media_id'];
        }
        q('DELETE FROM conversations WHERE id = ?', [$id]);
    }
    q('UPDATE conversations SET pair_key = NULL WHERE pair_key LIKE ? OR pair_key LIKE ?', ['d:' . $userId . ':%', 'd:%:' . $userId]);
    return $media;
}
