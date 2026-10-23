<?php
declare(strict_types=1);

/** Installed themes: themes/<key>/theme.json. */
function theme_list(): array
{
    $out = [];
    foreach (glob(BASE . '/themes/*/theme.json') ?: [] as $file) {
        $key = basename(dirname($file));
        if (!preg_match('/^[a-z0-9-]+$/', $key)) {
            continue;
        }
        $meta = json_decode((string) file_get_contents($file), true);
        if (is_array($meta)) {
            $out[$key] = $meta + ['name' => $key, 'description' => '', 'swatches' => []];
        }
    }
    ksort($out);
    return $out;
}

function active_theme(): string
{
    $key = (string) setting('site.theme', 'civic');
    return array_key_exists($key, theme_list()) ? $key : 'civic';
}

/** The active theme's CSS: tokens + overrides (+ admin customisations in phase 3). */
function theme_css(): string
{
    $key = active_theme();
    $dir = BASE . '/themes/' . $key;
    $css = '/* theme: ' . $key . " */\n";
    foreach (['tokens.css', 'overrides.css'] as $f) {
        if (is_file($dir . '/' . $f)) {
            $css .= (string) file_get_contents($dir . '/' . $f) . "\n";
        }
    }
    return $css;
}

function theme_css_url(): string
{
    $key = active_theme();
    $v = 0;
    foreach (['tokens.css', 'overrides.css'] as $f) {
        $file = BASE . '/themes/' . $key . '/' . $f;
        $v = max($v, is_file($file) ? (int) filemtime($file) : 0);
    }
    return '/theme.css?t=' . $key . '&v=' . $v;
}
