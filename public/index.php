<?php
declare(strict_types=1);

// Built-in dev server: let it serve real files under public/ directly. Text
// assets are gzipped and versioned ones cached, like .htaccess does on Apache,
// so local Lighthouse runs measure what production sends.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $gzip = str_contains((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip');
    if ($file !== __DIR__ . '/' && is_file($file) && !str_ends_with($file, '.php')) {
        $types = ['css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml', 'txt' => 'text/plain; charset=utf-8'];
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $cache = isset($_GET['v']) || str_contains($file, '/assets/fonts/') || str_contains($file, '/uploads/');
        if (!$gzip || !isset($types[$ext]) || realpath($file) === false || !str_starts_with((string) realpath($file), __DIR__ . '/')) {
            if ($cache) {
                header('Cache-Control: public, max-age=31536000, immutable');
            }
            return false;
        }
        header('Content-Type: ' . $types[$ext]);
        header('Content-Encoding: gzip');
        header('Vary: Accept-Encoding');
        header('X-Content-Type-Options: nosniff');
        if ($cache) {
            header('Cache-Control: public, max-age=31536000, immutable');
        }
        echo gzencode((string) file_get_contents($file), 6);
        return true;
    }
    $devPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if ($gzip && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !preg_match('#^/(media|downloads|admin/backups|api/radio|theme-preview|uploads)/#', $devPath)) {
        ob_start('ob_gzhandler');
    }
}

require __DIR__ . '/../src/bootstrap.php';
require BASE . '/src/routes.php';

use Core\HttpException;
use Core\Redirect;

$path = request_path();
$method = request_method();

try {
    if (!app_installed()) {
        if (!str_starts_with($path, '/install')) {
            redirect('/install');
        }
        $match = install_routes()->match($method, $path);
    } else {
        if (str_starts_with($path, '/install')) {
            throw new HttpException(404);
        }
        if ($method === 'GET' && !str_starts_with($path, '/admin')) {
            publish_due_lazily();
            if (($analytics = analytics_config()) !== null) {
                header('Content-Security-Policy: ' . csp_header_value($analytics));
            }
        }
        // B2: the one central check while an admin views the site as a member
        // (expiry, revoked admin session, restricted actions).
        impersonation_guard($method, $path);
        $match = app_routes()->match($method, $path);
    }
    [$class, $action] = $match['handler'];
    $class::$action($match['params']);
} catch (Redirect $r) {
    header('Location: ' . $r->location, true, $r->status);
} catch (HttpException $e) {
    render_error($e->status, $e->getMessage(), $path);
} catch (Throwable $e) {
    error_log((string) $e);
    if (config('debug') === true) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo (string) $e;
    } else {
        render_error(500, 'Something went wrong on our side. Please try again in a moment.', $path);
    }
}

/** Designed error page; falls back to plain text if the page itself fails. */
function render_error(int $status, string $message, string $path): void
{
    try {
        if ($status === 405) {
            header('Allow: GET, POST');
        }
        $admin = str_starts_with($path, '/admin') && app_installed() && is_staff(current_user());
        render($admin ? 'admin/error' : 'site/error', ['status' => $status, 'message' => $message, 'title' => HttpException::reason($status)], $admin ? 'admin' : (app_installed() ? 'site' : 'bare'), $status);
    } catch (Throwable $e) {
        error_log('Error page failed: ' . $e->getMessage());
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo $status . ' ' . HttpException::reason($status);
    }
}
