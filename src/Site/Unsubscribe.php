<?php
declare(strict_types=1);

namespace Site;

/**
 * Unsubscribe link in every bulk email (§5). The signed token is the
 * credential: GET shows a confirm button (so link scanners do not
 * unsubscribe people), POST unsubscribes. POST also serves RFC 8058
 * one-click requests from mail clients, which carry no session or CSRF
 * token, so this handler checks the token signature instead of CSRF.
 */
final class Unsubscribe
{
    public static function show(array $p): void
    {
        $email = unsubscribe_verify($p['token']);
        header('Cache-Control: no-store');
        render('site/unsubscribe', ['title' => 'Unsubscribe', 'email' => $email, 'token' => $p['token'], 'done' => false, 'noindex' => true], 'site', $email === null ? 404 : 200);
    }

    public static function confirm(array $p): void
    {
        $email = unsubscribe_verify($p['token']);
        if ($email === null) {
            abort(404);
        }
        email_unsubscribe($email);
        error_log('Unsubscribed ' . hash('sha256', $email));
        if (($_POST['List-Unsubscribe'] ?? '') === 'One-Click') {
            http_response_code(200);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Unsubscribed\n";
            return;
        }
        render('site/unsubscribe', ['title' => 'Unsubscribed', 'email' => $email, 'token' => $p['token'], 'done' => true, 'noindex' => true]);
    }
}
