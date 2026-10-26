<?php
declare(strict_types=1);

namespace Site;

/** "Return to admin" from the impersonation banner (B2). */
final class Impersonation
{
    public static function stop(array $p): void
    {
        csrf_check();
        if (!is_impersonating()) {
            redirect('/');
        }
        $imp = impersonation();
        $to = impersonation_end('returned');
        flash('You are back in the admin area. Viewing as ' . ($imp['name'] ?? 'the member') . ' has ended.');
        redirect($to);
    }
}
