<?php
declare(strict_types=1);

/** Installed themes: themes/<key>/theme.json. */
function theme_list(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $out = [];
    foreach (glob(BASE . '/themes/*/theme.json') ?: [] as $file) {
        $key = basename(dirname($file));
        if (!preg_match('/^[a-z0-9-]+$/', $key)) {
            continue;
        }
        $meta = json_decode((string) file_get_contents($file), true);
        if (is_array($meta)) {
            $out[$key] = $meta + ['name' => $key, 'description' => '', 'swatches' => []];
            $out[$key]['has_preview'] = is_file(BASE . '/themes/' . $key . '/preview.png');
        }
    }
    // Civic first (the default), then alphabetical.
    uksort($out, static fn (string $a, string $b): int => [$a !== 'civic', $a] <=> [$b !== 'civic', $b]);
    return $cache = $out;
}

function active_theme(): string
{
    $key = (string) setting('site.theme', 'civic');
    return array_key_exists($key, theme_list()) ? $key : 'civic';
}

/**
 * Theme for this request: staff may preview another theme with ?theme_preview=
 * (used by the Design screen's live preview).
 */
function request_theme(): array
{
    $preview = (string) ($_GET['theme_preview'] ?? '');
    if ($preview !== '' && array_key_exists($preview, theme_list()) && is_staff(current_user())) {
        return [$preview, true];
    }
    return [active_theme(), false];
}

/**
 * The theme's CSS: self-hosted font faces, theme tokens, overrides, then the
 * super admin's customisations (unless $custom is false: plain theme for previews).
 */
function theme_css(?string $key = null, bool $custom = true): string
{
    $key ??= active_theme();
    $dir = BASE . '/themes/' . $key;
    $css = '/* theme: ' . $key . " */\n" . design_font_faces();
    foreach (['tokens.css', 'overrides.css'] as $f) {
        if (is_file($dir . '/' . $f)) {
            $css .= (string) file_get_contents($dir . '/' . $f) . "\n";
        }
    }
    $design = $custom ? design_effective($key) : theme_design_defaults($key);
    $css .= "/* design */\n" . design_tokens_css(design_tokens($design));
    return $css;
}

function theme_css_url(?string $key = null, bool $custom = true): string
{
    $key ??= active_theme();
    $v = (int) filemtime(BASE . '/src/lib/design.php');
    foreach (['tokens.css', 'overrides.css', 'theme.json'] as $f) {
        $file = BASE . '/themes/' . $key . '/' . $f;
        $v = max($v, is_file($file) ? (int) filemtime($file) : 0);
    }
    $hash = substr(sha1(json_encode($custom ? design_effective($key) : theme_design_defaults($key), JSON_THROW_ON_ERROR)), 0, 8);
    return '/theme.css?t=' . $key . ($custom ? '' : '&plain=1') . '&v=' . $v . '-' . $hash;
}
