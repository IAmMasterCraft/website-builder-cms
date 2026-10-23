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
$env = array_merge(getenv(), ['GATHER_CONFIG' => $configPath]);
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
$r = $admin->post('/install/database', ['db_host' => $db['host'], 'db_port' => (string) $db['port'], 'db_name' => $db['name'], 'db_user' => $db['user'], 'db_pass' => $db['pass']]);
check('installer POST without CSRF token is rejected (419)', $r['status'] === 419);
$r = $admin->submit('/install', '/install/database', ['db_host' => $db['host'], 'db_port' => (string) $db['port'], 'db_name' => $db['name'], 'db_user' => $db['user'], 'db_pass' => 'wrong-password']);
check('installer reports a bad database password', $r['status'] === 422 && str_contains($r['body'], 'Could not connect'));
$r = $admin->submit('/install', '/install/database', ['db_host' => $db['host'], 'db_port' => (string) $db['port'], 'db_name' => $db['name'], 'db_user' => $db['user'], 'db_pass' => $db['pass']]);
check('installer writes config.php and runs migrations', $r['status'] === 303 && $r['location'] === '/install/admin' && is_file($configPath));
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
check('builder editor loads with its config and picker', $r['status'] === 200 && str_contains($r['body'], 'id="builder-config"') && substr_count($r['body'], 'data-add-type=') === 21);
$token = preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $r['body'], $m) ? $m[1] : '';
$cfg = preg_match('#<script type="application/json" id="builder-config">(.*?)</script>#s', $r['body'], $m) ? json_decode($m[1], true) : null;
check('builder config lists 21 section types with schemas', is_array($cfg) && count($cfg['types']) === 21 && isset($cfg['types']['hero']['schema']));
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
check('contact message stored and emailed (org + copy)', $r['status'] === 303 && (int) q_val("SELECT COUNT(*) FROM contact_messages WHERE email = 'cora@example.test'") === 1
    && count(glob(BASE . '/storage/mail/*.eml') ?: []) === $mailBefore + 2);
check('thank-you message shown after the redirect', str_contains($visitor->get('/contact')['body'], 'your message has been sent'));
$r = $visitor->post('/contact', $fields + ['_csrf' => $token, 'form_ts' => $ts, 'website_url' => 'http://spam.example']);
check('honeypot submission is refused', (int) q_val("SELECT COUNT(*) FROM contact_messages WHERE email = 'cora@example.test'") === 1);
foreach (['civic', 'heritage', 'harvest', 'monochrome'] as $t) {
    $css = $visitor->get('/theme.css?t=' . $t . '&plain=1')['body'];
    check("theme $t is served with its tokens and font faces", str_contains($css, "/* theme: $t */") && str_contains($css, '@font-face'));
}

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
status_is($limited, '/admin/pages/' . $homeId . '/builder', 403);
check('limited admin sidebar hides other sections', !str_contains($limited->get('/admin')['body'], 'href="/admin/posts"'));
$member = new Browser($base);
$r = $member->submit('/login', '/login', ['email' => 'member@example.test', 'password' => 'member-password-123']);
check('member logs in and lands on /account', $r['status'] === 303 && $r['location'] === '/account');
status_is($member, '/admin', 403);
status_is($member, '/admin/posts', 403);
status_is($member, '/admin/design', 403);
status_is($member, '/members-noticeboard', 200, 'Members');
$r = $member->get('/downloads/' . $membersDl['id'] . '/file');
check('members can download members-only files', $r['status'] === 200);
$r = $member->submit('/logout', '/logout', []);
check('logout works', $r['status'] === 303 && $member->get('/admin')['status'] === 303);

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
