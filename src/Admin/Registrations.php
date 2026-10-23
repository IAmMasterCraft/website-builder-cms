<?php
declare(strict_types=1);

namespace Admin;

final class Registrations
{
    private static function event(array $p): array
    {
        $e = q_one('SELECT * FROM events WHERE id = ?', [(int) $p['id']]);
        if ($e === null) {
            abort(404);
        }
        return $e;
    }

    private static function rows(int $eventId): array
    {
        return q_all(
            'SELECT r.*, t.name AS ticket_name FROM event_registrations r LEFT JOIN event_tickets t ON t.id = r.ticket_id
             WHERE r.event_id = ? ORDER BY r.created_at, r.id',
            [$eventId]
        );
    }

    public static function index(array $p): void
    {
        require_permission('events');
        $event = self::event($p);
        $rows = self::rows((int) $event['id']);
        $totals = ['registered' => 0, 'attended' => 0, 'cancelled' => 0];
        foreach ($rows as $r) {
            $totals[$r['status']] += (int) $r['quantity'];
        }
        render('admin/registrations', ['title' => 'Registrations', 'event' => $event, 'rows' => $rows, 'totals' => $totals], 'admin');
    }

    /** Neutralise spreadsheet formulas in CSV cells. */
    public static function csvCell(string $v): string
    {
        return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    }

    public static function csv(array $p): void
    {
        require_permission('events');
        $event = self::event($p);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="registrations-' . $event['slug'] . '.csv"');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Name', 'Email', 'Phone', 'Ticket', 'Quantity', 'Status', 'Member', 'Registered at (' . site_tz()->getName() . ')'], ',', '"', '');
        foreach (self::rows((int) $event['id']) as $r) {
            fputcsv($out, array_map([self::class, 'csvCell'], [
                (string) $r['name'], (string) $r['email'], (string) $r['phone'], (string) ($r['ticket_name'] ?? ''),
                (string) $r['quantity'], (string) $r['status'], $r['user_id'] ? 'yes' : 'no', fmt_date($r['created_at'], 'Y-m-d H:i'),
            ]), ',', '"', '');
        }
        fclose($out);
        audit('export', 'event', (int) $event['id'], 'registrations CSV');
    }

    public static function setStatus(array $p): void
    {
        csrf_check();
        require_permission('events');
        $event = self::event($p);
        $status = input('status');
        if (!in_array($status, ['registered', 'attended', 'cancelled'], true)) {
            abort(400);
        }
        $n = q('UPDATE event_registrations SET status = ? WHERE id = ? AND event_id = ?', [$status, (int) $p['reg'], $event['id']])->rowCount();
        if ($n > 0) {
            audit('status', 'registration', (int) $p['reg'], $status);
        }
        if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            json_out(['ok' => true, 'status' => $status]);
        }
        flash('Registration updated.');
        redirect('/admin/events/' . $event['id'] . '/registrations');
    }
}
