<?php
declare(strict_types=1);

/*
 * Builds public/assets/img/icons.svg (an SVG sprite of <symbol>s) from the
 * Lucide icon set (ISC licence, https://lucide.dev), plus our own brand
 * glyphs in dev-tools/icons/brands.svg (Lucide ships no X/WhatsApp marks).
 *
 * Usage (dev only, never deployed):
 *   1. curl -sL https://registry.npmjs.org/lucide-static/-/lucide-static-1.61.0.tgz -o lucide.tgz
 *      shasum -a 256 lucide.tgz   # 62773cd2ee411ad2e37c1973d1e4c4b49f9a6c57da6b5d5d0abca08756ba75f1
 *      tar xzf lucide.tgz          # -> package/icons/*.svg, package/LICENSE
 *   2. php dev-tools/build-icons.php /path/to/package
 *
 * Symbol ids are the names templates pass to icon(); the map keeps the old
 * short names (pin, help, trash...) working and adds new ones. Add an entry
 * here, re-run, and commit the sprite.
 */

const LUCIDE_VERSION = '1.61.0';

/** sprite id => Lucide icon name */
const ICON_MAP = [
    // Interface
    'search' => 'search', 'menu' => 'menu', 'close' => 'x', 'chevron-down' => 'chevron-down', 'chevron-up' => 'chevron-up',
    'chevron-left' => 'chevron-left', 'chevron-right' => 'chevron-right', 'arrow-right' => 'arrow-right', 'arrow-up' => 'arrow-up',
    'arrow-down' => 'arrow-down', 'arrow-up-right' => 'arrow-up-right', 'plus' => 'plus', 'minus' => 'minus', 'check' => 'check',
    'check-check' => 'check-check', 'circle-check' => 'circle-check', 'badge-check' => 'badge-check', 'alert' => 'circle-alert',
    'warning' => 'triangle-alert', 'external' => 'external-link', 'link' => 'link', 'share' => 'share-2', 'eye' => 'eye', 'eye-off' => 'eye-off',
    'trash' => 'trash-2', 'pen' => 'pen-line', 'settings' => 'settings', 'logout' => 'log-out', 'sidebar' => 'panel-left',
    'grid' => 'layout-grid', 'layers' => 'layers', 'sun' => 'sun', 'moon' => 'moon', 'bell' => 'bell', 'lock' => 'lock',
    'download' => 'download', 'upload' => 'upload', 'file' => 'file-text', 'file-down' => 'file-down', 'image' => 'image', 'images' => 'images',
    'play' => 'play', 'pause' => 'pause', 'quote' => 'quote', 'star' => 'star', 'help' => 'circle-help', 'rss' => 'rss', 'inbox' => 'inbox',
    'tag' => 'tag', 'flag' => 'flag', 'clock' => 'clock', 'history' => 'history', 'activity' => 'activity', 'send' => 'send', 'mail-x' => 'mail-x',
    'palette' => 'palette', 'layout-template' => 'layout-template', 'panels' => 'panels-top-left', 'text-input' => 'text-cursor-input',
    'network' => 'network', 'message' => 'message-square', 'hard-drive' => 'hard-drive-download', 'list-music' => 'list-music',
    'shield' => 'shield', 'id-card' => 'id-card', 'circle-dot' => 'circle-dot', 'move-right' => 'move-right',
    // People, places, things
    'home' => 'house', 'user' => 'user-round', 'users' => 'users-round', 'mail' => 'mail', 'phone' => 'phone', 'pin' => 'map-pin',
    'globe' => 'globe', 'calendar' => 'calendar', 'calendar-days' => 'calendar-days', 'calendar-check' => 'calendar-check',
    'ticket' => 'ticket', 'award' => 'award', 'trophy' => 'trophy', 'heart' => 'heart', 'radio' => 'radio', 'mic' => 'mic', 'music' => 'music',
    'hand-heart' => 'hand-heart', 'heart-handshake' => 'heart-handshake', 'handshake' => 'handshake', 'sprout' => 'sprout', 'leaf' => 'leaf',
    'flower' => 'flower-2', 'tree' => 'tree-pine', 'utensils' => 'utensils', 'coffee' => 'coffee', 'graduation-cap' => 'graduation-cap',
    'book-open' => 'book-open', 'shield-check' => 'shield-check', 'sparkles' => 'sparkles', 'megaphone' => 'megaphone', 'zap' => 'zap',
    'gift' => 'gift', 'building' => 'building-2', 'landmark' => 'landmark', 'map' => 'map', 'camera' => 'camera', 'briefcase' => 'briefcase',
    'hammer' => 'hammer', 'paintbrush' => 'paintbrush', 'bike' => 'bike', 'baby' => 'baby', 'stethoscope' => 'stethoscope', 'crown' => 'crown',
    'smile' => 'smile', 'house-heart' => 'house-heart', 'wifi' => 'wifi',
];

$src = rtrim((string) ($argv[1] ?? ''), '/');
if ($src === '' || !is_dir($src . '/icons')) {
    fwrite(STDERR, "Usage: php dev-tools/build-icons.php /path/to/lucide-static/package\n");
    exit(1);
}
$root = dirname(__DIR__);
$out = "<svg xmlns=\"http://www.w3.org/2000/svg\">\n<!-- Lucide v" . LUCIDE_VERSION . " (ISC, see LICENSE-lucide.txt) + Gather brand glyphs -->\n";
foreach (ICON_MAP as $id => $name) {
    $file = $src . '/icons/' . $name . '.svg';
    $svg = is_file($file) ? file_get_contents($file) : false;
    if ($svg === false) {
        fwrite(STDERR, "Missing Lucide icon $name\n");
        exit(1);
    }
    if (!preg_match('#<svg[^>]*>(.*)</svg>#s', $svg, $m)) {
        fwrite(STDERR, "Cannot parse $name\n");
        exit(1);
    }
    $inner = preg_replace('/\s+/', ' ', trim($m[1]));
    $inner = str_replace(' />', '/>', (string) $inner);
    $inner = preg_replace('/>\s+</', '><', $inner);
    $out .= '<symbol id="' . $id . '" viewBox="0 0 24 24">' . $inner . "</symbol>\n";
}
$brands = (string) file_get_contents(__DIR__ . '/icons/brands.svg');
preg_match_all('#<symbol .*?</symbol>#s', $brands, $bm);
$out .= implode("\n", $bm[0]) . "\n</svg>\n";
file_put_contents($root . '/public/assets/img/icons.svg', $out);
copy($src . '/LICENSE', $root . '/public/assets/img/LICENSE-lucide.txt');
echo 'Wrote ' . (count(ICON_MAP) + count($bm[0])) . ' symbols (' . strlen($out) . " bytes)\n";
