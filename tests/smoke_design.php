<?php
declare(strict_types=1);

// Addendum A6 smoke checks (design system): new sections on the demo pages,
// hero preload and overlay header, icon sprite and fonts, focal points,
// builder validation of the new sections, footer call to action, strict CSP.
// Included by tests/smoke.php after smoke_addendum.php.

// ---- Public pages ------------------------------------------------------------------
$homeId = (int) setting('home.page_id');
$homePage = q_one('SELECT * FROM pages WHERE id = ?', [$homeId]);
builder_publish($homeId, (int) $homePage['revision'], null); // publish whatever the builder checks left as a draft
$r = (new Browser($base))->get('/');
$h = $r['body'];
check('A6: home opens with a full-height photo hero and a transparent header over it', str_contains($h, 'hero-full') && str_contains($h, 'data-hero-stage') && str_contains($h, 'site-header sticky is-overlay'));
check('A6: the first hero photo and the two fonts are preloaded', (bool) preg_match('/<link rel="preload" as="image" imagesrcset="[^"]+400w/', $h) && substr_count($h, 'as="font" type="font/woff2" crossorigin') === 2);
foreach (['blk-stats', 'blk-logos', 'blk-split-metrics', 'blk-features', 'blk-about-band', 'blk-upcoming-events', 'blk-process', 'blk-showcase', 'blk-testimonials', 'blk-latest-posts', 'blk-gallery', 'blk-cta'] as $cls) {
    check("A6: demo home shows $cls", str_contains($h, $cls));
}
check('A6: hero slider controls, avatar stack and floating card are rendered', str_contains($h, 'class="hero-dots"') && str_contains($h, 'avatar-stack') && str_contains($h, 'class="hero-float"'));
check('A6: stats band uses icon tiles; counters count up from their text', str_contains($h, 'stats is-band') && str_contains($h, 'class="icon-tile"') && str_contains($h, 'data-count'));
check('A6: logo marquee duplicates its track for screen readers only once', substr_count($h, 'class="marquee-track"') === 2 && str_contains($h, 'aria-hidden="true" inert'));
check('A6: reveal cards, numbered showcase and testimonial slider markup', str_contains($h, 'reveal-card') && str_contains($h, 'class="sc-num"') && str_contains($h, 'data-carousel'));
check('A6: rich footer (columns, bottom bar with back-to-top)', str_contains($h, 'class="footer-grid"') && str_contains($h, 'data-to-top'));
check('A6: no inline style attributes or inline scripts in the page', !preg_match('/\sstyle="/', $h) && !preg_match('#<script(?![^>]*\bsrc=)(?![^>]*application/json)[^>]*>#', $h));
check('A6: strict CSP kept (no unsafe-inline)', str_contains($r['headers'], 'Content-Security-Policy') && !str_contains($r['headers'], 'unsafe-inline'));
check('A6: photos carry focal point classes', (bool) preg_match('/class="[^"]*fx-\d+ fy-\d+/', $h));
$mem = (new Browser($base))->get('/membership')['body'];
check('A6: membership page has tiers with a featured dark card and an FAQ', str_contains($mem, 'blk-tiers') && str_contains($mem, 'tier is-featured') && str_contains($mem, 'class="faq-body"'));
status_is($visitor, '/events', 200, 'event-card');
status_is($visitor, '/gallery', 200, 'album-tile');
status_is($visitor, '/leaders', 200, 'person-card');
status_is($visitor, '/login', 200, 'auth-wrap');

// ---- Assets ---------------------------------------------------------------------------
$sprite = $visitor->get('/assets/img/icons.svg')['body'];
check('A6: Lucide sprite has the old names and the new icons', str_contains($sprite, 'Lucide v1.61.0') && str_contains($sprite, 'id="pin"') && str_contains($sprite, 'id="hand-heart"') && str_contains($sprite, 'id="whatsapp"'));
status_is($visitor, '/assets/img/LICENSE-lucide.txt', 200, 'ISC License');
foreach (['bricolage-grotesque', 'instrument-sans', 'newsreader'] as $font) {
    status_is($visitor, "/assets/fonts/$font-latin-wght-normal.woff2", 200);
}
$bold = $visitor->get('/theme.css?t=bold&plain=1')['body'];
check('A6: theme CSS has the shared scale and the Bold font pairing', str_contains($bold, '--step-6') && str_contains($bold, '"Bricolage Grotesque Variable"') && str_contains($bold, '--shadow-4'));
check('A6: the Design screen offers the Bold theme', str_contains($admin->get('/admin/design')['body'], 'Bold'));

// ---- Demo photos and focal points ------------------------------------------------------
$photo = q_one("SELECT * FROM media WHERE folder = 'demo' AND original_name = 'elder-portrait-3.jpg'");
check('A6: demo photos went through the media pipeline (WebP variants, focal point)', $photo !== null && (int) $photo['focal_x'] === 45
    && array_keys(json_decode((string) $photo['variants'], true)) === [400, 800, 1600] && (int) $photo['width'] === 2000);
$pid = (int) $photo['id'];
status_is($admin, "/admin/media/$pid", 200, 'Focal point');
$r = $admin->submit("/admin/media/$pid", "/admin/media/$pid", ['title' => 'Elder portrait', 'alt' => 'Portrait', 'folder' => 'demo', 'focal_x' => '101', 'focal_y' => '20']);
check('A6: an out-of-range focal point is refused (422)', $r['status'] === 422 && str_contains($r['body'], 'from 0 to 100'));
$r = $admin->submit("/admin/media/$pid", "/admin/media/$pid", ['title' => 'Elder portrait', 'alt' => 'Portrait', 'folder' => 'demo', 'focal_x' => '30', 'focal_y' => '20']);
check('A6: the focal point is saved and used by img tags', $r['status'] === 303 && (int) q_val('SELECT focal_x FROM media WHERE id = ?', [$pid]) === 30
    && str_contains($visitor->get('/leaders')['body'], 'fx-30 fy-20'));

// ---- Builder: new sections validated on the server --------------------------------------
$r = $admin->get("/admin/pages/$homeId/builder");
$token = preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $r['body'], $m) ? $m[1] : '';
$cfg = preg_match('#<script type="application/json" id="builder-config">(.*?)</script>#s', $r['body'], $m) ? json_decode($m[1], true) : [];
check('A6: picker shows thumbnails for the new sections', str_contains($r['body'], '#sec-tiers') && str_contains($r['body'], '#sec-showcase') && isset($cfg['types']['testimonials']['schema']));
$json = ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $token];
$tiers = builder_new_section('tiers');
$tiers['settings']['items'][0]['button_url'] = 'javascript:alert(1)';
$tiers['settings']['items'][1]['price'] = '';
$hero = builder_new_section('hero');
$hero['settings']['video_media_id'] = $pid; // an image, not a video
$logos = builder_new_section('logos');
$logos['settings']['speed'] = 'warp';
$r = $admin->post("/admin/pages/$homeId/builder", json_encode(['revision' => (int) q_val('SELECT revision FROM pages WHERE id = ?', [$homeId]), 'sections' => [$hero, $tiers, $logos]]), $json);
$res = json_decode($r['body'], true) ?? [];
$errs = array_merge(...array_values(array_map(static fn ($e): array => (array) $e, (array) ($res['errors'] ?? []))));
check('A6: unsafe tier link, empty price, wrong media kind and unknown option are all reported', $r['status'] === 200
    && isset($errs['items.0.button_url'], $errs['items.1.price'], $errs['video_media_id'], $errs['speed']), $r['body']);
$draft = builder_draft_sections(q_one('SELECT * FROM pages WHERE id = ?', [$homeId]));
check('A6: the cleaned draft keeps no unsafe values', ($draft[1]['settings']['items'][0]['button_url'] ?? 'x') === '' && $draft[0]['settings']['video_media_id'] === null && $draft[2]['settings']['speed'] === 'normal');

// ---- Header and footer: footer call to action -------------------------------------------
$r = $admin->submit('/admin/layout', '/admin/layout', ['topbar' => '1', 'show_auth' => '1', 'sticky' => '1', 'listen_live' => '1', 'footer_cta' => '1', 'footer_word' => '1', 'footer_cta_heading' => str_repeat('x', 121), 'footer_cta_text' => '']);
check('A6: an over-long footer heading is refused (422)', $r['status'] === 422);
$r = $admin->submit('/admin/layout', '/admin/layout', ['topbar' => '1', 'show_auth' => '1', 'sticky' => '1', 'listen_live' => '1', 'footer_cta' => '1', 'footer_cta_heading' => 'Come and say hello', 'footer_cta_text' => 'Every Saturday.']);
check('A6: the footer call to action is editable and the wordmark can be switched off', $r['status'] === 303 && str_contains(($f = $visitor->get('/events')['body']), 'class="footer-cta"') && str_contains($f, 'Come and say hello') && !str_contains($f, 'class="footer-word"'));
$admin->submit('/admin/layout', '/admin/layout', ['topbar' => '1', 'show_auth' => '1', 'sticky' => '1', 'listen_live' => '1', 'footer_cta' => '1', 'footer_word' => '1', 'footer_cta_heading' => 'Come and say hello', 'footer_cta_text' => '']);
check('A6: the outlined wordmark returns when switched on', str_contains($visitor->get('/events')['body'], 'class="footer-word"'));
