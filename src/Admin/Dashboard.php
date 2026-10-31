<?php
declare(strict_types=1);

namespace Admin;

final class Dashboard
{
    /** Overview counts (§7). Public so tests can check them. */
    public static function counts(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', utc());
        $weekAgo = $now->modify('-7 days')->format('Y-m-d H:i:s');
        $nowUtc = $now->format('Y-m-d H:i:s');
        return [
            'members_active' => (int) q_val("SELECT COUNT(*) FROM users WHERE role IN ('member','group') AND status = 'active'"),
            'members_unverified' => (int) q_val("SELECT COUNT(*) FROM users WHERE role IN ('member','group') AND status = 'unverified'"),
            'members_new_week' => (int) q_val("SELECT COUNT(*) FROM users WHERE role IN ('member','group') AND created_at >= ?", [$weekAgo]),
            'events_upcoming' => (int) q_val('SELECT COUNT(*) FROM events WHERE ' . published_sql() . ' AND COALESCE(end_at, start_at) >= ?', [$nowUtc]),
            'registrations_week' => (int) q_val("SELECT COALESCE(SUM(quantity), 0) FROM event_registrations WHERE status <> 'cancelled' AND created_at >= ?", [$weekAgo]),
            'messages_unread' => (int) q_val('SELECT COUNT(*) FROM contact_messages WHERE read_at IS NULL'),
            'comments_pending' => comments_reported_count(),
            'jobs_pending' => (int) q_val("SELECT COUNT(*) FROM jobs WHERE status = 'pending'"),
            'team_unread' => msg_team_unread_count(),
            'message_reports' => msg_reports_open_count(),
            'posts_published' => (int) q_val('SELECT COUNT(*) FROM posts WHERE ' . published_sql()),
            'scheduled' => (int) q_val("SELECT (SELECT COUNT(*) FROM posts WHERE status = 'scheduled') + (SELECT COUNT(*) FROM events WHERE status = 'scheduled') + (SELECT COUNT(*) FROM pages WHERE status = 'scheduled')"),
        ];
    }

    /** Members whose birthday is today in the site timezone (29 Feb counts on 28 Feb in non-leap years). */
    public static function birthdaysToday(?\DateTimeImmutable $now = null): array
    {
        $local = ($now ?? new \DateTimeImmutable('now', utc()))->setTimezone(site_tz());
        $day = (int) $local->format('j');
        $month = (int) $local->format('n');
        $leap = $local->format('L') === '1';
        $extra = (!$leap && $month === 2 && $day === 28) ? ' OR (m.birth_month = 2 AND m.birth_day = 29)' : '';
        return q_all(
            "SELECT m.first_name, m.last_name, m.member_number FROM members m JOIN users u ON u.id = m.user_id
             WHERE u.status = 'active' AND ((m.birth_month = ? AND m.birth_day = ?)$extra) ORDER BY m.first_name LIMIT 50",
            [$month, $day]
        );
    }

    public static function overview(array $p): void
    {
        $user = require_staff();
        render('admin/overview', self::overviewData($user), 'admin');
    }

    /**
     * Overview panels for one staff user: everyone's recent activity is for super
     * admins (others see their own actions); birthdays need the members permission.
     */
    public static function overviewData(array $user): array
    {
        $all = user_can($user, 'super_admin');
        $activity = q_all(
            'SELECT a.*, u.display_name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id' . ($all ? '' : ' WHERE a.user_id = ?') . ' ORDER BY a.id DESC LIMIT 12',
            $all ? [] : [$user['id']]
        );
        $cron = setting('cron.last_run');
        // D1: birthday card with Today / This week / This month tabs (?bday=week|month, works without JS).
        $birthdays = null;
        if (user_can($user, 'members') || birthday_is_recipient($user)) {
            $tab = in_array(query_param('bday'), ['week', 'month'], true) ? query_param('bday') : 'today';
            $today = (new \DateTimeImmutable('now', site_tz()))->setTime(0, 0);
            $birthdays = [
                'tab' => $tab, 'rows' => birthday_period_members($tab, $today), 'today' => $today->format('Y-m-d'),
                'counts' => ['today' => birthday_count('today', $today), 'week' => birthday_count('week', $today), 'month' => birthday_count('month', $today)],
            ];
        }
        return [
            'title' => 'Overview',
            'counts' => self::counts(),
            'birthdays' => $birthdays,
            'payments' => user_can($user, 'payments') ? payment_dashboard() : null,
            'activity' => $activity,
            'activity_all' => $all,
            'user' => $user,
            'cron_last' => is_string($cron) ? $cron : null,
            'cron_line' => health_detected_cron_line(),
        ];
    }

    public static function search(array $p): void
    {
        $user = require_staff();
        $q = mb_substr(query_param('q'), 0, 100);
        $results = [];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            foreach (content_types() as $key => $type) {
                if (!user_can($user, $type['permission'])) {
                    continue;
                }
                foreach (q_all('SELECT id, ' . db_ident($type['title']) . ' AS t FROM ' . db_ident($type['table']) . ' WHERE ' . db_ident($type['title']) . ' LIKE ? ORDER BY id DESC LIMIT 10', [$like]) as $r) {
                    $results[] = ['type' => $type['label'], 'title' => (string) $r['t'], 'url' => '/admin/' . $key . '/' . $r['id'] . '/edit'];
                }
            }
            if (user_can($user, 'gallery_media')) {
                foreach (q_all('SELECT id, title FROM media WHERE (title LIKE ? OR original_name LIKE ?) AND visibility <> \'private\' AND folder <> \'member-photos\' ORDER BY id DESC LIMIT 10', [$like, $like]) as $r) {
                    $results[] = ['type' => 'Media', 'title' => (string) $r['title'], 'url' => '/admin/media/' . $r['id']];
                }
            }
        }
        render('admin/search', ['title' => 'Search', 'q' => $q, 'results' => $results], 'admin');
    }
}
