<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** JSON for embedding in HTML (data attributes or script blocks). */
function json_attr(mixed $value): string
{
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
}

/** Render a template file to a string. $template is always a literal from code. */
function view(string $template, array $data = []): string
{
    if (!preg_match('#^[a-z0-9_/-]+$#', $template)) {
        throw new InvalidArgumentException('Bad template name');
    }
    $__file = BASE . '/templates/' . $template . '.php';
    if (!is_file($__file)) {
        throw new RuntimeException('Missing template ' . $template);
    }
    extract($data, EXTR_SKIP);
    ob_start();
    try {
        require $__file;
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
    return (string) ob_get_clean();
}

/**
 * Render a template inside a layout and send it.
 * Layouts: site, admin, auth, install, bare.
 */
function render(string $template, array $data = [], string $layout = 'site', int $status = 200): void
{
    $content = view($template, $data);
    $layoutData = $data + ['title' => '', 'content' => $content];
    $layoutData['content'] = $content;
    if ($layout === 'site') {
        $layoutData['chrome'] = site_chrome();
    } elseif ($layout === 'admin') {
        $layoutData['chrome'] = admin_chrome();
    }
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo view('layouts/' . $layout, $layoutData);
}

/**
 * Per-request page flags set while sections render (the layout is rendered
 * afterwards): e.g. 'overlay_header' when a full-height hero opens the page.
 */
function page_flag(string $key, mixed $value = null): mixed
{
    static $flags = [];
    if (func_num_args() > 1) {
        $flags[$key] = $value;
    }
    return $flags[$key] ?? null;
}

/** <link rel="preload"> tags collected for the layout's <head> (hero image, fonts). */
function head_preloads(?string $tag = null): array
{
    static $tags = [];
    if ($tag !== null && !in_array($tag, $tags, true)) {
        $tags[] = $tag;
    }
    return $tags;
}

/** Preload the responsive image that will be the largest paint (LCP). */
function preload_image(?array $m, string $sizes): void
{
    if ($m === null || $m['kind'] !== 'image' || $m['visibility'] !== 'public' || $m['variants'] === []) {
        return;
    }
    $srcset = [];
    foreach (array_keys($m['variants']) as $w) {
        $srcset[] = media_variant_url($m, $w) . ' ' . $w . 'w';
    }
    head_preloads('<link rel="preload" as="image" imagesrcset="' . e(implode(', ', $srcset)) . '" imagesizes="' . e($sizes) . '" fetchpriority="high">');
}

/** Cache-busted URL for a file under public/. */
function asset(string $path): string
{
    $file = BASE . '/public/' . ltrim($path, '/');
    $v = is_file($file) ? (string) filemtime($file) : '0';
    return '/' . ltrim($path, '/') . '?v=' . $v;
}

/** Inline reference to an icon in the SVG sprite. */
function icon(string $name, string $class = 'icon'): string
{
    return '<svg class="' . e($class) . '" aria-hidden="true" focusable="false"><use href="' . e(asset('assets/img/icons.svg')) . '#' . e($name) . '"></use></svg>';
}

/** Build a query string link that keeps current filters, replacing some. */
function url_with(string $path, array $current, array $changes): string
{
    $params = array_filter(array_merge($current, $changes), static fn ($v) => $v !== null && $v !== '');
    if (isset($params['page']) && (int) $params['page'] <= 1) {
        unset($params['page']);
    }
    return $path . ($params ? '?' . http_build_query($params) : '');
}

/** @return array{page:int, per:int, offset:int} */
function paging(int $per = 12): array
{
    $page = max(1, min(10000, input_int('page', 1, true)));
    return ['page' => $page, 'per' => $per, 'offset' => ($page - 1) * $per];
}

function pluralise(int $n, string $one, ?string $many = null): string
{
    return $n . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'));
}

/** Messages shown in the UI live here so they can be translated later (§13). */
function t(string $key, array $vars = []): string
{
    static $strings = null;
    $strings ??= require BASE . '/src/strings.php';
    $s = $strings[$key] ?? $key;
    foreach ($vars as $k => $v) {
        $s = str_replace('{' . $k . '}', (string) $v, $s);
    }
    return $s;
}

/** Stored rich text for output: sanitised again (it was cleaned on save too). */
function rich(?string $html): string
{
    return \Core\Sanitizer::clean($html);
}
