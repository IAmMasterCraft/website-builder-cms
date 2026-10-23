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
        self::page('database', 'database', [
            'old' => ['db_host' => '127.0.0.1', 'db_port' => '3306', 'db_name' => '', 'db_user' => ''],
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
        $input = [];
        foreach (['db_host', 'db_port', 'db_name', 'db_user', 'db_pass'] as $k) {
            $input[$k] = is_string($_POST[$k] ?? null) ? ($k === 'db_pass' ? $_POST[$k] : trim($_POST[$k])) : '';
        }
        [$db, $errors] = install_db_input($input);
        $pdo = null;
        if (!$errors) {
            try {
                $pdo = db_connect($db);
            } catch (\PDOException $e) {
                $errors['form'] = 'Could not connect to the database. Check the details and that the database exists. (' . preg_replace('/\s+/', ' ', mb_substr($e->getMessage(), 0, 160)) . ')';
            }
        }
        if (!$errors && $pdo !== null && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'")->fetchColumn() > 0) {
            $errors['form'] = 'This database already contains a Gather installation. Use an empty database.';
        }
        if ($errors) {
            unset($input['db_pass']);
            self::page('database', 'database', ['errors' => $errors, 'old' => $input, 'checks' => self::checks()], 422);
            return;
        }
        $config = install_new_config($db, install_detect_base_url());
        try {
            install_write_config(config_path(), $config);
        } catch (\RuntimeException $e) {
            self::page('database', 'database', ['errors' => ['form' => $e->getMessage()], 'old' => $input, 'checks' => self::checks()], 500);
            return;
        }
        db($pdo);
        migrate($pdo);
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
