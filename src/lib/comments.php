<?php
declare(strict_types=1);

/*
 * Likes and comments (§4.7, D11). Members only. One like per member per
 * item (toggle). Comments are plain text with one level of replies, can be
 * edited for 15 minutes, reported, and are pre-moderated by default
 * (setting: post-moderated). Items: posts (likes + comments, each switched
 * on per post) and downloads (likes).
 */

const COMMENT_EDIT_MINUTES = 15;
const COMMENT_MAX = 2000;

/** item type => [table, supports comments] */
function engagement_items(): array
{
    return ['post' => ['posts', true], 'download' => ['downloads', false]];
}

function comments_moderation(): string
{
    return setting('comments.moderation', 'pre') === 'post' ? 'post' : 'pre';
}

/** The item row if it exists, is published, the viewer may see it and the feature is on. */
function engagement_item(string $type, int $id, string $feature, ?array $viewer): ?array
{
    if (!isset(engagement_items()[$type])) {
        return null;
    }
    [$table, $comments] = engagement_items()[$type];
    if ($feature === 'comments' && !$comments) {
        return null;
    }
    $row = q_one('SELECT * FROM ' . db_ident($table) . ' t WHERE t.id = ? AND ' . published_sql('t') . ' AND ' . visibility_sql('t', $viewer), [$id]);
    if ($row === null) {
        return null;
    }
    if ($type === 'post' && (($feature === 'likes' && !(int) $row['allow_likes']) || ($feature === 'comments' && !(int) $row['allow_comments']))) {
        return null;
    }
    return $row;
}

function engagement_item_url(string $type, array $row): string
{
    return match ($type) {
        'post' => post_url($row + ['base' => (string) q_val('SELECT base FROM categories WHERE id = ?', [$row['category_id']])]),
        'download' => '/downloads#download-' . (int) $row['id'],
        default => '/',
    };
}

// ---- Likes ---------------------------------------------------------------------

/** Toggle a like. Returns [liked, count]. The unique key makes a second like impossible. */
function like_toggle(int $userId, string $type, int $id): array
{
    $deleted = q('DELETE FROM likes WHERE user_id = ? AND item_type = ? AND item_id = ?', [$userId, $type, $id])->rowCount();
    if ($deleted === 0) {
        q('INSERT IGNORE INTO likes (user_id, item_type, item_id) VALUES (?, ?, ?)', [$userId, $type, $id]);
    }
    return [$deleted === 0, like_count($type, $id)];
}

function like_count(string $type, int $id): int
{
    return (int) q_val('SELECT COUNT(*) FROM likes WHERE item_type = ? AND item_id = ?', [$type, $id]);
}

function user_liked(?int $userId, string $type, int $id): bool
{
    return $userId !== null && q_val('SELECT 1 FROM likes WHERE user_id = ? AND item_type = ? AND item_id = ?', [$userId, $type, $id]) !== null;
}

/**
 * Like and approved-comment counts for many items at once (cards).
 * @param list<int> $ids
 */
function engagement_preload(string $type, array $ids): void
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $cache = &engagement_cache();
    $missing = array_values(array_filter($ids, static fn (int $id): bool => !isset($cache[$type][$id])));
    if ($missing === []) {
        return;
    }
    foreach ($missing as $id) {
        $cache[$type][$id] = ['likes' => 0, 'comments' => 0];
    }
    $in = db_placeholders(count($missing));
    foreach (q_all("SELECT item_id, COUNT(*) AS n FROM likes WHERE item_type = ? AND item_id IN ($in) GROUP BY item_id", array_merge([$type], $missing)) as $r) {
        $cache[$type][(int) $r['item_id']]['likes'] = (int) $r['n'];
    }
    // Visible comments only: not deleted, and replies only when their parent is still published.
    foreach (q_all("SELECT c.item_id, COUNT(*) AS n FROM comments c WHERE c.item_type = ? AND c.status = 'approved' AND c.deleted_at IS NULL AND c.item_id IN ($in)
                     AND (c.parent_id IS NULL OR EXISTS (SELECT 1 FROM comments p WHERE p.id = c.parent_id AND p.status = 'approved'))
                   GROUP BY c.item_id", array_merge([$type], $missing)) as $r) {
        $cache[$type][(int) $r['item_id']]['comments'] = (int) $r['n'];
    }
}

function &engagement_cache(): array
{
    static $cache = [];
    return $cache;
}

/** @return array{likes:int, comments:int} */
function engagement_counts(string $type, int $id): array
{
    engagement_preload($type, [$id]);
    return engagement_cache()[$type][$id];
}

function engagement_forget(): void
{
    $c = &engagement_cache();
    $c = [];
}

// ---- Comments -------------------------------------------------------------------

/**
 * Add a comment or reply. Returns [comment id|null, error|null].
 */
function comment_add(array $user, string $type, int $itemId, string $body, ?int $parentId = null): array
{
    $body = trim(str_replace("\r\n", "\n", $body));
    if ($body === '' || mb_strlen($body) > COMMENT_MAX) {
        return [null, 'Write a comment of up to ' . number_format(COMMENT_MAX) . ' characters.'];
    }
    if (!viewer_is_member($user)) {
        return [null, 'Only members can comment.'];
    }
    if (engagement_item($type, $itemId, 'comments', $user) === null) {
        return [null, 'Comments are not open here.'];
    }
    if ($parentId !== null) {
        $parent = q_one("SELECT * FROM comments WHERE id = ? AND item_type = ? AND item_id = ? AND status = 'approved' AND deleted_at IS NULL", [$parentId, $type, $itemId]);
        if ($parent === null) {
            return [null, 'The comment you replied to is not available.'];
        }
        if ($parent['parent_id'] !== null) {
            // One level of replies: a reply to a reply joins the same thread.
            $parentId = (int) $parent['parent_id'];
        }
    }
    if (rate_limited('comment:' . $user['id'], 10, 600)) {
        return [null, 'You have posted several comments in a short time. Please wait a few minutes.'];
    }
    rate_hit('comment:' . $user['id']);
    $status = is_staff($user) || comments_moderation() === 'post' ? 'approved' : 'pending';
    $id = db_insert('comments', ['item_type' => $type, 'item_id' => $itemId, 'user_id' => $user['id'], 'parent_id' => $parentId, 'body' => $body, 'status' => $status]);
    if ($status === 'approved') {
        comment_notify_reply($id);
    }
    return [$id, null];
}

/** Can this user still edit the comment? (owner, within 15 minutes, not rejected) */
function comment_editable(array $comment, ?array $user, ?DateTimeImmutable $now = null): bool
{
    if ($user === null || (int) ($comment['user_id'] ?? 0) !== (int) $user['id'] || $comment['status'] === 'rejected') {
        return false;
    }
    $now ??= new DateTimeImmutable('now', utc());
    $created = new DateTimeImmutable((string) $comment['created_at'], utc());
    return $now->getTimestamp() - $created->getTimestamp() < COMMENT_EDIT_MINUTES * 60;
}

/** Edit within the window. Pre-moderated sites send edited comments back to the queue. */
function comment_edit(int $id, array $user, string $body, ?DateTimeImmutable $now = null): ?string
{
    $c = q_one('SELECT * FROM comments WHERE id = ?', [$id]);
    if ($c === null || !comment_editable($c, $user, $now)) {
        return 'This comment can no longer be edited (comments can be changed for ' . COMMENT_EDIT_MINUTES . ' minutes).';
    }
    $body = trim(str_replace("\r\n", "\n", $body));
    if ($body === '' || mb_strlen($body) > COMMENT_MAX) {
        return 'Write a comment of up to ' . number_format(COMMENT_MAX) . ' characters.';
    }
    $status = $c['status'] === 'approved' && comments_moderation() === 'pre' && !is_staff($user) ? 'pending' : $c['status'];
    q('UPDATE comments SET body = ?, status = ?, edited_at = ? WHERE id = ?', [$body, $status, ($now ?? new DateTimeImmutable('now', utc()))->setTimezone(utc())->format('Y-m-d H:i:s'), $id]);
    return null;
}

function comment_delete_own(int $id, array $user): bool
{
    return comment_remove($id, (int) $user['id']);
}

/**
 * Remove a comment. One that other members replied to is blanked and shown
 * as "[deleted]" so their replies stay; others are deleted. With $ownerId
 * only that member's comment can be removed. Returns false when not found.
 */
function comment_remove(int $id, ?int $ownerId = null): bool
{
    return db_tx(static function () use ($id, $ownerId): bool {
        $c = q_one('SELECT id, user_id, parent_id FROM comments WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id]);
        if ($c === null || ($ownerId !== null && (int) ($c['user_id'] ?? 0) !== $ownerId)) {
            return false;
        }
        if ($c['parent_id'] === null && q_val('SELECT 1 FROM comments WHERE parent_id = ? LIMIT 1', [$id]) !== null) {
            q("UPDATE comments SET body = '', user_id = NULL, deleted_at = UTC_TIMESTAMP() WHERE id = ?", [$id]);
            q('UPDATE comment_reports SET resolved_at = UTC_TIMESTAMP() WHERE comment_id = ? AND resolved_at IS NULL', [$id]);
            return true;
        }
        q('DELETE FROM comments WHERE id = ?', [$id]);
        // A blanked parent whose last reply is gone has nothing left to show.
        if ($c['parent_id'] !== null && q_val('SELECT 1 FROM comments WHERE parent_id = ? LIMIT 1', [$c['parent_id']]) === null) {
            q('DELETE FROM comments WHERE id = ? AND deleted_at IS NOT NULL', [$c['parent_id']]);
        }
        return true;
    });
}

function comment_report(int $id, int $userId, string $reason): bool
{
    if (q_val("SELECT 1 FROM comments WHERE id = ? AND status = 'approved' AND deleted_at IS NULL", [$id]) === null) {
        return false;
    }
    return q('INSERT IGNORE INTO comment_reports (comment_id, user_id, reason) VALUES (?, ?, ?)', [$id, $userId, mb_substr($reason, 0, 500)])->rowCount() === 1;
}

/** Moderation action: approve, reject, or keep (clear reports). */
function comment_moderate(int $id, string $action, int $adminId): bool
{
    $c = q_one('SELECT * FROM comments WHERE id = ?', [$id]);
    if ($c === null) {
        return false;
    }
    switch ($action) {
        case 'approve':
            q("UPDATE comments SET status = 'approved', reviewed_by = ? WHERE id = ?", [$adminId, $id]);
            q('UPDATE comment_reports SET resolved_at = UTC_TIMESTAMP() WHERE comment_id = ? AND resolved_at IS NULL', [$id]);
            comment_notify_reply($id);
            return true;
        case 'reject':
            q("UPDATE comments SET status = 'rejected', reviewed_by = ? WHERE id = ?", [$adminId, $id]);
            q('UPDATE comment_reports SET resolved_at = UTC_TIMESTAMP() WHERE comment_id = ? AND resolved_at IS NULL', [$id]);
            return true;
        case 'delete':
            return comment_remove($id);
    }
    return false;
}

/** Email the parent comment's author once when a reply is published. */
function comment_notify_reply(int $id): void
{
    $c = q_one("SELECT c.*, u.display_name FROM comments c LEFT JOIN users u ON u.id = c.user_id WHERE c.id = ? AND c.status = 'approved' AND c.parent_id IS NOT NULL AND c.reply_notified = 0", [$id]);
    if ($c === null) {
        return;
    }
    q('UPDATE comments SET reply_notified = 1 WHERE id = ?', [$id]);
    $parent = q_one("SELECT c.user_id, u.email, u.status FROM comments c JOIN users u ON u.id = c.user_id WHERE c.id = ?", [$c['parent_id']]);
    if ($parent === null || $parent['status'] !== 'active' || (int) $parent['user_id'] === (int) $c['user_id']) {
        return;
    }
    [$table] = engagement_items()[$c['item_type']] ?? [null];
    $item = $table !== null ? q_one('SELECT * FROM ' . db_ident($table) . ' WHERE id = ?', [$c['item_id']]) : null;
    if ($item === null) {
        return;
    }
    $m = member_for_user((int) $parent['user_id']);
    send_template_mail((string) $parent['email'], (string) ($m['first_name'] ?? ''), 'comment_reply', [
        'replier' => comment_author_name($c), 'item_title' => (string) $item['title'], 'reply' => (string) $c['body'],
        'item_url' => base_url(engagement_item_url($c['item_type'], $item) . '#comment-' . $id),
    ]);
}

/** "First L." for members; "Former member" when the account was deleted. */
function comment_author_name(array $c): string
{
    if (($c['user_id'] ?? null) === null) {
        return 'Former member';
    }
    $m = member_for_user((int) $c['user_id']);
    if ($m !== null) {
        $last = trim((string) $m['last_name']);
        return trim($m['first_name'] . ($last !== '' ? ' ' . mb_substr($last, 0, 1) . '.' : ''));
    }
    return (string) ($c['display_name'] ?? q_val('SELECT display_name FROM users WHERE id = ?', [$c['user_id']]) ?? 'Member');
}

/**
 * Thread for an item: approved comments plus the viewer's own pending ones.
 * @return list<array> top-level comments, each with 'replies'
 */
function comment_thread(string $type, int $itemId, ?array $viewer): array
{
    $uid = $viewer['id'] ?? 0;
    $rows = q_all(
        "SELECT c.*, u.display_name, u.role, (SELECT COUNT(*) FROM comment_reports r WHERE r.comment_id = c.id AND r.user_id = ?) AS reported_by_me
           FROM comments c LEFT JOIN users u ON u.id = c.user_id
          WHERE c.item_type = ? AND c.item_id = ? AND (c.status = 'approved' OR (c.status = 'pending' AND c.user_id = ?))
          ORDER BY c.created_at, c.id",
        [$uid, $type, $itemId, $uid]
    );
    $top = [];
    $replies = [];
    foreach ($rows as $r) {
        $r['author'] = $r['deleted_at'] !== null ? '' : comment_author_name($r);
        if ($r['parent_id'] === null) {
            $r['replies'] = [];
            $top[(int) $r['id']] = $r;
        } else {
            $replies[] = $r;
        }
    }
    foreach ($replies as $r) {
        if (isset($top[(int) $r['parent_id']])) {
            $top[(int) $r['parent_id']]['replies'][] = $r;
        }
    }
    // A deleted comment is only shown (as "[deleted]") to keep its replies in context.
    return array_values(array_filter($top, static fn (array $c): bool => $c['deleted_at'] === null || $c['replies'] !== []));
}
