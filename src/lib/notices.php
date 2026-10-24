<?php
declare(strict_types=1);

use Core\Mail\Message;

/*
 * Notices (§5): announcements shown in member dashboards to an audience,
 * with an optional email copy (queued; honours email preferences and
 * unsubscribes like any bulk email). notice_reads records who read what.
 */

/**
 * Published notices this user is in the audience of, newest first, with read state.
 * Audience membership is checked once per distinct audience (most notices share a
 * few), and the result is kept for the request: the badge, the dashboard widget and
 * the list on one page cost one notices query plus one query per distinct audience.
 */
function notices_for_user(int $userId, int $limit = 50): array
{
    $cache = &notices_cache();
    if (!isset($cache[$userId])) {
        $rows = q_all(
            "SELECT n.*, r.read_at FROM notices n LEFT JOIN notice_reads r ON r.notice_id = n.id AND r.user_id = ?
              WHERE n.status = 'published' ORDER BY n.published_at DESC, n.id DESC LIMIT 200",
            [$userId]
        );
        $inAudience = [];
        $list = [];
        foreach ($rows as $n) {
            $a = audience_decode($n['audience']);
            $key = json_encode($a, JSON_THROW_ON_ERROR);
            $inAudience[$key] ??= audience_includes_user($a, $userId);
            if ($inAudience[$key]) {
                $list[] = $n;
            }
        }
        $cache[$userId] = $list;
    }
    return array_slice($cache[$userId], 0, $limit);
}

/** Per-request cache of notices_for_user(); cleared when read state or notices change. */
function &notices_cache(): array
{
    static $cache = [];
    return $cache;
}

function notices_forget(): void
{
    $c = &notices_cache();
    $c = [];
}

function notices_unread_count(int $userId): int
{
    return count(array_filter(notices_for_user($userId), static fn (array $n): bool => $n['read_at'] === null));
}

/** Mark read (only notices the user can see). Returns how many were newly marked. */
function notice_mark_read(int $userId, ?int $noticeId = null): int
{
    $n = 0;
    foreach (notices_for_user($userId, 200) as $notice) {
        if ($notice['read_at'] === null && ($noticeId === null || (int) $notice['id'] === $noticeId)) {
            $n += q('INSERT IGNORE INTO notice_reads (notice_id, user_id) VALUES (?, ?)', [$notice['id'], $userId])->rowCount();
        }
    }
    notices_forget();
    return $n;
}

/** Publish a notice; queues the email copy once when asked. Returns emails queued. */
function notice_publish(int $id, ?int $now = null): int
{
    $now ??= time();
    $n = q_one('SELECT * FROM notices WHERE id = ?', [$id]);
    if ($n === null) {
        return 0;
    }
    if ($n['status'] !== 'published') {
        q("UPDATE notices SET status = 'published', published_at = ? WHERE id = ?", [gmdate('Y-m-d H:i:s', $now), $id]);
    }
    notices_forget();
    if (!(bool) $n['email_copy'] || $n['emailed_at'] !== null) {
        return 0;
    }
    $c = email_colours();
    $bodyHtml = '<h2 class="email-h1" style="margin:0 0 14px;font-size:24px;line-height:1.25;color:#14202e">' . e($n['title']) . '</h2>'
        . email_style_rich(rich($n['body']), $c)
        . email_button('Open your dashboard', base_url('/account/notices'), $c);
    $queued = 0;
    foreach (audience_resolve(audience_decode($n['audience']), true, new DateTimeImmutable('@' . $now))['recipients'] as $r) {
        if (email_domain_status(email_domain($r['email'])) === 'none') {
            continue; // A5: never queue an address whose domain cannot receive mail
        }
        $html = email_layout($bodyHtml, ['colours' => $c, 'unsubscribe_url' => unsubscribe_url($r['email']), 'preheader' => excerpt($n['body'], 120),
            'footer_note' => 'A notice from ' . setting('site.name') . ' to members. It is also in your dashboard.']);
        $text = $n['title'] . "\n\n" . html_to_text(rich($n['body'])) . "\n\nOpen your dashboard: " . base_url('/account/notices') . "\nUnsubscribe: " . unsubscribe_url($r['email']) . "\n" . email_prefs_text() . "\n";
        try {
            $m = new Message($r['email'], $r['name'], 'Notice: ' . $n['title'], $text, $html, null, bulk_headers($r['email']));
        } catch (InvalidArgumentException) {
            continue;
        }
        // The dedupe key makes a second publish (or a retry after a crash half-way) queue nobody twice;
        // the address is hashed so long addresses fit the 190-character key.
        $queued += mail_queue_message($m, 'notice', 'notice:' . $id . ':' . hash('sha256', strtolower($r['email'])), 0, '', $now) ? 1 : 0;
    }
    // Only marked once every copy is queued, so an interrupted run is completed by the next publish.
    q('UPDATE notices SET emailed_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', $now), $id]);
    return $queued;
}

/** Read counts for the admin list. */
function notice_read_count(int $id): int
{
    return (int) q_val('SELECT COUNT(*) FROM notice_reads WHERE notice_id = ?', [$id]);
}
