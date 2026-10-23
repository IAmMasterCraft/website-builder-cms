<?php
declare(strict_types=1);

use Core\HttpException;
use Core\Router;

// ---- Router -----------------------------------------------------------------

test('router matches static and parameter routes', function (): void {
    $r = (new Router())->get('/', 'home')->get('/news/{slug:[a-z0-9-]+}', 'post')->post('/news', 'create');
    same('home', $r->match('GET', '/')['handler']);
    $m = $r->match('GET', '/news/hello-world');
    same('post', $m['handler']);
    same(['slug' => 'hello-world'], $m['params']);
    same('create', $r->match('POST', '/news')['handler']);
});

test('router normalises trailing and double slashes', function (): void {
    $r = (new Router())->get('/events/calendar', 'cal');
    same('cal', $r->match('GET', '/events/calendar/')['handler']);
    same('cal', $r->match('GET', '//events//calendar')['handler']);
});

test('router returns 404 for unknown paths and 405 for wrong method', function (): void {
    $r = (new Router())->get('/login', 'form')->post('/logout', 'out');
    same(404, throws(HttpException::class, fn () => $r->match('GET', '/nope'))->status);
    same(405, throws(HttpException::class, fn () => $r->match('GET', '/logout'))->status);
});

test('router treats HEAD as GET', function (): void {
    same('x', (new Router())->get('/a', 'x')->match('HEAD', '/a')['handler']);
});

test('router regex constraints reject bad parameters', function (): void {
    $r = (new Router())->get('/media/{id:\d+}', 'm');
    throws(HttpException::class, fn () => $r->match('GET', '/media/abc'));
    same(['id' => '42'], $r->match('GET', '/media/42')['params']);
});

test('app routes: specific routes win over the page catch-all', function (): void {
    require_once BASE . '/src/routes.php';
    $r = app_routes();
    same([Site\Events::class, 'calendar'], $r->match('GET', '/events/calendar')['handler']);
    same([Site\Events::class, 'ics'], $r->match('GET', '/events/summer-fair.ics')['handler']);
    same([Site\Events::class, 'show'], $r->match('GET', '/events/summer-fair')['handler']);
    same([Site\Feeds::class, 'sitemap'], $r->match('GET', '/sitemap.xml')['handler']);
    same([Site\Pages::class, 'show'], $r->match('GET', '/about-us')['handler']);
    same([Admin\Content::class, 'index'], $r->match('GET', '/admin/posts')['handler']);
    same([Admin\Media::class, 'index'], $r->match('GET', '/admin/media')['handler']);
    same(404, throws(HttpException::class, fn () => $r->match('GET', '/About Us'))->status);
});

// ---- Escaping, text, URLs ---------------------------------------------------

test('e() escapes HTML special characters', function (): void {
    same('&lt;b&gt; &quot;x&quot; &#039;y&#039; &amp;', e('<b> "x" \'y\' &'));
});

test('slugify makes URL-safe slugs', function (): void {
    same('harbour-festival-2026', slugify('  Harbour Festival — 2026! '));
    same('cafe-creme', slugify('Café Crème'));
    same('', slugify('!!!'));
});

test('excerpt strips tags and shortens at a word boundary', function (): void {
    same('Hello world', excerpt('<p>Hello <b>world</b></p>'));
    $long = excerpt(str_repeat('word ', 100), 30);
    ok(mb_strlen($long) <= 31 && str_ends_with($long, '…'), 'shortened with ellipsis');
});

test('safe_url allows http(s), mailto, tel and relative only', function (): void {
    same('https://example.org/a', safe_url('https://example.org/a'));
    same('/about', safe_url('/about'));
    same('mailto:a@b.c', safe_url('mailto:a@b.c'));
    same('', safe_url('javascript:alert(1)'));
    same('', safe_url('//evil.example'));
    same('', safe_url('data:text/html,hi'));
});

test('safe_redirect_target blocks open redirects', function (): void {
    same('/admin', safe_redirect_target('/admin'));
    same('/', safe_redirect_target('https://evil.example'));
    same('/', safe_redirect_target('//evil.example'));
    same('/', safe_redirect_target('/\\evil.example'));
    same('/x', safe_redirect_target('', '/x'));
});

test('url_with keeps filters and drops page 1', function (): void {
    same('/news?when=month&page=2', url_with('/news', ['when' => 'month'], ['page' => 2]));
    same('/news?when=month', url_with('/news', ['when' => 'month', 'page' => 3], ['page' => 1]));
    same('/news', url_with('/news', ['when' => 'month'], ['when' => null]));
});

test('local_to_utc and utc_to_local convert through the site timezone', function (): void {
    $london = new DateTimeZone('Europe/London');
    same('2026-07-01 09:00:00', local_to_utc('2026-07-01T10:00', $london), 'BST is UTC+1');
    same('2026-01-15 10:00:00', local_to_utc('2026-01-15T10:00', $london), 'GMT is UTC+0');
    same(null, local_to_utc('2026-02-30T10:00', $london), 'invalid date rejected');
    same('2026-07-01T10:00', utc_to_input('2026-07-01 09:00:00', $london));
});

// ---- CSRF -------------------------------------------------------------------

test('csrf_check rejects a missing or wrong token with 419', function (): void {
    $_SESSION = [];
    $token = csrf_token();
    $_POST = [];
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    same(419, throws(HttpException::class, 'csrf_check')->status);
    $_POST = ['_csrf' => 'wrong'];
    same(419, throws(HttpException::class, 'csrf_check')->status);
    $_POST = ['_csrf' => ['array']];
    same(419, throws(HttpException::class, 'csrf_check')->status);
    $_POST = ['_csrf' => $token];
    csrf_check();
    $_POST = [];
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
    csrf_check();
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    same(64, strlen($token));
    contains('name="_csrf"', csrf_field());
});

// ---- Sanitiser (XSS) --------------------------------------------------------

test('sanitiser removes scripts, event handlers and javascript: links', function (): void {
    $dirty = '<p onclick="x()">Hi <script>alert(1)</script><a href="javascript:alert(1)">a</a>'
        . '<img src="x" onerror="alert(1)"><iframe src="https://evil"></iframe><svg onload="alert(1)"><circle/></svg>'
        . '<a href="https://example.org" target="_blank" style="color:red">ok</a></p><style>body{}</style>';
    $clean = \Core\Sanitizer::clean($dirty);
    foreach (['<script', 'onclick', 'onerror', 'javascript:', '<iframe', '<svg', 'onload', '<style', 'style=', 'target='] as $bad) {
        not_contains($bad, $clean);
    }
    contains('<a href="https://example.org" rel="noopener noreferrer">ok</a>', $clean);
    contains('<p>Hi ', $clean);
});

test('sanitiser keeps allowed formatting and unwraps unknown tags', function (): void {
    $clean = \Core\Sanitizer::clean('<h1>Title</h1><div><span class="x">Text</span> <strong>bold</strong></div><ul><li>One</li></ul><img src="/uploads/a.webp" alt="A">');
    same('<h2>Title</h2>Text <strong>bold</strong><ul><li>One</li></ul><img src="/uploads/a.webp" alt="A">', $clean);
});

test('sanitiser handles UTF-8 and empty input', function (): void {
    same('<p>Ọmọ café 😀</p>', \Core\Sanitizer::clean('<p>Ọmọ café 😀</p>'));
    same('', \Core\Sanitizer::clean('   '));
    same('', \Core\Sanitizer::clean('<script>only()</script>'));
});

// ---- Mail -------------------------------------------------------------------

test('file mailer writes an .eml with text and HTML parts', function (): void {
    $dir = sys_get_temp_dir() . '/gather-mail-' . bin2hex(random_bytes(4));
    $mailer = new \Core\Mail\FileMailer($dir, 'no-reply@example.test', 'Gather');
    $mailer->send(new \Core\Mail\Message('ada@example.test', 'Ada', 'Hello é', "Plain body", '<p>HTML body</p>'));
    $files = glob($dir . '/*.eml');
    same(1, count($files));
    $eml = (string) file_get_contents($files[0]);
    contains('To: Ada <ada@example.test>', $eml);
    contains('multipart/alternative', $eml);
    contains('Plain body', $eml);
    contains('HTML body', $eml);
});

test('mail message rejects header injection and bad addresses', function (): void {
    throws(InvalidArgumentException::class, fn () => new \Core\Mail\Message('ada@example.test', 'Ada', "Hi\r\nBcc: x@evil", 'body'));
    throws(InvalidArgumentException::class, fn () => new \Core\Mail\Message('not-an-email', 'Ada', 'Hi', 'body'));
});

test('send_template_mail renders the password reset template', function (): void {
    $dir = sys_get_temp_dir() . '/gather-mail-' . bin2hex(random_bytes(4));
    mailer(new \Core\Mail\FileMailer($dir, 'no-reply@example.test', 'Gather'));
    ok(send_template_mail('ada@example.test', 'Ada <Admin>', 'password_reset', ['name' => 'Ada <Admin>', 'reset_url' => 'https://x.test/reset/abc']));
    $eml = quoted_printable_decode((string) file_get_contents(glob($dir . '/*.eml')[0]));
    contains('https://x.test/reset/abc', $eml);
    contains('Ada &lt;Admin&gt;', $eml, 'HTML part escapes names');
});

// ---- Migrations, installer helpers, cron -----------------------------------

test('migration splitter ignores comment lines with semicolons', function (): void {
    $stmts = migration_statements("-- a; b\nCREATE TABLE a (id INT);\n\n-- c;\nCREATE TABLE b (id INT);\n");
    same(['CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)'], $stmts);
});

test('migrate is idempotent and records every file', function (): void {
    same([], migrate(db()));
    same(count(migration_files()), (int) q_val('SELECT COUNT(*) FROM schema_migrations'));
});

test('installer validates database input', function (): void {
    [$db, $errors] = install_db_input(['db_host' => 'local host;', 'db_port' => '0', 'db_name' => 'x y', 'db_user' => '']);
    same(['db_host', 'db_port', 'db_name', 'db_user'], array_keys($errors));
    [$db, $errors] = install_db_input(['db_host' => '127.0.0.1', 'db_port' => '3306', 'db_name' => 'gather', 'db_user' => 'u', 'db_pass' => 'p']);
    same([], $errors);
    same(3306, $db['port']);
});

test('installer writes a loadable config file', function (): void {
    $saved = config_all();
    $path = sys_get_temp_dir() . '/gather-config-' . bin2hex(random_bytes(4)) . '.php';
    install_write_config($path, install_new_config(['host' => 'h', 'port' => 1, 'name' => 'n', 'user' => 'u', 'pass' => "p'q"], 'https://site.test'));
    $loaded = require $path;
    same(false, $loaded['installed']);
    same("p'q", $loaded['db']['pass']);
    same(64, strlen($loaded['app_key']));
    config_all($saved);
});

test('cron jobs are due by interval and publish scheduled items', function (): void {
    $job = ['every' => 60];
    ok(cron_is_due($job, null, 1000));
    ok(!cron_is_due($job, 1000, 1000 + 1800));
    ok(cron_is_due($job, 1000, 1000 + 3600));
    $cat = (string) q_val("SELECT id FROM categories WHERE slug = 'news'");
    $id = fixture_content('posts', ['title' => 'Cron post', 'category_id' => $cat, 'status' => 'scheduled', 'publish_at' => local_in('+1 day')]);
    q("UPDATE posts SET publish_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = ?", [$id]);
    $results = cron_run(null, true);
    ok(isset($results['publish_scheduled'], $results['cleanup']));
    same('published', q_val('SELECT status FROM posts WHERE id = ?', [$id]));
    ok(is_string(setting('cron.last_run')));
});

test('rich() sanitises stored HTML at output too', function (): void {
    same('<p>x</p>', rich('<p onclick="a()">x</p><script>b()</script>'));
});
