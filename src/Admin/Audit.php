<?php
declare(strict_types=1);

namespace Admin;

/** Audit log (§7, §11, super admin): who changed what, and when. */
final class Audit
{
    /**
     * Filters from the query string, validated.
     * @return array{user:string, action:string, item:string, from:string, to:string, q:string}
     */
    public static function filters(): array
    {
        $date = static fn (string $v): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
        $word = static fn (string $v): string => preg_match('/^[a-z_-]{1,40}$/', $v) ? $v : '';
        $user = query_param('user');
        return [
            'user' => preg_match('/^(\d{1,9}|system)$/', $user) ? $user : '',
            'action' => $word(query_param('action')),
            'item' => $word(query_param('item')),
            'from' => $date(query_param('from')),
            'to' => $date(query_param('to')),
            'q' => mb_substr(query_param('q'), 0, 100),
        ];
    }

    /** @return array{0:string,1:list<mixed>} WHERE clause and params */
    public static function where(array $f): array
    {
        $w = [];
        $params = [];
        if ($f['user'] === 'system') {
            $w[] = 'a.user_id IS NULL';
        } elseif ($f['user'] !== '') {
            $w[] = 'a.user_id = ?';
            $params[] = (int) $f['user'];
        }
        if ($f['action'] !== '') {
            $w[] = 'a.action = ?';
            $params[] = $f['action'];
        }
        if ($f['item'] !== '') {
            $w[] = 'a.item_type = ?';
            $params[] = $f['item'];
        }
        $tz = site_tz();
        if ($f['from'] !== '') {
            $w[] = 'a.created_at >= ?';
            $params[] = (new \DateTimeImmutable($f['from'] . ' 00:00', $tz))->setTimezone(utc())->format('Y-m-d H:i:s');
        }
        if ($f['to'] !== '') {
            $w[] = 'a.created_at < ?';
            $params[] = (new \DateTimeImmutable($f['to'] . ' 00:00', $tz))->modify('+1 day')->setTimezone(utc())->format('Y-m-d H:i:s');
        }
        if ($f['q'] !== '') {
            $w[] = 'a.summary LIKE ?';
            $params[] = '%' . addcslashes($f['q'], '%_\\') . '%';
        }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $params];
    }

    public static function index(array $p): void
    {
        require_permission('super_admin');
        $f = self::filters();
        [$where, $params] = self::where($f);
        $pg = paging(50);
        $rows = q_all("SELECT a.*, u.display_name, u.email FROM audit_log a LEFT JOIN users u ON u.id = a.user_id $where ORDER BY a.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);
        $total = (int) q_val("SELECT COUNT(*) FROM audit_log a $where", $params);
        render('admin/audit', [
            'title' => 'Audit log', 'rows' => $rows, 'total' => $total, 'filters' => $f, 'page' => $pg['page'], 'per' => $pg['per'],
            'people' => q_all('SELECT DISTINCT u.id, u.display_name FROM audit_log a JOIN users u ON u.id = a.user_id ORDER BY u.display_name LIMIT 300'),
            'actions' => array_column(q_all('SELECT DISTINCT action FROM audit_log ORDER BY action'), 'action'),
            'items' => array_column(q_all("SELECT DISTINCT item_type FROM audit_log WHERE item_type <> '' ORDER BY item_type"), 'item_type'),
        ], 'admin');
    }

    public static function csv(array $p): void
    {
        require_permission('super_admin');
        [$where, $params] = self::where(self::filters());
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit-log-' . gmdate('Ymd') . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['When (UTC)', 'Who', 'Email', 'Action', 'Item type', 'Item id', 'Summary', 'IP'], ',', '"', '');
        $stmt = q("SELECT a.*, u.display_name, u.email FROM audit_log a LEFT JOIN users u ON u.id = a.user_id $where ORDER BY a.id DESC LIMIT 50000", $params);
        while ($r = $stmt->fetch()) {
            fputcsv($out, array_map(static fn ($v): string => csv_safe((string) $v), [
                $r['created_at'], $r['display_name'] ?? 'System', $r['email'] ?? '', $r['action'], $r['item_type'], $r['item_id'] ?? '', $r['summary'], $r['ip'],
            ]), ',', '"', '');
        }
        fclose($out);
        audit('export', 'audit_log', null, 'audit log CSV');
    }
}
