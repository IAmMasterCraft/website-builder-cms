<?php
declare(strict_types=1);

// Addendum C smoke checks through php -S: C1 site icons on every page type,
// C2 the organisation's name in the admin, C4 cascading location fields.
// Included by tests/smoke.php after smoke_addendum_b.php (uses Browser,
// check(), status_is(), guard_tokens(), $admin (super admin), $visitor,
// $impMember (an active member), $impMid, $base, login_as(), meta_csrf()).

$siteName = (string) q_val("SELECT JSON_UNQUOTE(`value`) FROM settings WHERE `key` = 'site.name'") ?: 'Gather';

// ---- C1: icons -----------------------------------------------------------------------
$home = $visitor->get('/')['body'];
check('C1: public pages link the generated favicon set and manifest', (bool) preg_match('#<link rel="icon" href="/icons/([a-f0-9]{16})/favicon\.ico" sizes="48x48">#', $home, $sm)
    && str_contains($home, 'rel="apple-touch-icon" href="/icons/' . ($sm[1] ?? 'x') . '/apple-touch-icon.png"') && str_contains($home, '<link rel="manifest" href="/site.webmanifest?v='));
$sig = $sm[1] ?? '';
$pages = ['/login' => $visitor, '/help' => $visitor, '/no-such-page-c1' => $visitor, '/admin' => $admin, '/admin/help' => $admin, '/account' => $impMember, '/account/card/print' => $impMember, '/join' => $visitor];
$missing = [];
foreach ($pages as $p => $b) {
    if (!str_contains($b->get($p)['body'], '/icons/' . $sig . '/favicon.ico')) {
        $missing[] = $p;
    }
}
check('C1: the icon is on site, member, admin, help, error and card print pages', $missing === [] && $sig !== '', implode(', ', $missing));
$r = $visitor->get('/icons/' . $sig . '/icon-192.png');
check('C1: icon files are PNGs with a long, immutable cache for the current version', $r['status'] === 200 && $r['type'] === 'image/png' && str_contains($r['headers'], 'max-age=31536000, immutable') && getimagesizefromstring($r['body'])[0] === 192);
$r = $visitor->get('/icons/0000000000000000/icon-192.png');
check('C1: an old version still gets the current icon, briefly cached', $r['status'] === 200 && str_contains($r['headers'], 'max-age=3600'));
check('C1: unknown icon names are 404', $visitor->get('/icons/' . $sig . '/config.php')['status'] === 404 && $visitor->get('/icons/' . $sig . '/..%2Fconfig.php')['status'] === 404);
$r = $visitor->get('/favicon.ico');
check('C1: /favicon.ico is the generated ICO (16/32/48)', $r['status'] === 200 && $r['type'] === 'image/x-icon' && substr($r['body'], 0, 4) === "\x00\x00\x01\x00" && unpack('v', substr($r['body'], 4, 2))[1] === 3);
check('C1: /apple-touch-icon.png is 180 px', getimagesizefromstring($visitor->get('/apple-touch-icon.png')['body'])[0] === 180);
$r = $visitor->get('/site.webmanifest');
$manifest = json_decode($r['body'], true);
check('C1: site.webmanifest has the site name, brand colour and icons', str_contains($r['type'], 'application/manifest+json') && ($manifest['name'] ?? '') === $siteName
    && preg_match('/^#[0-9a-f]{6}$/', (string) ($manifest['theme_color'] ?? '')) === 1 && count($manifest['icons'] ?? []) === 2);
// Upload: too small refused, a big square accepted (new version), then back to the letter tile.
$tmpC = sys_get_temp_dir() . '/gather-c1-' . bin2hex(random_bytes(3));
mkdir($tmpC);
foreach ([300 => 'small', 640 => 'big'] as $px => $name) {
    $im = imagecreatetruecolor($px, $px);
    imagefilledrectangle($im, 0, 0, $px, $px, imagecolorallocate($im, 20, 140, 90));
    imagepng($im, "$tmpC/$name.png");
}
$settingsForm = static function () use ($admin): array {
    $html = $admin->get('/admin/settings')['body'];
    preg_match_all('/<input([^>]+)>/', $html, $m, PREG_SET_ORDER);
    $f = [];
    foreach ($m as [, $attrs]) {
        if (!preg_match('/name="([a-z_0-9]+)"/', $attrs, $nm) || !preg_match('/value="([^"]*)"/', $attrs, $vm)
            || (preg_match('/type="(checkbox|radio)"/', $attrs) && !str_contains($attrs, ' checked')) || str_contains($attrs, 'type="file"')) {
            continue;
        }
        $f[$nm[1]] = html_entity_decode($vm[1], ENT_QUOTES);
    }
    preg_match_all('/<select[^>]+name="([a-z_]+)"[^>]*>(.*?)<\/select>/s', $html, $sm2, PREG_SET_ORDER);
    foreach ($sm2 as [, $n, $opts]) {
        $f[$n] = preg_match('/<option value="([^"]*)" selected/', $opts, $om) ? html_entity_decode($om[1], ENT_QUOTES) : '';
    }
    preg_match_all('/<textarea[^>]+name="([a-z_]+)"[^>]*>(.*?)<\/textarea>/s', $html, $tm, PREG_SET_ORDER);
    foreach ($tm as [, $n, $v]) {
        $f[$n] = html_entity_decode($v, ENT_QUOTES);
    }
    $f['site_name'] = $f['site_name'] ?? 'Smoke site';
    unset($f['favicon_remove'], $f['logo_remove']);
    return $f;
};
$f = $settingsForm();
$r = $admin->post('/admin/settings', $f + ['_csrf' => meta_csrf($admin, '/admin/settings'), 'favicon_upload' => new CURLFile("$tmpC/small.png", 'image/png', 'small.png')]);
check('C1: an icon under 512 × 512 is refused with a clear message', $r['status'] === 422 && str_contains($r['body'], 'at least 512'));
$r = $admin->post('/admin/settings', $f + ['_csrf' => meta_csrf($admin, '/admin/settings'), 'favicon_upload' => new CURLFile("$tmpC/big.png", 'image/png', 'big.png')]);
preg_match('#/icons/([a-f0-9]{16})/favicon\.ico#', $visitor->get('/')['body'], $sm3);
check('C1: uploading an icon makes a new versioned set used everywhere (audited)', $r['status'] === 303 && ($sm3[1] ?? $sig) !== $sig
    && str_contains((string) q_val("SELECT summary FROM audit_log WHERE action = 'update' AND item_type = 'settings' ORDER BY id DESC LIMIT 1"), 'site icon uploaded'),
    $r['status'] . ' ' . implode(' | ', array_map('strip_tags', preg_match_all('/<p class="error"[^>]*>(.*?)<\/p>/', $r['body'], $em) ? $em[1] : [])));
$r = $admin->post('/admin/settings', $settingsForm() + ['_csrf' => meta_csrf($admin, '/admin/settings'), 'favicon_remove' => '1']);
preg_match('#/icons/([a-f0-9]{16})/favicon\.ico#', $visitor->get('/')['body'], $sm4);
check('C1: "use the letter tile instead" goes back to the generated tile', $r['status'] === 303 && ($sm4[1] ?? '') === $sig);
array_map('unlink', glob("$tmpC/*") ?: []);
rmdir($tmpC);

// ---- C2: organisation name in the admin -------------------------------------------------
$h = $admin->get('/admin/members')['body'];
check('C2: the sidebar header shows the site name and its icon tile (full name as tooltip)', str_contains($h, '<span class="logo-text">' . e($siteName) . '</span>') && str_contains($h, 'title="' . e($siteName) . ' admin"')
    && (bool) preg_match('#<img class="logo-mark logo-img" src="/icons/[a-f0-9]{16}/icon-192\.png"#', $h));
check('C2: browser titles use the site name; "Gather" only in the footer credit', str_contains($h, '<title>Members · Admin · ' . e($siteName) . '</title>') && str_contains($h, 'Powered by <a href="/admin/help">Gather</a>')
    && !str_contains($h, '<span class="logo-text">Gather</span>'));
