<?php
declare(strict_types=1);

namespace Admin;

/** System health (§7 super admin, §12): read-only checks. */
final class Health
{
    public static function index(array $p): void
    {
        require_permission('super_admin');
        $groups = health_checks();
        header('Cache-Control: no-store');
        render('admin/health', ['title' => 'System health', 'groups' => $groups, 'summary' => health_summary($groups)], 'admin');
    }
}
