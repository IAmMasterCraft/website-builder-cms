<?php
declare(strict_types=1);

namespace Site;

/**
 * POST /webhooks/email/{provider}: bounce and complaint notifications from
 * the sending provider (Addendum A5). Called by the provider's servers, not
 * by a browser, so there is no session and no CSRF token: this handler is
 * the documented CSRF exemption (see tests/smoke_admin.php). Every request
 * is authenticated by the provider's signature or a shared secret in
 * webhook_handle() before anything is recorded; unknown or unconfigured
 * providers answer 404.
 */
final class EmailWebhooks
{
    public static function receive(array $p): void
    {
        if (rate_limited('webhook-fail:' . client_ip(), 30, 600)) {
            self::out(429, 'Too many failed requests');
        }
        $body = (string) file_get_contents('php://input', false, null, 0, WEBHOOK_MAX_BODY + 1);
        $r = webhook_handle((string) $p['provider'], $body, $_SERVER);
        if ($r['status'] === 401 || $r['status'] === 403 || $r['status'] === 400) {
            rate_hit('webhook-fail:' . client_ip());
            error_log('Email webhook (' . $p['provider'] . ') refused: ' . $r['status']);
        }
        self::out($r['status'], $r['message']);
    }

    private static function out(int $status, string $message): never
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $message, "\n";
        exit;
    }
}
