<?php
declare(strict_types=1);

// End-to-end smoke test through PHP's built-in server.
//   php tests/smoke.php
// Empties the test database, installs the site through the /install wizard
// (with demo content), then checks public pages, admin pages, auth,
// permissions, CSRF, upload rejection, event registration and feeds.

if (PHP_SAPI !== 'cli') {
    exit(1);
}
define('BASE', dirname(__DIR__));
$testConfig = require __DIR__ . '/config.test.php';
$port = 8099;
$base = "http://127.0.0.1:$port";
$tmp = sys_get_temp_dir() . '/gather-smoke-' . bin2hex(random_bytes(4));
mkdir($tmp);
$configPath = $tmp . '/config.php';
$serverLog = $tmp . '/server.log';

// Empty the test database (the installer refuses a database that has tables).
putenv('GATHER_CONFIG=' . __DIR__ . '/config.test.php');
require BASE . '/src/load.php';
drop_all_tables(db());

// Start the server with its own (not yet existing) config file.
$installToken = bin2hex(random_bytes(16));
// GATHER_DNS=off: the server never makes real DNS (MX) lookups during the smoke test.
$env = array_merge(getenv(), ['GATHER_CONFIG' => $configPath, 'GATHER_INSTALL_TOKEN' => $installToken, 'GATHER_DNS' => 'off']);
$proc = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', BASE . '/public', BASE . '/public/index.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
    $pipes,
    BASE,
    $env
);
if (!is_resource($proc)) {
    fwrite(STDERR, "Could not start php -S\n");
    exit(2);
}
register_shutdown_function(static function () use ($proc): void {
    proc_terminate($proc);
});
for ($i = 0; $i < 50; $i++) {
    $ch = curl_init("http://127.0.0.1:$port/robots.txt");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1]);
    curl_exec($ch);
    if (curl_errno($ch) === 0) {
        break;
    }
    usleep(100000);
}

/** Minimal browser: cookie jar, no automatic redirects. */
final class Browser
{
    private string $jar;
    public array $last = ['status' => 0, 'body' => '', 'location' => '', 'type' => ''];

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'jar');
    }

    public function get(string $path, array $headers = []): array
    {
        return $this->request('GET', $path, null, $headers);
    }

    public function post(string $path, array|string|null $fields, array $headers = []): array
    {
        return $this->request('POST', $path, $fields, $headers);
    }

    /** POST a form, adding the CSRF token found on $formPage. */
    public function submit(string $formPage, string $action, array $fields): array
    {
        $fields['_csrf'] = $this->csrf($formPage);
        return $this->post($action, $fields);
    }

    public function csrf(string $page): string
    {
        $html = $this->get($page)['body'];
        return preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $html, $m) ? $m[1] : '';
    }

    /** POST and write the response body to $file (large downloads). Returns status and content type. */
    public function postToFile(string $path, array $fields, string $file): array
    {
        $fh = fopen($file, 'wb');
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_TIMEOUT => 300]);
        curl_exec($ch);
        fclose($fh);
        return ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'type' => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE)];
    }

    private function request(string $method, string $path, array|string|null $fields, array $headers): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
        ]);
        if ($fields !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        }
        $raw = (string) curl_exec($ch);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $head = substr($raw, 0, $headerSize);
        $this->last = [
            'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'body' => substr($raw, $headerSize),
            'location' => preg_match('/^Location:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : '',
            'type' => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
            'headers' => $head,
        ];
        return $this->last;
    }
}

$checks = 0;
$failures = [];
function check(string $label, bool $ok, string $detail = ''): void
{
    global $checks, $failures;
    $checks++;
    echo ($ok ? 'ok    ' : 'FAIL  ') . $label . ($ok || $detail === '' ? '' : "\n      $detail") . "\n";
    if (!$ok) {
        $failures[] = $label;
    }
}
/** Read a setting straight from the database (the server process changed it). */
function setting_fresh(string $key): mixed
{
    settings_all([]);
    return setting($key);
}
function status_is(Browser $b, string $path, int $want, string $contains = ''): void
{
    $r = $b->get($path);
    $ok = $r['status'] === $want && ($contains === '' || str_contains($r['body'], $contains));
    check(sprintf('GET %-48s %d%s', $path, $want, $contains !== '' ? " (has \"$contains\")" : ''), $ok, "got {$r['status']} " . mb_substr(strip_tags($r['body']), 0, 200));
}

$admin = new Browser($base);
$db = $testConfig['db'];

// ---- Installer --------------------------------------------------------------
$r = $admin->get('/');
check('fresh site redirects to /install', $r['status'] === 303 && $r['location'] === '/install');
status_is($admin, '/install', 200, 'Connect your database');
$dbFields = ['db_host' => $db['host'], 'db_port' => (string) $db['port'], 'db_name' => $db['name'], 'db_user' => $db['user'], 'db_pass' => $db['pass'], 'base_url' => $base, 'install_token' => $installToken];
$r = $admin->post('/install/database', $dbFields);
check('installer POST without CSRF token is rejected (419)', $r['status'] === 419);
check('installer asks for the install token and the site address', str_contains($admin->get('/install')['body'], 'name="install_token"') && str_contains($admin->last['body'], 'name="base_url"'));
$r = $admin->submit('/install', '/install/database', ['install_token' => 'wrong-token-wrong-token'] + $dbFields);
check('installer refuses a wrong install token (S9)', $r['status'] === 403 && !is_file($configPath));
$r = $admin->submit('/install', '/install/database', ['install_token' => ''] + $dbFields);
check('installer refuses a missing install token (S9)', $r['status'] === 403 && !is_file($configPath));
$r = $admin->submit('/install', '/install/database', ['base_url' => 'javascript:alert(1)'] + $dbFields);
check('installer validates the site address (S9)', $r['status'] === 422 && str_contains($r['body'], 'site address') && !is_file($configPath));
$r = $admin->submit('/install', '/install/database', ['db_pass' => 'wrong-password'] + $dbFields);
check('installer reports a bad database password without the driver message (S9)', $r['status'] === 422 && str_contains($r['body'], 'Could not connect') && !str_contains($r['body'], 'Access denied') && !str_contains($r['body'], 'SQLSTATE'));
// An earlier attempt that failed half-way (e.g. a SQL error on one host) leaves
// some tables and no accounts: the installer offers to clear them, nothing else.
$scratch = db_connect($db);
$scratch->exec('CREATE TABLE users (id INT PRIMARY KEY) ENGINE=InnoDB');
$scratch->exec('CREATE TABLE schema_migrations (version VARCHAR(190) PRIMARY KEY) ENGINE=InnoDB');
$scratch->exec('CREATE TABLE not_gather_keep_me (id INT PRIMARY KEY) ENGINE=InnoDB');
$r = $admin->submit('/install', '/install/database', $dbFields);
check('installer spots an unfinished installation and asks before clearing it', $r['status'] === 422 && str_contains($r['body'], 'unfinished Gather installation') && str_contains($r['body'], 'name="reset_unfinished"') && !is_file($configPath));
$scratch->exec('INSERT INTO users (id) VALUES (1)');
$r = $admin->submit('/install', '/install/database', ['reset_unfinished' => '1'] + $dbFields);
check('installer never clears a database that has accounts', $r['status'] === 422 && str_contains($r['body'], 'already contains a Gather installation') && !is_file($configPath));
$scratch->exec('DELETE FROM users');
$r = $admin->submit('/install', '/install/database', ['reset_unfinished' => '1'] + $dbFields);
check('installer clears the unfinished installation, writes config.php and runs migrations', $r['status'] === 303 && $r['location'] === '/install/admin' && is_file($configPath));
check('clearing an unfinished installation leaves other tables alone', (int) $scratch->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'not_gather_keep_me'")->fetchColumn() === 1);
$scratch->exec('DROP TABLE not_gather_keep_me');
$other = new Browser($base);
check('another session cannot continue the install', $other->get('/install/admin')['status'] === 403);
$r = $admin->submit('/install/admin', '/install/admin', ['site_name' => 'Smoke Test Society', 'name' => 'Sam Smoke', 'email' => 'sam@example.test', 'password' => 'short']);
check('installer validates the admin password', $r['status'] === 422 && str_contains($r['body'], 'at least 10'));
$r = $admin->submit('/install/admin', '/install/admin', ['site_name' => 'Smoke Test Society', 'name' => 'Sam Smoke', 'email' => 'sam@example.test', 'password' => 'smoke-test-password']);
check('installer creates the super admin', $r['status'] === 303 && $r['location'] === '/install/theme');
$r = $admin->submit('/install/theme', '/install/theme', ['theme' => 'civic']);
check('installer saves the theme', $r['status'] === 303 && $r['location'] === '/install/content');
$t = microtime(true);
$r = $admin->submit('/install/content', '/install/content', ['demo' => '1']);
check(sprintf('installer loads demo content and locks itself (%.1fs)', microtime(true) - $t), $r['status'] === 303 && $r['location'] === '/admin');
$cfg = require $configPath;
check('config is marked installed and the token removed', $cfg['installed'] === true && !isset($cfg['install_token']));
check('installer stores the typed site address, not the Host header', $cfg['base_url'] === $base);
status_is($admin, '/install', 404);
status_is($admin, '/install/admin', 404);

// Talk to the installed database directly for setup and assertions.
config_all($cfg);
db(db_connect($cfg['db']));
settings_all([]);

// ---- Public pages -----------------------------------------------------------
$visitor = new Browser($base);
$post = q_one("SELECT p.slug, c.base FROM posts p JOIN categories c ON c.id = p.category_id WHERE p.status = 'published' AND p.visibility = 'public' ORDER BY p.publish_at DESC LIMIT 1");
$event = q_one("SELECT slug FROM events WHERE registration_open = 1 AND guest_registration = 1 AND start_at > UTC_TIMESTAMP() ORDER BY start_at LIMIT 1");
$album = q_one('SELECT slug FROM albums LIMIT 1');
$group = q_one('SELECT g.slug, l.slug AS leader FROM leader_groups g JOIN leaders l ON l.group_id = g.id LIMIT 1');
$page = q_one("SELECT slug FROM pages WHERE visibility = 'public' AND status = 'published' LIMIT 1");
foreach ([
    ['/', 200, 'Harbourside'], ['/news', 200, 'News'], ['/news?when=month', 200, ''], ['/news?when=older', 200, ''], ['/blog', 200, ''], ['/projects', 200, ''],
    ['/' . $post['base'] . '/' . $post['slug'], 200, 'Share'], ['/events', 200, 'Events'], ['/events?when=past', 200, ''], ['/events?when=today', 200, ''],
    ['/events/calendar', 200, 'calendar'], ['/events/calendar?view=week', 200, 'Week of'], ['/events/calendar?view=list', 200, ''], ['/events/calendar?month=2026-02', 200, 'February 2026'],
    ['/events/' . $event['slug'], 200, 'Register'], ['/gallery', 200, 'Gallery'], ['/gallery/' . $album['slug'], 200, 'data-lightbox'],
    ['/downloads', 200, 'Downloads'], ['/leaders', 200, ''], ['/leaders/' . $group['slug'], 200, ''], ['/leaders/' . $group['slug'] . '?term=past', 200, ''],
    ['/leaders/' . $group['slug'] . '/' . $group['leader'], 200, ''], ['/awards', 200, 'Awards'], ['/faq', 200, '<details'], ['/branches', 200, 'Branches'],
    ['/groups', 200, 'Affiliated groups'], ['/' . $page['slug'], 200, ''], ['/search?q=festival', 200, 'result'], ['/login', 200, 'Log in'],
    ['/forgot-password', 200, ''], ['/join', 200, ''], ['/no-such-page', 404, 'search'], ['/news/no-such-post', 404, ''], ['/members-noticeboard', 403, 'Members'],
] as [$path, $want, $has]) {
    status_is($visitor, $path, $want, $has);
}
$home = $visitor->get('/');
check('home page has header menu, footer and theme CSS', str_contains($home['body'], 'id="site-nav"') && str_contains($home['body'], 'site-footer') && str_contains($home['body'], '/theme.css'));
check('security headers are sent', str_contains($home['headers'], 'Content-Security-Policy:') && str_contains($home['headers'], 'X-Content-Type-Options: nosniff'));
$fresh = (new Browser($base))->get('/login');
check('session cookie is HttpOnly and SameSite=Lax', (bool) preg_match('/Set-Cookie: gather_sid=[^\n]*HttpOnly[^\n]*SameSite=Lax/i', $fresh['headers']), $fresh['headers']);
$r = $visitor->get('/theme.css');
check('theme CSS served with tokens', str_contains($r['type'], 'text/css') && str_contains($r['body'], '--c-brand'));
$r = $visitor->get('/feed.xml');
check('RSS feed is valid XML', str_contains($r['type'], 'rss+xml') && simplexml_load_string($r['body']) !== false);
$r = $visitor->get('/sitemap.xml');
check('sitemap.xml is valid XML with absolute URLs', simplexml_load_string($r['body']) !== false && str_contains($r['body'], "<loc>$base/"));
$r = $visitor->get('/robots.txt');
check('robots.txt disallows admin and points to the sitemap', str_contains($r['body'], 'Disallow: /admin') && str_contains($r['body'], "Sitemap: $base/sitemap.xml"));
$r = $visitor->get('/events.ics');
check('events.ics feed is a calendar', str_contains($r['type'], 'text/calendar') && str_starts_with($r['body'], "BEGIN:VCALENDAR\r\n"));
$r = $visitor->get('/events/' . $event['slug'] . '.ics');
check('per-event .ics download', str_contains($r['type'], 'text/calendar') && str_contains($r['body'], 'BEGIN:VEVENT') && str_contains($r['headers'], 'attachment'));
$img = q_one("SELECT variants FROM media WHERE kind = 'image' AND visibility = 'public' LIMIT 1");
$variant = (string) array_values(json_decode((string) $img['variants'], true))[0];
$r = $visitor->get('/' . $variant);
check('public image variants are served as WebP', $r['status'] === 200 && str_contains($r['type'], 'image/webp'));
$membersDl = q_one("SELECT id FROM downloads WHERE visibility = 'members' LIMIT 1");
$r = $visitor->get('/downloads/' . $membersDl['id'] . '/file');
check('members-only download sends visitors to log in', $r['status'] === 303 && str_starts_with($r['location'], '/login'));
$publicDl = q_one("SELECT id FROM downloads WHERE visibility = 'public' LIMIT 1");
$r = $visitor->get('/downloads/' . $publicDl['id'] . '/file');
check('public download is served with nosniff and counted', $r['status'] === 200 && str_contains($r['type'], 'application/pdf') && str_contains($r['headers'], 'nosniff')
    && (int) q_val('SELECT download_count FROM downloads WHERE id = ?', [$publicDl['id']]) === 1);
$pdfMedia = q_one("SELECT id FROM media WHERE visibility = 'members' LIMIT 1");
check('members-only originals are hidden from visitors', $visitor->get('/media/' . $pdfMedia['id'] . '/file.pdf')['status'] === 404);
check('admin is not reachable by visitors (redirect to login)', $visitor->get('/admin')['status'] === 303);

// ---- Event registration (guest) ---------------------------------------------
$mailBefore = count(glob(BASE . '/storage/mail/*.eml') ?: []);
$eventRow = q_one('SELECT * FROM events WHERE slug = ?', [$event['slug']]);
$ticket = q_val('SELECT id FROM event_tickets WHERE event_id = ? ORDER BY sort LIMIT 1', [$eventRow['id']]);
$fields = ['name' => 'Gwen Guest', 'email' => 'gwen@example.test', 'phone' => '', 'quantity' => '2'];
if ($ticket !== null) {
    $fields['ticket_id'] = (string) $ticket;
}
$r = $visitor->submit('/events/' . $event['slug'], '/events/' . $event['slug'] . '/register', $fields);
check('guest registration redirects back (PRG)', $r['status'] === 303 && str_contains($r['location'], '/events/' . $event['slug']));
check('registration stored', (int) q_val("SELECT COUNT(*) FROM event_registrations WHERE email = 'gwen@example.test' AND quantity = 2") === 1);
$mails = glob(BASE . '/storage/mail/*.eml') ?: [];
check('confirmation email written to storage/mail', count($mails) === $mailBefore + 1);
check('confirmation email mentions the event', count($mails) > 0 && str_contains(quoted_printable_decode((string) file_get_contents(end($mails))), (string) $eventRow['title']));
$r = $visitor->get('/events/' . $event['slug']);
check('confirmation flash shown after redirect', str_contains($r['body'], 'You are registered'));
$r = $visitor->post('/events/' . $event['slug'] . '/register', $fields);
check('registration without CSRF token is rejected (419)', $r['status'] === 419);

// ---- Admin as super admin ---------------------------------------------------
$eventId = (int) $eventRow['id'];
$albumId = (int) q_val('SELECT id FROM albums LIMIT 1');
$menuId = (int) q_val("SELECT id FROM menus WHERE location = 'main'");
foreach (['/admin', '/admin/pages', '/admin/pages/new', '/admin/menus', "/admin/menus/$menuId", '/admin/media', '/admin/posts', '/admin/posts/new',
    '/admin/categories', '/admin/events', "/admin/events/$eventId/edit", "/admin/events/$eventId/registrations", '/admin/albums', "/admin/albums/$albumId/photos",
    '/admin/downloads', '/admin/leaders', '/admin/leader-groups', '/admin/awards', '/admin/faqs', '/admin/branches', '/admin/groups', '/admin/settings', '/admin/search?q=festival'] as $path) {
    status_is($admin, $path, 200);
}
$r = $admin->get("/admin/events/$eventId/registrations.csv");
check('registrations CSV export', str_contains($r['type'], 'text/csv') && str_contains($r['body'], 'Gwen Guest'));
$r = $admin->get('/admin/media/picker?kind=image', ['Accept: application/json']);
$json = json_decode($r['body'], true);
check('media picker returns JSON images', is_array($json) && count($json['items'] ?? []) > 0);

// Create, schedule and publish a post through the admin form.
$catId = (string) q_val("SELECT id FROM categories WHERE slug = 'news'");
$r = $admin->submit('/admin/posts/new', '/admin/posts', ['title' => 'Smoke test announcement', 'category_id' => $catId, 'body' => '<p>Hello <script>alert(1)</script></p>', 'status' => 'published', 'visibility' => 'public', 'tags' => 'smoke']);
check('admin creates a post', $r['status'] === 303 && str_contains($r['location'], '/admin/posts/'));
check('created post body is sanitised', !str_contains((string) q_val("SELECT body FROM posts WHERE title = 'Smoke test announcement'"), '<script'));
status_is($visitor, '/news/smoke-test-announcement', 200, 'Smoke test announcement');
$r = $admin->submit('/admin/posts/new', '/admin/posts', ['title' => '', 'category_id' => $catId, 'status' => 'draft']);
check('invalid post shows errors (422)', $r['status'] === 422 && str_contains($r['body'], 'Title is required'));

// Upload: a PHP file is rejected, a real image accepted.
$mediaBefore = (int) q_val('SELECT COUNT(*) FROM media');
$phpFile = $tmp . '/shell.jpg';
file_put_contents($phpFile, "<?php echo 'pwned'; ?>");
$r = $admin->post('/admin/media/upload', ['_csrf' => $admin->csrf('/admin/media'), 'folder' => '', 'visibility' => 'public', 'files[0]' => new CURLFile($phpFile, 'image/jpeg', 'shell.jpg')]);
check('upload of a PHP file disguised as .jpg is rejected', $r['status'] === 303 && (int) q_val('SELECT COUNT(*) FROM media') === $mediaBefore);
check('rejection message shown', str_contains($admin->get('/admin/media')['body'], 'not allowed'));
$jpg = $tmp . '/real.jpg';
$im = imagecreatetruecolor(900, 600);
imagejpeg($im, $jpg);
$r = $admin->post('/admin/media/upload', ['_csrf' => $admin->csrf('/admin/media'), 'folder' => 'smoke', 'visibility' => 'public', 'files[0]' => new CURLFile($jpg, 'image/jpeg', 'real.jpg')]);
check('a real 900px image uploads and gets WebP variants 400/800/900', (int) q_val('SELECT COUNT(*) FROM media') === $mediaBefore + 1
    && array_keys(json_decode((string) q_val('SELECT variants FROM media ORDER BY id DESC LIMIT 1'), true)) === [400, 800, 900]);

// Menu: deleting a linked page flags the item.
$aboutId = (int) q_val("SELECT id FROM pages WHERE slug = 'about-us'");
$r = $admin->submit("/admin/pages/$aboutId/edit", "/admin/pages/$aboutId/delete", []);
check('admin deletes a page', $r['status'] === 303);
check('menu items pointing to the deleted page are flagged', (int) q_val("SELECT COUNT(*) FROM menu_items WHERE target_type = 'page' AND target_id = ? AND is_broken = 1", [$aboutId]) >= 1);
check('menu editor shows the broken flag', str_contains($admin->get("/admin/menus/$menuId")['body'], 'Target deleted'));

// Check-in toggle.
$regId = (int) q_val("SELECT id FROM event_registrations WHERE email = 'gwen@example.test'");
$r = $admin->submit("/admin/events/$eventId/registrations", "/admin/events/$eventId/registrations/$regId", ['status' => 'attended']);
check('check-in toggles a registration to attended', $r['status'] === 303 && q_val('SELECT status FROM event_registrations WHERE id = ?', [$regId]) === 'attended');

// ---- Page builder, design, header/footer (phase 3) ---------------------------
$homeId = (int) setting('home.page_id');
check('demo home page is a builder page', $homeId > 0 && q_val('SELECT sections FROM pages WHERE id = ?', [$homeId]) !== null);
$r = $visitor->get('/');
check('home renders builder sections (hero h1, stats, events)', str_contains($r['body'], 'class="hero-title"') && str_contains($r['body'], 'blk-stats') && str_contains($r['body'], 'blk-upcoming-events'));
$r = $admin->get("/admin/pages/$homeId/builder");
check('builder editor loads with its config and picker', $r['status'] === 200 && str_contains($r['body'], 'id="builder-config"') && substr_count($r['body'], 'data-add-type=') === 29);
$token = preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $r['body'], $m) ? $m[1] : '';
$cfg = preg_match('#<script type="application/json" id="builder-config">(.*?)</script>#s', $r['body'], $m) ? json_decode($m[1], true) : null;
check('builder config lists 29 section types with schemas', is_array($cfg) && count($cfg['types']) === 29 && isset($cfg['types']['hero']['schema']));
$sections = $cfg['sections'];
$sections[0]['settings']['heading'] = 'Smoke draft heading';
$json = ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $token];
$r = $admin->post("/admin/pages/$homeId/builder", json_encode(['revision' => $cfg['page']['revision'], 'sections' => $sections]), $json);
$saved = json_decode($r['body'], true);
check('autosave stores the draft (JSON 200 with new revision)', $r['status'] === 200 && ($saved['revision'] ?? 0) === $cfg['page']['revision'] + 1, $r['body']);
$r = $admin->post("/admin/pages/$homeId/builder", json_encode(['revision' => $cfg['page']['revision'], 'sections' => $sections]), $json);
check('stale revision gets 409 "edited by … at hh:mm"', $r['status'] === 409 && (bool) preg_match('/edited by Sam Smoke at \d\d:\d\d/', $r['body']), $r['body']);
$r = $admin->post("/admin/pages/$homeId/builder", json_encode(['revision' => $saved['revision'], 'sections' => $sections]), ['Content-Type: application/json']);
check('autosave without CSRF token is rejected (419)', $r['status'] === 419);
check('visitors still see the published version', !str_contains($visitor->get('/')['body'], 'Smoke draft heading'));
check('preview shows the draft with section markers', str_contains(($p = $admin->get("/admin/pages/$homeId/preview?frame=1"))['body'], 'Smoke draft heading') && str_contains($p['body'], 'data-section-id='));
status_is($visitor, "/admin/pages/$homeId/preview", 303);
$r = $admin->post("/admin/pages/$homeId/publish", ['_csrf' => $token, 'revision' => (string) $saved['revision']]);
check('publish redirects back to the builder', $r['status'] === 303 && str_contains($r['location'], '/builder'));
check('after publishing, visitors see the new heading', str_contains($visitor->get('/')['body'], 'Smoke draft heading'));
$oldRev = (int) q_val('SELECT id FROM page_revisions WHERE page_id = ? ORDER BY id ASC LIMIT 1', [$homeId]);
$curRev = (int) q_val('SELECT revision FROM pages WHERE id = ?', [$homeId]);
$r = $admin->submit("/admin/pages/$homeId/builder", "/admin/pages/$homeId/revisions/$oldRev/restore", ['revision' => (string) $curRev]);
check('restore makes the old version the draft', $r['status'] === 303 && !str_contains($admin->get("/admin/pages/$homeId/preview")['body'], 'Smoke draft heading'));
check('history tab lists revisions with restore buttons', str_contains($admin->get("/admin/pages/$homeId/builder")['body'], 'Restored from'));
$r = $admin->post("/admin/pages/$homeId/templates", json_encode(['name' => 'Smoke band', 'kind' => 'section', 'sections' => [$sections[count($sections) - 1]]]), $json);
check('a section can be saved as a template', $r['status'] === 200 && (json_decode($r['body'], true)['name'] ?? '') === 'Smoke band');
status_is($admin, '/admin/templates', 200, 'Smoke band');
$r = $admin->submit('/admin/pages/new', '/admin/pages', ['title' => 'Smoke events page', 'status' => 'published', 'visibility' => 'public', 'start_template' => 'starter:events']);
check('a new page from a starter template opens in the builder', $r['status'] === 303 && str_ends_with($r['location'], '/builder'));
status_is($visitor, '/smoke-events-page', 200, 'blk-event-calendar');
status_is($admin, '/admin/design', 200, 'Contrast check');
$r = $admin->submit('/admin/design', '/admin/design', ['theme' => 'harvest', 'brand' => '#1f5132', 'accent' => '#ffd84d', 'heading_font' => 'nunito', 'body_font' => 'nunito', 'radius' => 'round', 'button' => 'pill']);
check('design saves with a contrast warning for a light accent', $r['status'] === 303 && str_contains($admin->get('/admin/design')['body'], 'fall below the 4.5:1'));
$home = $visitor->get('/');
check('the site uses the chosen theme and custom accent', (bool) preg_match('#/theme\.css\?t=harvest&amp;v=#', $home['body']));
preg_match('#href="(/theme\.css\?[^"]+)"#', $home['body'], $m);
check('theme.css carries the override tokens', str_contains($visitor->get(html_entity_decode($m[1] ?? ''))['body'], '--c-accent: #ffd84d;'));
$r = $admin->submit('/admin/design', '/admin/design', ['theme' => 'civic', 'reset' => '1']);
check('design reset returns to theme defaults', $r['status'] === 303 && setting_fresh('design') === []);
status_is($admin, '/admin/layout', 200, 'Footer columns');
$r = $admin->submit('/admin/layout', '/admin/layout', ['topbar' => '1', 'show_auth' => '1', 'sticky' => '1', 'col_widget[0]' => 'about', 'col_widget[1]' => 'join', 'col_widget[2]' => 'menu', 'col_widget[3]' => '', 'col_title[0]' => '', 'col_title[1]' => 'Join the association', 'col_title[2]' => '', 'col_title[3]' => '', 'col_menu[0]' => 'footer_1', 'col_menu[1]' => 'footer_1', 'col_menu[2]' => 'footer_2', 'col_menu[3]' => 'footer_1', 'col_text[0]' => '', 'col_text[1]' => '', 'col_text[2]' => '', 'col_text[3]' => '']);
check('footer columns save and render', $r['status'] === 303 && str_contains($visitor->get('/news')['body'], 'Join the association'));
$contactPage = $visitor->get('/contact');
check('contact page has the guarded contact form', str_contains($contactPage['body'], 'name="form_ts"') && str_contains($contactPage['body'], 'name="website_url"'));
$fields = ['name' => 'Cora Contact', 'email' => 'cora@example.test', 'message' => 'Hello from the smoke test', 'return' => '/contact'];
$r = $visitor->submit('/contact', '/contact', $fields);
check('a too-fast contact submission is refused', $r['status'] === 303 && (int) q_val("SELECT COUNT(*) FROM contact_messages WHERE email = 'cora@example.test'") === 0);
$token = $visitor->csrf('/contact');
$ts = preg_match('/name="form_ts" value="([^"]+)"/', $visitor->last['body'], $m) ? html_entity_decode($m[1]) : '';
sleep(3);
$mailBefore = count(glob(BASE . '/storage/mail/*.eml') ?: []);
$r = $visitor->post('/contact', $fields + ['_csrf' => $token, 'form_ts' => $ts, 'website_url' => '']);
check('contact message stored and emailed to the organisation only (no copy unless asked)', $r['status'] === 303 && (int) q_val("SELECT COUNT(*) FROM contact_messages WHERE email = 'cora@example.test'") === 1
    && count(glob(BASE . '/storage/mail/*.eml') ?: []) === $mailBefore + 1);
check('thank-you message shown after the redirect', str_contains($visitor->get('/contact')['body'], 'your message has been sent'));
check('contact form offers an opt-in confirmation', str_contains($visitor->last['body'], 'name="send_copy"'));
$copies = [];
foreach ([1, 2, 3] as $i) {
    $before = glob(BASE . '/storage/mail/*.eml') ?: [];
    $visitor->post('/contact', $fields + ['_csrf' => $token, 'form_ts' => $ts, 'website_url' => '', 'send_copy' => '1']);
    $copies[] = array_values(array_diff(glob(BASE . '/storage/mail/*.eml') ?: [], $before));
}
$copyMail = array_values(array_filter(array_map(static fn (string $f): string => quoted_printable_decode((string) file_get_contents($f)), $copies[0]), static fn (string $t): bool => str_contains($t, 'We received your message')));
check('opt-in confirmation is sent without repeating the message', count($copies[0]) === 2 && count($copyMail) === 1 && !str_contains($copyMail[0], 'Hello from the smoke test'));
check('confirmations to one address stop after 2 a day (S5)', count($copies[1]) === 2 && count($copies[2]) === 1, json_encode(array_map('count', $copies)));
$r = $visitor->post('/contact', $fields + ['_csrf' => $token, 'form_ts' => $ts, 'website_url' => 'http://spam.example']);
check('honeypot submission is refused', (int) q_val("SELECT COUNT(*) FROM contact_messages WHERE email = 'cora@example.test'") === 4);
foreach (['civic', 'bold', 'heritage', 'harvest', 'monochrome'] as $t) {
    $css = $visitor->get('/theme.css?t=' . $t . '&plain=1')['body'];
    check("theme $t is served with its tokens and font faces", str_contains($css, "/* theme: $t */") && str_contains($css, '@font-face'));
}

// ---- Membership (phase 4) -----------------------------------------------------
/** Hidden guard fields (csrf + signed time) from a form page. */
function guard_tokens(Browser $b, string $page): array
{
    $html = $b->get($page)['body'];
    return [
        '_csrf' => preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $html, $m) ? $m[1] : '',
        'form_ts' => preg_match('/name="form_ts" value="([^"]+)"/', $html, $m) ? html_entity_decode($m[1]) : '',
        'website_url' => '',
    ];
}
function last_mail_link(string $pattern): string
{
    $files = glob(BASE . '/storage/mail/*.eml') ?: [];
    rsort($files);
    foreach (array_slice($files, 0, 5) as $file) {
        $body = quoted_printable_decode((string) file_get_contents($file));
        if (preg_match($pattern, $body, $m)) {
            return $m[0];
        }
    }
    return '';
}
$joiner = new Browser($base);
status_is($joiner, '/join', 200, 'name="form_ts"');
check('join form has every field from §4.1', (bool) preg_match_all('/name="(first_name|last_name|birth_day|birth_month|birth_year|gender|address|nationality|country|region|city|photo|password|consent|newsletters|cf_home_district)"/', $joiner->last['body'], $mm) && count(array_unique($mm[1])) === 16, implode(',', array_unique($mm[1] ?? [])));
$photoFile = $tmp . '/me.jpg';
$im = imagecreatetruecolor(800, 600);
imagefilledrectangle($im, 0, 0, 800, 600, imagecolorallocate($im, 40, 120, 160));
imagejpeg($im, $photoFile);
$joinEmail = 'joiner@example.test';
$joinFields = ['first_name' => 'Jo', 'last_name' => 'Joiner', 'birth_day' => '24', 'birth_month' => '10', 'birth_year' => '1990', 'country' => 'United Kingdom', 'region' => 'England',
    'email' => $joinEmail, 'password' => 'joiner-password-1', 'password_confirm' => 'joiner-password-1', 'consent' => '1', 'newsletters' => '1', 'cf_home_district' => 'Quayside'];
$r = $joiner->post('/join', $joinFields + guard_tokens($joiner, '/join') + ['photo' => new CURLFile($photoFile, 'image/jpeg', 'me.jpg')]);
check('a too-fast sign-up is refused (time check)', $r['status'] === 422 && str_contains($r['body'], 'very quick'));
$tokens = guard_tokens($joiner, '/join');
$groupBrowser = new Browser($base);
$groupTokens = guard_tokens($groupBrowser, '/join/group');
sleep(3);
$r = $joiner->post('/join', $joinFields + array_merge($tokens, ['website_url' => 'http://spam.example']) + ['photo' => new CURLFile($photoFile, 'image/jpeg', 'me.jpg')]);
check('honeypot sign-up is refused', $r['status'] === 422 && q_val('SELECT id FROM users WHERE email = ?', [$joinEmail]) === null);
$r = $joiner->post('/join', array_merge($joinFields, ['password_confirm' => 'nope', 'consent' => '']) + $tokens + ['photo' => new CURLFile($photoFile, 'image/jpeg', 'me.jpg')]);
check('join validation errors are shown (422)', $r['status'] === 422 && str_contains($r['body'], 'do not match') && str_contains($r['body'], 'agree to the terms'));
$mailBefore = count(glob(BASE . '/storage/mail/*.eml') ?: []);
$r = $joiner->post('/join', $joinFields + $tokens + ['photo' => new CURLFile($photoFile, 'image/jpeg', 'me.jpg')]);
check('sign-up creates an unverified account without logging in (pre-hijack)', $r['status'] === 303 && $r['location'] === '/login' && q_val('SELECT status FROM users WHERE email = ?', [$joinEmail]) === 'unverified');
$signupAnswer = $joiner->get('/login')['body'];
check('verification email sent', count(glob(BASE . '/storage/mail/*.eml') ?: []) === $mailBefore + 1);
$verifyLink = last_mail_link('#' . preg_quote($base, '#') . '/join/verify/[a-f0-9]{64}#');
check('verification email contains the link', $verifyLink !== '');
check('the new account is not logged in after sign-up', $joiner->get('/account')['status'] === 303);
// S7: signing up again with the same address gives the same answer and emails the owner instead.
$again = new Browser($base);
$againTokens = guard_tokens($again, '/join');
sleep(3);
$mailBefore = count(glob(BASE . '/storage/mail/*.eml') ?: []);
$r = $again->post('/join', array_merge($joinFields, ['first_name' => 'Imposter']) + $againTokens + ['photo' => new CURLFile($photoFile, 'image/jpeg', 'me.jpg')]);
$againAnswer = $again->get('/login')['body'];
$strip = static fn (string $html): string => preg_match('#<div class="flash[^"]*"[^>]*>(.*?)</div>#s', $html, $m) ? trim(strip_tags($m[1])) : '';
check('sign-up with an existing address answers exactly like a new sign-up (S7)', $r['status'] === 303 && $r['location'] === '/login' && $strip($againAnswer) !== '' && $strip($againAnswer) === $strip($signupAnswer), $strip($againAnswer) . ' | ' . $strip($signupAnswer));
check('the owner of the address gets an "already have an account" email, no second account', count(glob(BASE . '/storage/mail/*.eml') ?: []) === $mailBefore + 1
    && last_mail_link('#already has an account#') !== '' && (int) q_val('SELECT COUNT(*) FROM users WHERE email = ?', [$joinEmail]) === 1);
$r = $joiner->submit('/login', '/login', ['email' => $joinEmail, 'password' => 'joiner-password-1']);
status_is($joiner, '/account', 200, 'Check your email');
$r = $joiner->get('/account/card');
check('unverified members cannot open the card', $r['status'] === 303 && $r['location'] === '/account');
check('no member number before verification', q_val('SELECT m.member_number FROM members m JOIN users u ON u.id = m.user_id WHERE u.email = ?', [$joinEmail]) === null);
$verifyPath = (string) parse_url($verifyLink, PHP_URL_PATH);
status_is(new Browser($base), $verifyPath, 200, 'Verify my email');
$r = $joiner->submit($verifyPath, $verifyPath, []);
$member = q_one('SELECT m.*, u.status FROM members m JOIN users u ON u.id = m.user_id WHERE u.email = ?', [$joinEmail]);
check('verifying the email activates the account and assigns the number', $r['status'] === 303 && $member['status'] === 'active' && preg_match('/^HCA-\d\d-\d{4}$/', (string) $member['member_number']) === 1, json_encode([$r['status'], $member['status'] ?? null, $member['member_number'] ?? null]));
check('welcome email carries the member number', str_contains(quoted_printable_decode((string) file_get_contents((string) (static function () { $f = glob(BASE . '/storage/mail/*.eml') ?: []; sort($f); return end($f); })())), (string) $member['member_number']));
status_is($joiner, $verifyPath, 404, 'expired');
$r = $joiner->get('/account');
check('dashboard shows the card preview and news', $r['status'] === 200 && str_contains($r['body'], 'id-card') && str_contains($r['body'], 'Latest news'));
$r = $joiner->get('/account/card');
check('ID card page: front, back, QR target and PNG button', $r['status'] === 200 && str_contains($r['body'], 'id-back') && str_contains($r['body'], 'data-qr="' . $base . '/verify/' . $member['member_number'] . '/' . $member['card_code'] . '"') && str_contains($r['body'], 'data-card-png'));
check('card shows the custom field marked "show on card"', str_contains($r['body'], 'Home district') && str_contains($r['body'], 'Quayside'));
status_is($joiner, '/account/card/print', 200, 'cr80-page');
$verifyUrl = '/verify/' . $member['member_number'] . '/' . $member['card_code'];
$checker = new Browser($base);
status_is($checker, $verifyUrl, 200, 'Valid member card');
$r = $checker->get($verifyUrl . '/photo');
check('verify page photo is served for a matching card', $r['status'] === 200 && str_contains($r['type'], 'image/webp'));
$photoId = (int) $member['photo_media_id'];
$photoVariant = (int) array_key_first(json_decode((string) q_val('SELECT variants FROM media WHERE id = ?', [$photoId]), true));
check('member photo is hidden from visitors', $checker->get("/media/$photoId/w/$photoVariant")['status'] === 404);
check('member photo is visible to its owner', $joiner->get("/media/$photoId/w/$photoVariant")['status'] === 200);
check('member photo is visible to staff', $admin->get("/media/$photoId/w/$photoVariant")['status'] === 200);
$memberId = (int) $member['id'];
status_is($admin, '/admin/members', 200, (string) $member['member_number']);
status_is($admin, '/admin/members?status=active&region=England&field=' . (int) q_val("SELECT id FROM custom_fields WHERE field_key = 'home_district'") . '&value=Quayside', 200, 'Joiner');
status_is($admin, "/admin/members/$memberId", 200, 'Regenerate card code');
$r = $admin->submit("/admin/members/$memberId", "/admin/members/$memberId/card-code", []);
check('admin regenerates the card code', $r['status'] === 303);
status_is($checker, $verifyUrl, 404, 'Not a valid card');
$member = q_one('SELECT * FROM members WHERE id = ?', [$memberId]);
$verifyUrl = '/verify/' . $member['member_number'] . '/' . $member['card_code'];
status_is($checker, $verifyUrl, 200, 'Valid member card');
$edit = ['email' => $joinEmail, 'first_name' => 'Jo', 'last_name' => 'Joiner', 'birth_day' => '24', 'birth_month' => '10', 'birth_year' => '1990', 'country' => 'United Kingdom', 'region' => 'England',
    'membership_type' => 'Standard', 'membership_fee_status' => 'paid', 'card_valid_until' => '2020-01-31', 'cf_home_district' => 'Quayside'];
$r = $admin->submit("/admin/members/$memberId", "/admin/members/$memberId", $edit);
check('admin edits the member and sets an expiry date', $r['status'] === 303 && q_val('SELECT card_valid_until FROM members WHERE id = ?', [$memberId]) === '2020-01-31');
status_is($checker, $verifyUrl, 200, 'Card expired');
$admin->submit("/admin/members/$memberId", "/admin/members/$memberId", array_merge($edit, ['card_valid_until' => '']));
$r = $admin->submit("/admin/members/$memberId", "/admin/members/$memberId/reset", []);
$resetPath = (string) parse_url(last_mail_link('#' . preg_quote($base, '#') . '/reset/[a-f0-9]{64}#'), PHP_URL_PATH);
check('admin emails a password link that opens the reset form', $r['status'] === 303 && $resetPath !== '' && (new Browser($base))->get($resetPath)['status'] === 200);
$r = $admin->submit("/admin/members/$memberId", "/admin/members/$memberId/notes", ['body' => 'Brought cakes to the AGM.']);
check('admin adds a note', $r['status'] === 303 && str_contains($admin->get("/admin/members/$memberId")['body'], 'Brought cakes'));
$r = $admin->get("/admin/members/cards?ids[]=$memberId");
check('bulk A4 card sheet (8 per page) renders', $r['status'] === 200 && str_contains($r['body'], 'a4-sheet') && substr_count($r['body'], 'slot-empty') === 14);
$r = $admin->get('/admin/members.csv?q=Joiner');
check('members CSV export', str_contains($r['type'], 'text/csv') && str_contains($r['body'], $joinEmail));
$csvFile = $tmp . '/import.csv';
file_put_contents($csvFile, "email,first_name,last_name,birth_day,birth_month,region\ncsv.person@example.test,Cee,Esvee,2,3,Wales\nbroken,No,Email,1,1,\n");
$r = $admin->post('/admin/members/import', ['_csrf' => $admin->csrf('/admin/members/import'), 'csv' => new CURLFile($csvFile, 'text/csv', 'import.csv')]);
$page = $admin->get('/admin/members/import')['body'];
check('CSV import creates members and reports bad rows', str_contains($page, 'Created 1') && str_contains($page, 'invalid email') && q_val('SELECT status FROM users WHERE email = ?', ['csv.person@example.test']) === 'active');
$r = $admin->submit('/admin/custom-fields/new', '/admin/custom-fields', ['label' => 'Year of arrival', 'type' => 'text', 'visible_to_members' => '1', 'applies_to' => 'member', 'sort' => '2']);
check('super admin adds a custom field', $r['status'] === 303 && q_val("SELECT field_key FROM custom_fields WHERE label = 'Year of arrival'") === 'year_of_arrival');
status_is($joiner, '/join', 303);
status_is($admin, '/admin/settings/membership', 200, 'Number format');
$r = $admin->submit('/admin/settings/membership', '/admin/settings/membership', ['prefix' => 'HCA', 'group_prefix' => 'HCA', 'number_format' => '{0000}', 'card_months' => '12', 'free_first_n' => '0', 'terms_url' => '/privacy-policy', 'signatory' => 'Secretary', 'regions' => 'England', 'genders' => 'Female', 'types' => 'Standard']);
check('membership settings validate the format and prefixes', $r['status'] === 422 && str_contains($r['body'], 'different prefix') && str_contains($r['body'], 'Include {PREFIX}'));
// Dashboard pages
foreach (['/account/profile', '/account/password', '/account/events', '/account/downloads', '/account/notices', '/account/preferences', '/account/privacy'] as $pth) {
    status_is($joiner, $pth, 200);
}
$r = $joiner->submit('/account/preferences', '/account/preferences', ['newsletters' => '1', 'celebrants' => '1']);
check('email preferences save', $r['status'] === 303 && str_contains((string) q_val('SELECT prefs FROM members WHERE id = ?', [$memberId]), '"celebrants":true'));
$r = $joiner->submit('/account/profile', '/account/profile', ['first_name' => 'Joanna', 'last_name' => 'Joiner', 'birth_day' => '24', 'birth_month' => '10', 'country' => 'United Kingdom', 'cf_home_district' => 'Marina', 'cf_year_of_arrival' => '2001']);
check('member edits their profile', $r['status'] === 303 && q_val('SELECT first_name FROM members WHERE id = ?', [$memberId]) === 'Joanna');
check('profile form shows the saved birthday', (bool) preg_match('/<option value="24" selected>24<\/option>/', $joiner->get('/account/profile')['body']));
$evRow = q_one("SELECT * FROM events WHERE registration_open = 1 AND start_at > UTC_TIMESTAMP() ORDER BY start_at LIMIT 1");
$r = $joiner->submit('/events/' . $evRow['slug'], '/events/' . $evRow['slug'] . '/register', ['name' => 'Joanna Joiner', 'email' => $joinEmail, 'phone' => '', 'quantity' => '1'] + (($t = q_val('SELECT id FROM event_tickets WHERE event_id = ? LIMIT 1', [$evRow['id']])) !== null ? ['ticket_id' => (string) $t] : []));
$regId = (int) q_val('SELECT id FROM event_registrations WHERE email = ? AND event_id = ?', [$joinEmail, $evRow['id']]);
check('member registers for an event', $regId > 0 && str_contains($joiner->get('/account/events')['body'], (string) $evRow['title']));
$r = $joiner->submit('/account/events', "/account/events/$regId/cancel", []);
check('member cancels their registration (My events)', $r['status'] === 303 && q_val('SELECT status FROM event_registrations WHERE id = ?', [$regId]) === 'cancelled');
$otherReg = (int) q_val("SELECT id FROM event_registrations WHERE email = 'gwen@example.test'");
check('a member cannot cancel someone else\'s registration', $joiner->submit('/account/events', "/account/events/$otherReg/cancel", [])['status'] === 404);
$r = $joiner->get('/account/privacy/export');
$export = json_decode($r['body'], true);
check('data export downloads JSON with the profile', str_contains($r['type'], 'application/json') && ($export['member']['first_name'] ?? '') === 'Joanna' && !isset($export['member']['card_code']));
$r = $joiner->submit('/account/privacy', '/account/privacy/delete', ['password' => 'wrong']);
check('deletion request needs the password', q_val('SELECT deletion_requested_at FROM members WHERE id = ?', [$memberId]) === null);
$r = $joiner->submit('/account/privacy', '/account/privacy/delete', ['password' => 'joiner-password-1']);
check('deletion request recorded and admins emailed', q_val('SELECT deletion_requested_at FROM members WHERE id = ?', [$memberId]) !== null && last_mail_link('#/admin/members/' . $memberId . '#') !== '');
status_is($admin, '/admin/members?status=deletion', 200, 'Joanna');
// Group registration
$r = $groupBrowser->post('/join/group', ['name' => 'Smoke Valley Association', 'contact_person' => 'Gabi Group', 'country' => 'United Kingdom', 'email' => 'group@example.test',
    'password' => 'group-password-12', 'password_confirm' => 'group-password-12', 'consent' => '1'] + $groupTokens);
check('group registration creates an unverified group account', $r['status'] === 303 && q_val("SELECT status FROM groups_orgs WHERE name = 'Smoke Valley Association'") === 'hidden');
check('unverified groups are not listed', !str_contains($visitor->get('/groups')['body'], 'Smoke Valley Association'));
$gPath = (string) parse_url(last_mail_link('#' . preg_quote($base, '#') . '/join/verify/[a-f0-9]{64}#'), PHP_URL_PATH);
$r = $groupBrowser->submit($gPath, $gPath, []);
check('verified group gets a group ID and is listed', $r['status'] === 303 && str_starts_with((string) q_val("SELECT group_number FROM groups_orgs WHERE name = 'Smoke Valley Association'"), 'GRP-') && str_contains($visitor->get('/groups')['body'], 'Smoke Valley Association'));
status_is($groupBrowser, '/account', 200, 'Group ID');
status_is($groupBrowser, '/account/card', 404);
// Access control
status_is($visitor, '/account', 303);
status_is($visitor, '/account/card/print', 303);
status_is($admin, $verifyUrl . 'x', 404);
$r = $admin->submit("/admin/members/$memberId", "/admin/members/$memberId/delete", ['confirm' => 'DELETE']);
check('admin confirms the deletion; the number stays retired', $r['status'] === 303 && q_val('SELECT id FROM members WHERE id = ?', [$memberId]) === null
    && q_val('SELECT number FROM issued_numbers WHERE number = ?', [$member['member_number']]) !== null);
status_is($checker, $verifyUrl, 404, 'Not a valid card');

// ---- Permissions -----------------------------------------------------------
create_user('limited@example.test', 'limited-admin-password', 'admin', 'Lim Ited', 'active', ['events']);
create_user('member@example.test', 'member-password-123', 'member', 'Mo Member', 'active');
$limited = new Browser($base);
$r = $limited->submit('/login', '/login', ['email' => 'limited@example.test', 'password' => 'limited-admin-password']);
check('limited admin logs in and lands on /admin', $r['status'] === 303 && $r['location'] === '/admin');
status_is($limited, '/admin', 200);
status_is($limited, '/admin/events', 200);
status_is($limited, '/admin/groups', 403);
status_is($limited, '/admin/posts', 403);
status_is($limited, '/admin/settings', 403);
status_is($limited, '/admin/design', 403);
status_is($limited, '/admin/members', 403);
status_is($limited, '/admin/members.csv', 403);
status_is($limited, '/admin/custom-fields', 403);
status_is($limited, '/admin/pages/' . $homeId . '/builder', 403);
check('limited admin sidebar hides other sections', !str_contains($limited->get('/admin')['body'], 'href="/admin/posts"'));
$member = new Browser($base);
$r = $member->submit('/login', '/login', ['email' => 'member@example.test', 'password' => 'member-password-123']);
check('member logs in and lands on /account', $r['status'] === 303 && $r['location'] === '/account');
status_is($member, '/admin', 403);
status_is($member, '/admin/posts', 403);
status_is($member, '/admin/design', 403);
status_is($member, '/admin/members', 403);
status_is($member, '/members-noticeboard', 200, 'Members');
$r = $member->get('/downloads/' . $membersDl['id'] . '/file');
check('members can download members-only files', $r['status'] === 200);
$r = $member->submit('/logout', '/logout', []);
check('logout works', $r['status'] === 303 && $member->get('/admin')['status'] === 303);

// ---- Phases 5-6: communications, jobs, comments, radio --------------------
require __DIR__ . '/smoke_comms.php';

// ---- Phase 7: users & roles, audit, backups, health, permission matrix, CSRF --
require __DIR__ . '/smoke_admin.php';

// ---- Addendum A: newsletter opt-in, essential notices, bounce handling ------
require __DIR__ . '/smoke_addendum.php';

// Addendum A6 design system.
require __DIR__ . '/smoke_design.php';

// Addendum B: sidebar groups, log in as a member, help centre.
require __DIR__ . '/smoke_addendum_b.php';

// Addendum C: site icons, organisation name in the admin, cascading location fields.
require __DIR__ . '/smoke_addendum_c.php';

// ---- Login safety ----------------------------------------------------------
$r = $visitor->post('/login', ['email' => 'sam@example.test', 'password' => 'x']);
check('login POST without CSRF token is rejected (419)', $r['status'] === 419);
$r = $visitor->submit('/login', '/login', ['email' => 'sam@example.test', 'password' => 'smoke-test-password', 'next' => 'https://evil.example/']);
check('login ignores off-site "next" redirects', $r['status'] === 303 && $r['location'] === '/admin');
$attacker = new Browser($base);
for ($i = 0; $i < 10; $i++) {
    $attacker->submit('/login', '/login', ['email' => 'member@example.test', 'password' => 'wrong-' . $i]);
}
$attacker->submit('/login', '/login', ['email' => 'member@example.test', 'password' => 'member-password-123']);
check('login is locked after 10 failures (acceptance 12)', str_contains($attacker->get('/login')['body'], 'Too many failed attempts'));
$r = $visitor->submit('/forgot-password', '/forgot-password', ['email' => 'nobody@example.test']);
check('forgot password gives the same answer for unknown emails', $r['status'] === 303 && $r['location'] === '/login');

// ---- Server log ------------------------------------------------------------
proc_terminate($proc);
usleep(200000);
$log = (string) file_get_contents($serverLog);
$problems = array_filter(explode("\n", $log), static fn (string $l): bool => (bool) preg_match('/(PHP )?(Fatal|Warning|Notice|Deprecated|Parse error)/i', $l));
check('no PHP warnings or errors in the server log', $problems === [], implode("\n      ", array_slice($problems, 0, 5)));

printf("\n%d checks, %d failed\n", $checks, count($failures));
exit($failures ? 1 : 0);
