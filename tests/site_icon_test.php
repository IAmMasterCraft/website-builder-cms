<?php
declare(strict_types=1);

// Addendum C: C1 site icons (favicon set, manifest) and C2 the organisation's name in the admin.

// ---- C1 icons ----------------------------------------------------------------------------

/** Parse an ICO header: list of [width, height, bpp]. */
function c1_ico_entries(string $bin): array
{
    $h = unpack('vreserved/vtype/vcount', substr($bin, 0, 6));
    $out = [];
    for ($i = 0; $i < $h['count']; $i++) {
        $e = unpack('Cw/Ch/Ccolors/Cres/vplanes/vbpp/Vsize/Voffset', substr($bin, 6 + 16 * $i, 16));
        $dib = unpack('Vhs/Vw/Vh', substr($bin, $e['offset'], 12));
        same(40, $dib['hs'], 'BITMAPINFOHEADER');
        same($e['w'] * 2, $dib['h'], 'height includes the AND mask');
        $out[] = [$e['w'], $e['h'], $e['bpp']];
    }
    return $out;
}

test('C1 icons: generated from the initial on the tile colours; ICO has 16/32/48', function (): void {
    setting_set('site.favicon_media_id', null);
    setting_set('site.name', 'Ekiti Parapo UK');
    setting_set('design', []);
    settings_all([]);
    $sig = favicon_ensure();
    ok($sig !== null && preg_match('/^[a-f0-9]{16}$/', $sig) === 1);
    $dir = favicon_dir((string) $sig);
    same([[16, 16, 32], [32, 32, 32], [48, 48, 32]], c1_ico_entries((string) file_get_contents($dir . '/favicon.ico')));
    foreach (['favicon-32.png' => 32, 'apple-touch-icon.png' => 180, 'icon-192.png' => 192, 'icon-512.png' => 512] as $file => $px) {
        $info = getimagesize($dir . '/' . $file);
        same([$px, $px, 'image/png'], [$info[0], $info[1], $info['mime']], $file);
    }
    $img = imagecreatefrompng($dir . '/icon-512.png');
    same(127, (imagecolorat($img, 2, 2) >> 24) & 0x7f, 'rounded corners are transparent');
    $bg = design_effective()['accent'];
    $c = imagecolorat($img, 60, 256);
    same(strtolower($bg), sprintf('#%02x%02x%02x', ($c >> 16) & 255, ($c >> 8) & 255, $c & 255), 'tile in the header tile colour');
    $apple = imagecreatefrompng($dir . '/apple-touch-icon.png');
    same(0, (imagecolorat($apple, 1, 1) >> 24) & 0x7f, 'apple icon is opaque');
    // A new name (other initial) or colour gives a new set; the old folder is removed.
    setting_set('site.name', 'Harbourside');
    settings_all([]);
    $sig2 = favicon_ensure();
    ok($sig2 !== $sig && is_dir(favicon_dir((string) $sig2)) && !is_dir($dir));
    setting_set('design', ['accent' => '#aa0000']);
    settings_all([]);
    ok(favicon_ensure() !== $sig2, 'colour change regenerates');
    setting_set('design', []);
    settings_all([]);
    same('E', favicon_initial('Ẹgbẹ́ Ọmọ'));
    same('#', favicon_initial('合唱团'));
});

test('C1 icons: head tags, manifest (site name, brand colour) and the uploaded icon', function (): void {
    setting_set('site.name', 'Harbourside Community Association');
    settings_all([]);
    $tags = favicon_head_tags();
    $sig = favicon_ensure();
    contains('<link rel="icon" href="/icons/' . $sig . '/favicon.ico" sizes="48x48">', $tags);
    contains('rel="apple-touch-icon" href="/icons/' . $sig . '/apple-touch-icon.png"', $tags);
    contains('<link rel="manifest" href="/site.webmanifest?v=', $tags);
    $m = favicon_manifest();
    same('Harbourside Community Association', $m['name']);
    same('Harbourside', $m['short_name']);
    same(design_effective()['brand'], $m['theme_color']);
    same(['/icons/' . $sig . '/icon-192.png', '/icons/' . $sig . '/icon-512.png'], array_column($m['icons'], 'src'));
    // Upload rules: at least 512 x 512, raster only.
    $small = fixture_jpeg(300, 300);
    contains('512', (string) favicon_upload_problem($small, 'small.jpg', (int) filesize($small)));
    $big = fixture_jpeg(700, 600);
    same(null, favicon_upload_problem($big, 'big.jpg', (int) filesize($big)));
    $svg = fixture_file('<svg xmlns="http://www.w3.org/2000/svg"/>', 'svg');
    ok(favicon_upload_problem($svg, 'x.svg', (int) filesize($svg)) !== null, 'SVG refused');
    $id = media_store($big, 'icon.jpg', (int) filesize($big), null, false, ['kind' => 'image', 'folder' => 'site']);
    setting_set('site.favicon_media_id', $id);
    settings_all([]);
    media_forget($id);
    $sig2 = favicon_ensure();
    ok($sig2 !== $sig, 'uploaded icon has its own set');
    $img = imagecreatefrompng(favicon_dir((string) $sig2) . '/icon-512.png');
    same(0, (imagecolorat($img, 2, 2) >> 24) & 0x7f, 'uploaded square fills the icon');
    ok(media_usage($id) !== [], 'the icon is "in use" so it cannot be deleted from the media library');
    setting_set('site.favicon_media_id', null);
    settings_all([]);
});

test('C2 admin shows the organisation name and tile; mail sender defaults to the site name', function (): void {
    setting_set('site.name', 'Ekiti Parapo UK');
    setting_set('mail.from_name', '');
    settings_all([]);
    ok(str_starts_with((string) favicon_tile_url(), '/icons/'));
    same('Ekiti Parapo UK', mail_from_name('', 'Gather', 'Ekiti Parapo UK'), 'config "Gather" from older installers is ignored');
    same('Ekiti Parapo UK', mail_from_name('', '', 'Ekiti Parapo UK'));
    same('EPU Office', mail_from_name('', 'EPU Office', 'Ekiti Parapo UK'));
    setting_set('mail.from_name', 'EPU Secretary');
    settings_all([]);
    same('EPU Secretary', mail_from()[1]);
    setting_set('mail.from_name', '');
    $layout = (string) file_get_contents(BASE . '/templates/layouts/admin.php');
    not_contains('>Gather</span>', $layout);
    contains('Powered by', $layout);
    contains("\$c['tile_url']", $layout);
});
