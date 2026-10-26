<?php
declare(strict_types=1);

namespace Install;

/**
 * First-run wizard: database → config.php → migrations → first super admin
 * → theme → optional demo content → lock. Only reachable while config is
 * missing or 'installed' is false; later steps only for the session that
 * wrote the config.
 */
final class Wizard
{
    public const STEPS = ['database' => 'Database', 'admin' => 'Administrator', 'theme' => 'Theme', 'content' => 'Content'];

    private static function page(string $step, string $template, array $data = [], int $status = 200): void
    {
        render('install/' . $template, $data + ['title' => 'Install Gather', 'step' => $step, 'errors' => [], 'old' => []], 'bare', $status);
    }

    /** Steps after the database need the config written by this session. */
    private static function guard(): void
    {
        if (!is_file(config_path())) {
            redirect('/install');
        }
        if (!install_session_ok()) {
            abort(403, 'This installation was started from another browser session.');
        }
    }

    public static function start(array $p): void
    {
        if (is_file(config_path()) && install_session_ok()) {
            redirect(q_val("SELECT COUNT(*) FROM users WHERE role = 'super_admin'") > 0 ? '/install/theme' : '/install/admin');
        }
        if (is_file(config_path())) {
            self::page('database', 'blocked', [], 403);
            return;
        }
        if (install_token_expected() === null) {
            self::page('database', 'token_needed', ['token_file' => BASE . INSTALL_TOKEN_FILE], 403);
            return;
        }
        self::page('database', 'database', [
            'old' => ['db_host' => '127.0.0.1', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'base_url' => install_detect_base_url()],
            'checks' => self::checks(),
        ]);
    }

    /** Server requirements shown on the first step. */
    public static function checks(): array
    {
        return [
            ['PHP 8.2 or newer', version_compare(PHP_VERSION, '8.2.0', '>=')],
            ['PDO MySQL extension', extension_loaded('pdo_mysql')],
            ['GD with WebP (image resizing)', function_exists('imagewebp')],
            ['Fileinfo (upload checks)', class_exists('finfo')],
            ['mbstring', extension_loaded('mbstring')],
            ['Config folder writable', is_writable(dirname(config_path()))],
            ['storage/ writable', is_writable(BASE . '/storage')],
            ['public/uploads writable', is_writable(BASE . '/public/uploads')],
        ];
    }

    public static function database(array $p): void
    {
        csrf_check();
        if (is_file(config_path())) {
            abort(403);
        }
        if (install_token_expected() === null) {
            self::page('database', 'token_needed', ['token_file' => BASE . INSTALL_TOKEN_FILE], 403);
            return;
        }
        $input = [];
        foreach (['db_host', 'db_port', 'db_name', 'db_user', 'db_pass', 'base_url'] as $k) {
            $input[$k] = is_string($_POST[$k] ?? null) ? ($k === 'db_pass' ? $_POST[$k] : trim($_POST[$k])) : '';
        }
        if (!install_token_ok(is_string($_POST['install_token'] ?? null) ? $_POST['install_token'] : '')) {
            error_log('Installer: wrong install token from ' . client_ip());
            unset($input['db_pass']);
            self::page('database', 'database', ['errors' => ['install_token' => 'This is not the install token set up on the server.'], 'old' => $input, 'checks' => self::checks()], 403);
            return;
        }
        [$db, $errors] = install_db_input($input);
        $baseUrl = install_base_url_input($input['base_url']);
        if ($baseUrl === null) {
            $errors['base_url'] = 'Enter the site address, such as https://www.example.org (no trailing slash).';
        }
        $pdo = null;
        if (!$errors) {
            try {
                $pdo = db_connect($db);
            } catch (\PDOException $e) {
                // The driver message can name hosts and users: it goes to the log, not the page.
                error_log('Installer: database connection failed: ' . $e->getMessage());
                $errors['form'] = 'Could not connect to the database. Check the host, port, database name, user and password, and that the database exists.';
            }
        }
        $unfinished = false;
        if (!$errors && $pdo !== null) {
            $state = install_db_state($pdo);
            if ($state === 'installed') {
                $errors['form'] = 'This database already contains a Gather installation. Use an empty database.';
            } elseif ($state === 'unfinished') {
                if (($_POST['reset_unfinished'] ?? '') === '1') {
                    install_reset_unfinished($pdo);
                    error_log('Installer: removed the tables of an unfinished installation, from ' . client_ip());
                } else {
                    $unfinished = true;
                    $errors['form'] = 'This database holds an unfinished Gather installation from an earlier attempt (no accounts were created). Tick "Remove the unfinished installation" below to clear its tables and start again.';
                }
            }
        }
        if ($errors) {
            unset($input['db_pass']);
            self::page('database', 'database', ['errors' => $errors, 'old' => $input, 'checks' => self::checks(), 'unfinished' => $unfinished], 422);
            return;
        }
        // Create the tables before writing config.php: if this fails, the
        // installer is still open and the next attempt can reset the database.
        db($pdo);
        try {
            migrate($pdo);
        } catch (\PDOException $e) {
            error_log('Installer: creating the tables failed: ' . $e->getMessage());
            unset($input['db_pass']);
            $code = (string) $e->getCode();
            self::page('database', 'database', ['errors' => ['form' => 'The database connected, but creating the tables failed' . ($code !== '' ? ' (SQLSTATE ' . $code . ')' : '') . '. The details are in the PHP error log. If you fix the cause, submit again and tick "Remove the unfinished installation".'], 'old' => $input, 'checks' => self::checks(), 'unfinished' => true], 500);
            return;
        }
        $config = install_new_config($db, (string) $baseUrl);
        try {
            install_write_config(config_path(), $config);
        } catch (\RuntimeException $e) {
            self::page('database', 'database', ['errors' => ['form' => $e->getMessage()], 'old' => $input, 'checks' => self::checks()], 500);
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['install_token'] = $config['install_token'];
        redirect('/install/admin');
    }

    public static function adminForm(array $p): void
    {
        self::guard();
        if ((int) q_val("SELECT COUNT(*) FROM users WHERE role = 'super_admin'") > 0) {
            redirect('/install/theme');
        }
        self::page('admin', 'admin', ['old' => ['site_name' => 'Gather', 'name' => '', 'email' => '']]);
    }

    public static function admin(array $p): void
    {
        csrf_check();
        self::guard();
        if ((int) q_val("SELECT COUNT(*) FROM users WHERE role = 'super_admin'") > 0) {
            redirect('/install/theme');
        }
        $old = ['site_name' => input('site_name'), 'name' => input('name'), 'email' => strtolower(input('email'))];
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $errors = [];
        if ($old['site_name'] === '' || mb_strlen($old['site_name']) > 120) {
            $errors['site_name'] = 'Enter your organisation’s name.';
        }
        if ($old['name'] === '' || mb_strlen($old['name']) > 120) {
            $errors['name'] = 'Enter your name.';
        }
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (($problem = password_problem($password)) !== null) {
            $errors['password'] = $problem;
        }
        if ($errors) {
            self::page('admin', 'admin', ['errors' => $errors, 'old' => $old], 422);
            return;
        }
        $id = create_user($old['email'], $password, 'super_admin', $old['name']);
        setting_set('site.name', $old['site_name']);
        $_SESSION['install_admin'] = $id;
        redirect('/install/theme');
    }

    public static function themeForm(array $p): void
    {
        self::guard();
        self::page('theme', 'theme', ['themes' => theme_list(), 'current' => active_theme()]);
    }

    public static function theme(array $p): void
    {
        csrf_check();
        self::guard();
        $theme = input('theme');
        if (!array_key_exists($theme, theme_list())) {
            self::page('theme', 'theme', ['themes' => theme_list(), 'current' => active_theme(), 'errors' => ['theme' => 'Choose a theme.']], 422);
            return;
        }
        setting_set('site.theme', $theme);
        redirect('/install/content');
    }

    public static function contentForm(array $p): void
    {
        self::guard();
        self::page('content', 'content');
    }

    public static function content(array $p): void
    {
        csrf_check();
        self::guard();
        $adminId = (int) ($_SESSION['install_admin'] ?? q_val("SELECT id FROM users WHERE role = 'super_admin' ORDER BY id LIMIT 1"));
        if ($adminId <= 0) {
            redirect('/install/admin');
        }
        if (input('demo') === '1' && (int) q_val('SELECT COUNT(*) FROM posts') === 0) {
            demo_seed($adminId);
        }
        install_lock();
        unset($_SESSION['install_token'], $_SESSION['install_admin']);
        login_user($adminId);
        flash('Gather is installed. Welcome to your admin area.');
        redirect('/admin');
    }
}
