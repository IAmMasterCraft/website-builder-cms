<?php
declare(strict_types=1);

/*
 * Analytics (§7 "analytics snippet allow-list", §11 "cookie banner only if
 * analytics is enabled"). Admins cannot paste arbitrary script: they pick
 * one provider from this allow-list and give its site ID. Our own module
 * (analytics.js) loads the provider's script from a fixed host, and the
 * CSP is widened for exactly that provider's hosts on public pages.
 * Providers that set cookies wait for consent in the cookie banner;
 * cookieless ones load directly and no banner is shown.
 */

/** @return array<string,array{label:string,script:string,hosts:list<string>,cookies:bool,id_label:string,id_pattern:string,id_hint:string}> */
function analytics_providers(): array
{
    return [
        'plausible' => [
            'label' => 'Plausible (no cookies)', 'script' => 'https://plausible.io/js/script.js',
            'hosts' => ['https://plausible.io'], 'cookies' => false,
            'id_label' => 'Site domain as set up in Plausible', 'id_pattern' => '/^[a-z0-9.-]{3,100}$/', 'id_hint' => 'For example example.org',
        ],
        'fathom' => [
            'label' => 'Fathom (no cookies)', 'script' => 'https://cdn.usefathom.com/script.js',
            'hosts' => ['https://cdn.usefathom.com'], 'cookies' => false,
            'id_label' => 'Fathom site ID', 'id_pattern' => '/^[A-Z0-9]{4,12}$/', 'id_hint' => 'Eight capital letters, for example ABCDEFGH',
        ],
        'ga4' => [
            'label' => 'Google Analytics 4 (sets cookies: asks for consent)', 'script' => 'https://www.googletagmanager.com/gtag/js',
            'hosts' => ['https://www.googletagmanager.com', 'https://*.google-analytics.com', 'https://*.analytics.google.com'], 'cookies' => true,
            'id_label' => 'Measurement ID', 'id_pattern' => '/^G-[A-Z0-9]{4,12}$/', 'id_hint' => 'Starts with G-, for example G-AB12CD34EF',
        ],
    ];
}

function analytics_default_banner(): string
{
    return 'We would like to use analytics cookies to understand how visitors use this website. They are only set if you accept.';
}

/** Active analytics config, or null when off or misconfigured. @return array{provider:string,id:string,script:string,hosts:list<string>,cookies:bool,banner:string}|null */
function analytics_config(): ?array
{
    $s = (array) setting('analytics', []);
    $key = (string) ($s['provider'] ?? '');
    $p = analytics_providers()[$key] ?? null;
    $id = (string) ($s['site_id'] ?? '');
    if ($p === null || !preg_match($p['id_pattern'], $id)) {
        return null;
    }
    $banner = trim((string) ($s['banner'] ?? ''));
    return ['provider' => $key, 'id' => $id, 'script' => $p['script'], 'hosts' => $p['hosts'], 'cookies' => $p['cookies'], 'banner' => $banner !== '' ? $banner : analytics_default_banner()];
}

/** The Content-Security-Policy for a response; public pages allow the chosen analytics hosts. */
function csp_header_value(?array $analytics = null): string
{
    $script = "'self'";
    $connect = "'self'";
    $img = "'self' data: blob:";
    if ($analytics !== null) {
        $hosts = implode(' ', $analytics['hosts']);
        $script .= ' ' . $hosts;
        $connect .= ' ' . $hosts;
        $img .= ' ' . $hosts;
    }
    return "default-src 'self'; img-src $img; style-src 'self'; script-src $script; connect-src $connect; font-src 'self'; media-src 'self' blob:; "
        . "frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com https://www.google.com https://www.facebook.com; "
        . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";
}
