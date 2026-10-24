<?php
declare(strict_types=1);

namespace Admin;

/**
 * Backups (§7, super admin): download a database dump and a ZIP of the
 * uploads (both streamed), schedule a daily backup into storage/backups
 * with a retention count, download or delete stored backups.
 */
final class Backups
{
    public static function index(array $p): void
    {
        require_permission('super_admin');
        $old = take_old_input();
        render('admin/backups', [
            'title' => 'Backups', 'settings' => $old['values'] + backup_settings(), 'errors' => $old['errors'],
            'stored' => backup_list(), 'uploads' => backup_uploads_size(),
            'db_size' => (int) q_val('SELECT COALESCE(SUM(data_length), 0) FROM information_schema.TABLES WHERE table_schema = DATABASE()'),
            'cron_ok' => health_cron_running(), 'gzip' => function_exists('deflate_init'),
        ], 'admin');
    }

    /** POST so the download is CSRF-protected and audited; the response streams. */
    public static function downloadDb(array $p): void
    {
        csrf_check();
        require_permission('super_admin');
        $gzip = function_exists('deflate_init');
        audit('download', 'backup', null, 'database dump');
        backup_send_headers('gather-' . gmdate('Ymd-His') . '-db.sql' . ($gzip ? '.gz' : ''), $gzip ? 'application/gzip' : 'application/sql');
        $out = fopen('php://output', 'wb');
        try {
            backup_dump_db($out, $gzip);
        } catch (\Throwable $e) {
            // Headers are gone: the file simply ends early (and will not open), so log it.
            error_log('backup download failed: ' . $e->getMessage());
        }
        fclose($out);
    }

    public static function downloadUploads(array $p): void
    {
        csrf_check();
        require_permission('super_admin');
        audit('download', 'backup', null, 'uploads zip');
        backup_send_headers('gather-' . gmdate('Ymd-His') . '-uploads.zip', 'application/zip');
        $out = fopen('php://output', 'wb');
        try {
            backup_zip_uploads($out);
        } catch (\Throwable $e) {
            // Headers are gone: the file simply ends early (and will not open), so log it.
            error_log('backup download failed: ' . $e->getMessage());
        }
        fclose($out);
    }

    public static function settings(array $p): void
    {
        csrf_check();
        require_permission('super_admin');
        $time = input('time');
        $keep = input_int('keep', 0);
        $errors = [];
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            $errors['time'] = 'Use a time such as 03:00.';
        }
        if ($keep < 1 || $keep > 60) {
            $errors['keep'] = 'Keep between 1 and 60 backups.';
        }
        $values = ['enabled' => input('enabled') === '1', 'time' => $time, 'keep' => $keep, 'uploads' => input('uploads') === '1'];
        if ($errors) {
            remember_input($values, $errors);
            flash('Please check the backup schedule.', 'error');
            redirect('/admin/backups');
        }
        setting_set('backups', $values);
        audit('update', 'settings', null, 'backup schedule: ' . ($values['enabled'] ? 'daily at ' . $time . ', keep ' . $keep . ($values['uploads'] ? ', with uploads' : '') : 'off'));
        flash('Backup schedule saved.');
        redirect('/admin/backups');
    }

    public static function runNow(array $p): void
    {
        csrf_check();
        require_permission('super_admin');
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }
        try {
            $files = backup_run(input('uploads') === '1');
        } catch (\Throwable $e) {
            error_log('backup failed: ' . $e->getMessage());
            flash('The backup failed: ' . $e->getMessage(), 'error');
            redirect('/admin/backups');
        }
        audit('create', 'backup', null, implode(', ', $files));
        flash('Backup stored on the server: ' . implode(', ', $files) . '.');
        redirect('/admin/backups');
    }

    public static function file(array $p): void
    {
        require_permission('super_admin');
        $path = backup_path($p['name']);
        if ($path === null) {
            abort(404);
        }
        audit('download', 'backup', null, $p['name']);
        backup_send_headers($p['name'], str_ends_with($p['name'], '.zip') ? 'application/zip' : (str_ends_with($p['name'], '.gz') ? 'application/gzip' : 'application/sql'));
        header('Content-Length: ' . (string) filesize($path));
        $in = fopen($path, 'rb');
        $out = fopen('php://output', 'wb');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
    }

    public static function delete(array $p): void
    {
        csrf_check();
        require_permission('super_admin');
        $path = backup_path($p['name']);
        if ($path === null) {
            abort(404);
        }
        unlink($path);
        audit('delete', 'backup', null, $p['name']);
        flash('Deleted ' . $p['name'] . '.');
        redirect('/admin/backups');
    }
}
