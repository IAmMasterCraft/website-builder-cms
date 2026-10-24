<?php
declare(strict_types=1);

/*
 * Design system controls (§8): curated fonts, colour maths, WCAG contrast,
 * and the CSS custom properties generated from the super admin's choices.
 * The same derivation runs in the browser (assets/js/design.js) for the live
 * preview; tests pin both to the same numbers.
 */

/**
 * Curated fonts. 'files' are self-hosted variable woff2 files in
 * public/assets/fonts (latin + latin-ext); system stacks download nothing.
 * @return array<string, array{label:string, stack:string, files:?string, kind:string, weights?:string, italic?:bool}>
 */
function design_fonts(): array
{
    $sans = 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif';
    $serif = '"Iowan Old Style", "Palatino Linotype", Palatino, Georgia, "Times New Roman", serif';
    return [
        'bricolage-grotesque' => ['label' => 'Bricolage Grotesque (bold display grotesk)', 'stack' => '"Bricolage Grotesque Variable", ' . $sans, 'files' => 'bricolage-grotesque', 'kind' => 'sans', 'weights' => '200 800'],
        'instrument-sans' => ['label' => 'Instrument Sans (crisp text sans)', 'stack' => '"Instrument Sans Variable", ' . $sans, 'files' => 'instrument-sans', 'kind' => 'sans', 'weights' => '400 700'],
        'newsreader'     => ['label' => 'Newsreader (editorial serif)', 'stack' => '"Newsreader Variable", ' . $serif, 'files' => 'newsreader', 'kind' => 'serif', 'weights' => '200 800', 'italic' => true],
        'inter'          => ['label' => 'Inter (clean sans)', 'stack' => '"Inter Variable", ' . $sans, 'files' => 'inter', 'kind' => 'sans'],
        'manrope'        => ['label' => 'Manrope (modern sans)', 'stack' => '"Manrope Variable", ' . $sans, 'files' => 'manrope', 'kind' => 'sans'],
        'nunito'         => ['label' => 'Nunito (friendly rounded)', 'stack' => '"Nunito Variable", ui-rounded, ' . $sans, 'files' => 'nunito', 'kind' => 'rounded'],
        'source-serif-4' => ['label' => 'Source Serif 4 (readable serif)', 'stack' => '"Source Serif 4 Variable", ' . $serif, 'files' => 'source-serif-4', 'kind' => 'serif'],
        'lora'           => ['label' => 'Lora (warm serif)', 'stack' => '"Lora Variable", ' . $serif, 'files' => 'lora', 'kind' => 'serif'],
        'fraunces'       => ['label' => 'Fraunces (characterful serif)', 'stack' => '"Fraunces Variable", ' . $serif, 'files' => 'fraunces', 'kind' => 'serif'],
        'playfair-display' => ['label' => 'Playfair Display (editorial serif)', 'stack' => '"Playfair Display Variable", ' . $serif, 'files' => 'playfair-display', 'kind' => 'serif'],
        'system-sans'    => ['label' => 'System sans (no download)', 'stack' => $sans, 'files' => null, 'kind' => 'sans'],
        'system-serif'   => ['label' => 'System serif (no download)', 'stack' => $serif, 'files' => null, 'kind' => 'serif'],
        'system-rounded' => ['label' => 'System rounded (no download)', 'stack' => 'ui-rounded, "SF Pro Rounded", "Arial Rounded MT Bold", ' . $sans, 'files' => null, 'kind' => 'rounded'],
    ];
}

/** Corner radius scales: [s, m, l, xl]. */
function design_radius_scales(): array
{
    return [
        'sharp'   => ['label' => 'Sharp', 'values' => [0, 2, 4, 6]],
        'soft'    => ['label' => 'Soft', 'values' => [4, 6, 10, 14]],
        'rounded' => ['label' => 'Rounded', 'values' => [6, 10, 16, 24]],
        'round'   => ['label' => 'Extra round', 'values' => [10, 16, 24, 32]],
    ];
}

function design_button_styles(): array
{
    return ['pill' => 'Pill', 'rounded' => 'Rounded corners', 'square' => 'Square'];
}

/** @font-face rules for every self-hosted font (browsers only fetch the ones used). */
function design_font_faces(): string
{
    $latin = 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD';
    $latinExt = 'U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF';
    $css = '';
    foreach (design_fonts() as $f) {
        if ($f['files'] === null) {
            continue;
        }
        $family = trim(explode(',', $f['stack'])[0], '" ');
        $styles = !empty($f['italic']) ? ['normal', 'italic'] : ['normal'];
        foreach ($styles as $style) {
            foreach (['latin-ext' => $latinExt, 'latin' => $latin] as $subset => $range) {
                $file = $f['files'] . '-' . $subset . '-wght-' . $style . '.woff2';
                $css .= sprintf(
                    "@font-face{font-family:\"%s\";font-style:%s;font-display:swap;font-weight:%s;src:url(/assets/fonts/%s) format(\"woff2-variations\"),url(/assets/fonts/%s) format(\"woff2\");unicode-range:%s}\n",
                    $family, $style, $f['weights'] ?? '100 900', $file, $file, $range
                );
            }
        }
    }
    return $css;
}

// ---- Colour maths -------------------------------------------------------------

function hex_valid(string $hex): bool
{
    return (bool) preg_match('/^#[0-9a-f]{6}$/i', $hex);
}

/** @return array{0:int,1:int,2:int} */
function hex_to_rgb(string $hex): array
{
    if (!hex_valid($hex)) {
        throw new InvalidArgumentException('Bad colour ' . $hex);
    }
    return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
}

/** @param array{0:int|float,1:int|float,2:int|float} $rgb */
function rgb_to_hex(array $rgb): string
{
    return sprintf('#%02x%02x%02x', ...array_map(static fn ($c): int => max(0, min(255, (int) round($c))), $rgb));
}

/** Mix $a towards $b by $t (0 = a, 1 = b) in sRGB. */
function color_mix(string $a, string $b, float $t): string
{
    $x = hex_to_rgb($a);
    $y = hex_to_rgb($b);
    return rgb_to_hex([$x[0] + ($y[0] - $x[0]) * $t, $x[1] + ($y[1] - $x[1]) * $t, $x[2] + ($y[2] - $x[2]) * $t]);
}

/** WCAG 2.x relative luminance. */
function relative_luminance(string $hex): float
{
    $out = 0.0;
    foreach (hex_to_rgb($hex) as $i => $c) {
        $s = $c / 255;
        $lin = $s <= 0.04045 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        $out += [0.2126, 0.7152, 0.0722][$i] * $lin;
    }
    return $out;
}

/** WCAG contrast ratio, 1..21. */
function contrast_ratio(string $a, string $b): float
{
    $la = relative_luminance($a);
    $lb = relative_luminance($b);
    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/** White or near-black text, whichever reads better on $bg. */
function best_ink(string $bg, string $dark = '#111111', string $light = '#ffffff'): string
{
    return contrast_ratio($bg, $light) >= contrast_ratio($bg, $dark) ? $light : $dark;
}

/** Darken $fg (towards black) until it reaches $target contrast on $bg. */
function ensure_contrast(string $fg, string $bg, float $target = 4.5): string
{
    $c = $fg;
    for ($i = 0; $i < 20 && contrast_ratio($c, $bg) < $target; $i++) {
        $c = color_mix($c, '#000000', 0.1);
    }
    return $c;
}

// ---- Tokens -------------------------------------------------------------------

/** Theme defaults from theme.json "design" (colours, fonts, radius, button). */
function theme_design_defaults(string $theme): array
{
    $meta = theme_list()[$theme] ?? [];
    $d = is_array($meta['design'] ?? null) ? $meta['design'] : [];
    return [
        'brand' => hex_valid((string) ($d['brand'] ?? '')) ? strtolower((string) $d['brand']) : '#12355b',
        'accent' => hex_valid((string) ($d['accent'] ?? '')) ? strtolower((string) $d['accent']) : '#0b7577',
        'surface' => hex_valid((string) ($d['surface'] ?? '')) ? strtolower((string) $d['surface']) : '#ffffff',
        'heading_font' => array_key_exists((string) ($d['heading_font'] ?? ''), design_fonts()) ? (string) $d['heading_font'] : 'inter',
        'body_font' => array_key_exists((string) ($d['body_font'] ?? ''), design_fonts()) ? (string) $d['body_font'] : 'inter',
        'radius' => array_key_exists((string) ($d['radius'] ?? ''), design_radius_scales()) ? (string) $d['radius'] : 'rounded',
        'button' => array_key_exists((string) ($d['button'] ?? ''), design_button_styles()) ? (string) $d['button'] : 'pill',
    ];
}

/** Saved customisations merged over the theme defaults. */
function design_effective(?string $theme = null, ?array $custom = null): array
{
    $theme ??= active_theme();
    $custom ??= (array) setting('design', []);
    $out = theme_design_defaults($theme);
    foreach (['brand', 'accent'] as $k) {
        if (isset($custom[$k]) && is_string($custom[$k]) && hex_valid($custom[$k])) {
            $out[$k] = strtolower($custom[$k]);
        }
    }
    foreach (['heading_font' => design_fonts(), 'body_font' => design_fonts(), 'radius' => design_radius_scales(), 'button' => design_button_styles()] as $k => $allowed) {
        if (isset($custom[$k]) && is_string($custom[$k]) && array_key_exists($custom[$k], $allowed)) {
            $out[$k] = $custom[$k];
        }
    }
    return $out;
}

/**
 * Custom properties derived from a design: brand/accent families with
 * readable inks, font stacks, radius scale and button shape.
 * @return array<string,string> property => value
 */
function design_tokens(array $d): array
{
    $brand = $d['brand'];
    $accent = $d['accent'];
    $surface = $d['surface'] ?? '#ffffff';
    $brandStrong = color_mix($brand, '#000000', 0.28);
    $accentSoft = color_mix($accent, '#ffffff', 0.86);
    $darkInk = best_ink($brandStrong);
    [$s, $m, $l, $xl] = design_radius_scales()[$d['radius']]['values'];
    $fonts = design_fonts();
    return [
        '--c-brand' => $brand,
        '--c-brand-strong' => $brandStrong,
        '--c-brand-ink' => best_ink($brand),
        '--c-brand-soft' => color_mix($brand, '#ffffff', 0.9),
        '--c-on-dark-strong' => $darkInk,
        '--c-on-dark' => $darkInk === '#ffffff' ? '#e9eef4' : '#1b1b1b',
        '--c-on-dark-muted' => $darkInk === '#ffffff' ? color_mix('#e9eef4', $brandStrong, 0.22) : color_mix('#1b1b1b', $brandStrong, 0.25),
        '--c-accent' => $accent,
        '--c-accent-strong' => color_mix($accent, '#000000', 0.18),
        '--c-accent-ink' => best_ink($accent),
        '--c-accent-soft' => $accentSoft,
        '--c-accent-text' => ensure_contrast($accent, $accentSoft),
        '--c-link' => ensure_contrast($accent, $surface),
        '--focus-ring' => 'color-mix(in srgb, ' . $accent . ' 30%, transparent)',
        '--font-heading' => $fonts[$d['heading_font']]['stack'],
        '--font-body' => $fonts[$d['body_font']]['stack'],
        '--radius-s' => $s . 'px',
        '--radius-m' => $m . 'px',
        '--radius-l' => $l . 'px',
        '--radius-xl' => $xl . 'px',
        '--button-radius' => match ($d['button']) { 'square' => $s . 'px', 'rounded' => $m . 'px', default => '999px' },
    ];
}

/**
 * Contrast checks shown on the Design screen (WCAG AA: 4.5:1 for text).
 * @return list<array{label:string, fg:string, bg:string, ratio:float, ok:bool}>
 */
function design_contrast_report(array $d): array
{
    $t = design_tokens($d);
    $surface = $d['surface'] ?? '#ffffff';
    $pairs = [
        ['Text on brand-coloured buttons and bands', $t['--c-brand-ink'], $d['brand']],
        ['Text on accent buttons', $t['--c-accent-ink'], $d['accent']],
        ['Accent colour as link text on the page', $d['accent'], $surface],
        ['Footer and top bar text', $t['--c-on-dark'], $t['--c-brand-strong']],
        ['Muted footer text', $t['--c-on-dark-muted'], $t['--c-brand-strong']],
    ];
    $out = [];
    foreach ($pairs as [$label, $fg, $bg]) {
        $ratio = round(contrast_ratio($fg, $bg), 2);
        $out[] = ['label' => $label, 'fg' => $fg, 'bg' => $bg, 'ratio' => $ratio, 'ok' => $ratio >= 4.5];
    }
    return $out;
}

/**
 * Validate the Design form.
 * @return array{0:array,1:array<string,string>} [design values, errors]
 */
function design_validate(array $input): array
{
    $out = [];
    $errors = [];
    foreach (['brand' => 'Brand colour', 'accent' => 'Accent colour'] as $k => $label) {
        $v = strtolower(trim((string) ($input[$k] ?? '')));
        if (!hex_valid($v)) {
            $errors[$k] = "$label must be a colour like #12355b.";
        }
        $out[$k] = $v;
    }
    foreach (['heading_font' => design_fonts(), 'body_font' => design_fonts(), 'radius' => design_radius_scales(), 'button' => design_button_styles()] as $k => $allowed) {
        $v = (string) ($input[$k] ?? '');
        if (!array_key_exists($v, $allowed)) {
            $errors[$k] = 'Choose one of the options.';
        }
        $out[$k] = $v;
    }
    return [$out, $errors];
}

function design_tokens_css(array $tokens): string
{
    $css = ":root{\n";
    foreach ($tokens as $prop => $value) {
        if (!preg_match('/^--[a-z0-9-]+$/', $prop) || preg_match('/[{};<>]/', $value)) {
            continue;
        }
        $css .= '  ' . $prop . ': ' . $value . ";\n";
    }
    return $css . "}\n";
}

/**
 * Latin woff2 files of the active heading and body fonts, preloaded in the
 * page head so text renders in its final face without a late swap.
 * @return list<string> URLs
 */
function design_preload_fonts(): array
{
    [$theme, $preview] = request_theme();
    $d = $preview ? theme_design_defaults($theme) : design_effective($theme);
    $fonts = design_fonts();
    $out = [];
    foreach ([$d['heading_font'], $d['body_font']] as $key) {
        $files = $fonts[$key]['files'] ?? null;
        if ($files !== null) {
            $out[] = '/assets/fonts/' . $files . '-latin-wght-normal.woff2';
        }
    }
    return array_values(array_unique($out));
}
