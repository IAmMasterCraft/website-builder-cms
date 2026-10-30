<?php
declare(strict_types=1);

/*
 * Site icons (Addendum C1): favicon.ico (16/32/48), a 32 px PNG, the
 * 180 px apple-touch-icon, 192/512 px app icons and site.webmanifest, made
 * with GD from the icon uploaded under Settings, or, when there is none,
 * from the site name's initial on the colours of the header logo tile.
 * Files live in storage/icons/<signature>/; the signature changes with the
 * source image, the initial and the colours, so URLs are versioned and the
 * set is regenerated on the next page view after any of them changes.
 */

/** Bump when the drawing changes, so every site regenerates its icons. */
const FAVICON_VERSION = 3;
/** File name => [MIME type, pixel size]. */
const FAVICON_FILES = [
    'favicon.ico' => ['image/x-icon', 48],
    'favicon-32.png' => ['image/png', 32],
    'apple-touch-icon.png' => ['image/png', 180],
    'icon-192.png' => ['image/png', 192],
    'icon-512.png' => ['image/png', 512],
];
const FAVICON_MIN_SIZE = 512;
/** Review: icon uploads above this many pixels are refused (decoding them costs memory and time). */
const FAVICON_MAX_PIXELS = 16_000_000;

/** What the icons are made from: uploaded media id (or null), initial, tile colours, site name. */
function favicon_inputs(): array
{
    try {
        $name = trim((string) setting('site.name', 'Gather')) ?: 'Gather';
        $mediaId = (int) setting('site.favicon_media_id', 0) ?: null;
        $media = $mediaId !== null ? media_find($mediaId) : null;
        $d = design_effective();
        $t = design_tokens($d);
        return ['media' => $media, 'name' => $name, 'initial' => favicon_initial($name), 'bg' => $d['accent'], 'ink' => $t['--c-accent-ink'], 'brand' => $d['brand']];
    } catch (Throwable) {
        // No database yet (installer): the default tile.
        return ['media' => null, 'name' => 'Gather', 'initial' => 'G', 'bg' => '#0b7577', 'ink' => '#ffffff', 'brand' => '#12355b'];
    }
}

/** First letter of the name, as a character the bundled font can draw (accents removed). */
function favicon_initial(string $name): string
{
    $first = mb_substr(trim($name), 0, 1);
    if (class_exists('Normalizer')) {
        $first = mb_substr((string) preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize($first, Normalizer::FORM_D)), 0, 1);
    }
    $first = mb_strtoupper($first);
    if (preg_match('/^[A-Z0-9]$/', $first)) {
        return $first;
    }
    return preg_match('/[A-Za-z0-9]/', $name, $m) ? strtoupper($m[0]) : '#';
}

function favicon_signature(?array $in = null): string
{
    $in ??= favicon_inputs();
    $src = $in['media'] !== null ? $in['media']['id'] . ':' . $in['media']['path'] : 'tile:' . $in['initial'] . ':' . $in['bg'] . ':' . $in['ink'];
    return substr(hash('sha256', FAVICON_VERSION . '|' . $src), 0, 16);
}

function favicon_dir(string $sig): string
{
    return BASE . '/storage/icons/' . $sig;
}

/**
 * Make sure the current icon set exists (generated at most once per change).
 * Returns the signature, or null when the icons cannot be made (no GD, the
 * folder is not writable): the layouts then fall back to the static SVG.
 */
function favicon_ensure(?array $in = null, bool $wait = false): ?string
{
    static $done = [];
    $in ??= favicon_inputs();
    $sig = favicon_signature($in);
    if (isset($done[$sig])) {
        return $done[$sig] ? $sig : null;
    }
    $dir = favicon_dir($sig);
    $ready = static fn (): bool => is_file($dir . '/icon-512.png') && is_file($dir . '/favicon.ico');
    if ($ready()) {
        $done[$sig] = true;
        return $sig;
    }
    // Review: one generator at a time (flock). Public page views do not wait: while another
    // request draws the set they use the static SVG; admin saves ($wait) wait for it.
    $lock = null;
    try {
        $root = dirname($dir);
        if (!is_dir($root) && !mkdir($root, 0755, true) && !is_dir($root)) {
            throw new RuntimeException('Cannot create ' . $root);
        }
        $lock = fopen($root . '/.lock', 'c');
        if ($lock === false || !flock($lock, $wait ? LOCK_EX : LOCK_EX | LOCK_NB)) {
            return null; // not remembered: the next page view looks again
        }
        if (!$ready()) {
            favicon_generate($in, $dir);
        }
        $done[$sig] = true;
        return $sig;
    } catch (Throwable $e) {
        error_log('Favicon generation failed: ' . $e->getMessage());
        $done[$sig] = false;
        return null;
    } finally {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/** Draw every file into $dir (atomically: a temporary folder renamed into place), then remove older sets. */
function favicon_generate(array $in, string $dir): void
{
    if (!function_exists('imagecreatetruecolor')) {
        throw new RuntimeException('GD is not available');
    }
    $root = dirname($dir);
    if (!is_dir($root) && !mkdir($root, 0755, true) && !is_dir($root)) {
        throw new RuntimeException('Cannot create ' . $root);
    }
    $tmp = $root . '/.tmp-' . bin2hex(random_bytes(6));
    if (!mkdir($tmp, 0755)) {
        throw new RuntimeException('Cannot write to ' . $root);
    }
    try {
        $base = favicon_base_image($in, false); // 512 px with transparent corners (tile) or the uploaded square
        $opaque = favicon_base_image($in, true); // full-bleed square for Apple (it rounds the corners itself)
        foreach ([32 => 'favicon-32.png', 192 => 'icon-192.png', 512 => 'icon-512.png'] as $size => $file) {
            favicon_write_png(favicon_scale($base, $size), $tmp . '/' . $file);
        }
        favicon_write_png(favicon_scale($opaque, 180), $tmp . '/apple-touch-icon.png');
        $ico = ico_encode([favicon_scale($base, 16), favicon_scale($base, 32), favicon_scale($base, 48)]);
        if (file_put_contents($tmp . '/favicon.ico', $ico) !== strlen($ico)) {
            throw new RuntimeException('Could not write favicon.ico');
        }
        if (is_dir($dir)) {
            favicon_rmdir($dir);
        }
        if (!rename($tmp, $dir)) {
            throw new RuntimeException('Could not move the icons into place');
        }
    } finally {
        if (is_dir($tmp)) {
            favicon_rmdir($tmp);
        }
    }
    foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $old) {
        if ($old !== $dir && preg_match('/^[a-f0-9]{16}$/', basename($old))) {
            favicon_rmdir($old);
        }
    }
}

function favicon_rmdir(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $f) {
        is_dir($f) ? favicon_rmdir($f) : unlink($f);
    }
    rmdir($dir);
}

/** 512 × 512 source: the uploaded image (centre square) or the generated letter tile. */
function favicon_base_image(array $in, bool $opaque): GdImage
{
    if ($in['media'] !== null && ($path = media_file_path($in['media'])) !== null) {
        $src = image_load($path, (string) $in['media']['mime']);
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $out = favicon_canvas(512, $opaque ? '#ffffff' : null);
        imagealphablending($out, true);
        imagecopyresampled($out, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), 512, 512, $side, $side);
        return $out;
    }
    return favicon_tile($in['initial'], $in['bg'], $in['ink'], 512, !$opaque);
}

/** Transparent (or solid) true-colour canvas. */
function favicon_canvas(int $size, ?string $fill = null): GdImage
{
    $img = imagecreatetruecolor($size, $size);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    [$r, $g, $b] = $fill !== null ? hex_rgb($fill) : [0, 0, 0];
    imagefilledrectangle($img, 0, 0, $size - 1, $size - 1, imagecolorallocatealpha($img, $r, $g, $b, $fill !== null ? 0 : 127));
    return $img;
}

/** "#0b7577" -> [11, 117, 119] */
function hex_rgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
}

/**
 * The header logo tile as an image: the initial, bold and centred, on a
 * rounded square (drawn at twice the size and scaled down for smooth edges).
 */
function favicon_tile(string $initial, string $bg, string $ink, int $size = 512, bool $rounded = true): GdImage
{
    $s = $size * 2;
    $img = favicon_canvas($s);
    imagealphablending($img, true);
    [$r, $g, $b] = hex_rgb($bg);
    $fill = imagecolorallocate($img, $r, $g, $b);
    if ($rounded) {
        $rad = (int) round($s * 0.22);
        imagefilledrectangle($img, $rad, 0, $s - 1 - $rad, $s - 1, $fill);
        imagefilledrectangle($img, 0, $rad, $s - 1, $s - 1 - $rad, $fill);
        foreach ([[$rad, $rad], [$s - 1 - $rad, $rad], [$rad, $s - 1 - $rad], [$s - 1 - $rad, $s - 1 - $rad]] as [$cx, $cy]) {
            imagefilledellipse($img, $cx, $cy, $rad * 2, $rad * 2, $fill);
        }
    } else {
        imagefilledrectangle($img, 0, 0, $s - 1, $s - 1, $fill);
    }
    [$r, $g, $b] = hex_rgb($ink);
    $color = imagecolorallocate($img, $r, $g, $b);
    $font = BASE . '/src/fonts/Lato-Regular.ttf';
    if (function_exists('imagettftext') && is_file($font)) {
        $pt = $s * 0.46;
        $box = imagettfbbox($pt, 0, $font, $initial);
        $w = max($box[2], $box[4]) - min($box[0], $box[6]);
        $h = max($box[1], $box[3]) - min($box[5], $box[7]);
        $x = (int) round(($s - $w) / 2 - min($box[0], $box[6]));
        $y = (int) round(($s + $h) / 2 - max($box[1], $box[3]));
        // Lato Regular drawn around a small circle reads as a bold weight at icon sizes.
        $stroke = max(1, (int) round($s * 0.018));
        for ($a = 0; $a < 360; $a += 30) {
            imagettftext($img, $pt, 0, $x + (int) round($stroke * cos(deg2rad($a))), $y + (int) round($stroke * sin(deg2rad($a))), $color, $font, $initial);
        }
        imagettftext($img, $pt, 0, $x, $y, $color, $font, $initial);
    } else {
        // No FreeType: GD's built-in font, scaled up.
        $small = favicon_canvas(16);
        imagealphablending($small, true);
        imagestring($small, 5, 4, 0, $initial, imagecolorallocate($small, $r, $g, $b));
        imagecopyresampled($img, $small, (int) ($s * 0.2), (int) ($s * 0.15), 0, 0, (int) ($s * 0.6), (int) ($s * 0.6), 16, 16);
    }
    return favicon_scale($img, $size);
}

function favicon_scale(GdImage $src, int $size): GdImage
{
    $out = favicon_canvas($size);
    imagecopyresampled($out, $src, 0, 0, 0, 0, $size, $size, imagesx($src), imagesy($src));
    return $out;
}

function favicon_write_png(GdImage $img, string $file): void
{
    imagesavealpha($img, true);
    if (!imagepng($img, $file, 9)) {
        throw new RuntimeException('Could not write ' . basename($file));
    }
}

/**
 * Minimal ICO encoder: one 32-bit BMP (BGRA + 1-bit AND mask) entry per
 * image, the format every browser and Windows version reads.
 * @param list<GdImage> $images square images up to 256 px
 */
function ico_encode(array $images): string
{
    $count = count($images);
    $header = pack('vvv', 0, 1, $count);
    $dir = '';
    $data = '';
    $offset = 6 + 16 * $count;
    foreach ($images as $img) {
        $w = imagesx($img);
        $h = imagesy($img);
        $pixels = '';
        $maskRow = (int) ceil($w / 32) * 4;
        $mask = '';
        for ($y = $h - 1; $y >= 0; $y--) { // bottom-up rows
            $bits = array_fill(0, $maskRow, 0);
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($img, $x, $y);
                $alpha = 255 - (int) round((($c >> 24) & 0x7f) * 255 / 127);
                $pixels .= pack('CCCC', $c & 0xff, ($c >> 8) & 0xff, ($c >> 16) & 0xff, $alpha);
                if ($alpha === 0) {
                    $bits[intdiv($x, 8)] |= 0x80 >> ($x % 8);
                }
            }
            $mask .= pack('C*', ...$bits);
        }
        $bmp = pack('VVVvvVVVVVV', 40, $w, $h * 2, 1, 32, 0, strlen($pixels) + strlen($mask), 0, 0, 0, 0) . $pixels . $mask;
        $dir .= pack('CCCCvvVV', $w >= 256 ? 0 : $w, $h >= 256 ? 0 : $h, 0, 0, 1, 32, strlen($bmp), $offset);
        $data .= $bmp;
        $offset += strlen($bmp);
    }
    return $header . $dir . $data;
}

/** <link> tags for every layout's <head>. */
function favicon_head_tags(): string
{
    $sig = favicon_ensure();
    if ($sig === null) {
        return '<link rel="icon" href="' . e(asset('assets/img/favicon.svg')) . '" type="image/svg+xml">';
    }
    $base = '/icons/' . $sig . '/';
    return '<link rel="icon" href="' . e($base . 'favicon.ico') . '" sizes="48x48">' . "\n"
        . '<link rel="icon" href="' . e($base . 'favicon-32.png') . '" type="image/png" sizes="32x32">' . "\n"
        . '<link rel="apple-touch-icon" href="' . e($base . 'apple-touch-icon.png') . '">' . "\n"
        . '<link rel="manifest" href="' . e('/site.webmanifest?v=' . favicon_manifest_version()) . '">' . "\n";
}

/** URL of the square tile shown next to the site name in the admin (C2), or null. */
function favicon_tile_url(int $size = 192): ?string
{
    $sig = favicon_ensure();
    return $sig === null ? null : '/icons/' . $sig . '/icon-' . ($size > 192 ? 512 : 192) . '.png';
}

/** The manifest changes with the icons and with the site name and brand colour. */
function favicon_manifest_version(): string
{
    $in = favicon_inputs();
    return substr(hash('sha256', favicon_signature($in) . '|' . $in['name'] . '|' . $in['brand']), 0, 12);
}

/** site.webmanifest: name = site name, theme colour = brand colour. */
function favicon_manifest(): array
{
    $in = favicon_inputs();
    $sig = favicon_ensure($in);
    $short = mb_strlen($in['name']) <= 12 ? $in['name'] : rtrim(mb_substr($in['name'], 0, 12));
    $icons = [];
    if ($sig !== null) {
        foreach ([192, 512] as $px) {
            $icons[] = ['src' => '/icons/' . $sig . '/icon-' . $px . '.png', 'sizes' => $px . 'x' . $px, 'type' => 'image/png'];
        }
    }
    return ['name' => $in['name'], 'short_name' => $short, 'start_url' => '/', 'display' => 'browser', 'theme_color' => $in['brand'], 'background_color' => '#ffffff', 'icons' => $icons];
}

/** Check an uploaded icon: a raster image (PNG, JPEG or WebP) of at least 512 × 512 pixels. */
function favicon_upload_problem(string $path, string $name, int $size): ?string
{
    try {
        $info = media_validate($path, $name, $size, 'image');
    } catch (Core\UploadException $e) {
        return $e->getMessage();
    }
    if (!in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
        return 'Use a PNG, JPEG or WebP image.';
    }
    $dim = getimagesize($path);
    if ($dim === false || $dim[0] < FAVICON_MIN_SIZE || $dim[1] < FAVICON_MIN_SIZE) {
        return 'The icon must be at least 512 × 512 pixels (a square works best).';
    }
    if ($dim[0] * $dim[1] > FAVICON_MAX_PIXELS) {
        return 'The icon is too large: use an image of at most 16 megapixels (for example 4000 × 4000).';
    }
    return null;
}
