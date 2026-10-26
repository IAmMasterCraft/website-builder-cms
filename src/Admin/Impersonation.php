<?php
declare(strict_types=1);

namespace Admin;

/** "Log in as this member" (Addendum B2): confirmation screen and start. */
final class Impersonation
{
    public static function confirm(array $p): void
    {
        $admin = require_permission('impersonate');
        $target = impersonation_target((int) $p['id']);
        $refusal = impersonation_refusal($target);
        if ($target === null) {
            abort(404);
        }
        $old = take_old_input();
        render('admin/impersonate', [
            'title' => 'Log in as ' . $target['name'], 'target' => $target, 'refusal' => $refusal,
            'warnings' => $refusal === null ? impersonation_warnings($target) : [],
            'values' => $old['values'], 'errors' => $old['errors'], 'notify' => (bool) setting('impersonation.notify', true),
            'can_members' => user_can($admin, 'members'),
        ], 'admin', $refusal !== null ? 422 : 200);
    }

    public static function start(array $p): void
    {
        csrf_check();
        $admin = require_permission('impersonate');
        $id = (int) $p['id'];
        $reason = input('reason');
        try {
            if (mb_strlen($reason) > 200) {
                throw new \DomainException('Keep the reason under 200 characters.');
            }
            $target = impersonation_start($admin, $id, $reason, input('allow_changes') === '1', input('confirm') === '1');
        } catch (\DomainException $e) {
            // Nothing started, so nothing to log: impersonation_start() writes the audit entry when it starts.
            remember_input(['reason' => $reason, 'allow_changes' => input('allow_changes')], ['form' => $e->getMessage()]);
            flash($e->getMessage(), 'error');
            redirect('/admin/impersonate/' . $id);
        }
        flash('You are now viewing the site as ' . $target['name'] . '. Use “Return to admin” in the bar at the bottom when you are done.');
        redirect('/account');
    }
}
