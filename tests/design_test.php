<?php
declare(strict_types=1);

// Addendum A6 design system: focal points, the eight new sections and the
// upgraded ones, hero preload and overlay header flag, video media fields,
// fonts and theme tokens.

test('A6 focal point: rounded to 5 % classes on every img; centre adds nothing', function (): void {
    $id = fixture_image_media();
    q('UPDATE media SET focal_x = 33, focal_y = 81 WHERE id = ?', [$id]);
    media_forget($id);
    $m = media_find($id);
    same('fx-35 fy-80', media_focal_class($m));
    contains('class="card-img fx-35 fy-80"', img_html($m, '100vw', '', 'card-img'));
    same('', media_focal_class(['focal_x' => 50, 'focal_y' => 52] + $m));
    same('fx-0 fy-100', media_focal_class(['focal_x' => -4, 'focal_y' => 140] + $m), 'clamped');
    $stored = media_store(fixture_jpeg(900, 600), 'f.jpg', 1, null, false, ['focal_x' => 120, 'focal_y' => 10]);
    same([100, 10], array_map('intval', array_values(q_one('SELECT focal_x, focal_y FROM media WHERE id = ?', [$stored]))), 'media_store clamps the focal point');
});

test('A6 media references: every *_media_id key in builder JSON is tracked', function (): void {
    same([5, 7, 9], media_ids_in_json(json_encode([['settings' => ['video_media_id' => 5, 'items' => [['media_id' => '7']]], 'style' => ['bg_media_id' => 9]]])));
});

test('A6 sections: defaults validate and render with their own markup', function (): void {
    act_as(null);
    $expect = [
        'split_metrics' => 'class="metrics"', 'about_band' => 'class="ab-head"', 'process' => 'data-steps', 'points' => 'points style-dots',
        'showcase' => 'class="sc-num"', 'testimonials' => 'data-carousel', 'tiers' => 'tier is-featured', 'logos' => 'class="marquee-track"',
    ];
    foreach ($expect as $type => $needle) {
        $s = builder_new_section($type);
        [, $errors] = builder_validate_settings($type, $s['settings']);
        same([], $errors, "$type defaults validate");
        $html = builder_render([$s], ['viewer' => null, 'page' => null]);
        contains($needle, $html, "$type renders");
        ok(!preg_match('/\sstyle="/', $html), "$type has no inline styles");
    }
    same('dark', builder_new_section('about_band')['style']['background'], 'story band defaults to dark');
    same('dark', builder_new_section('testimonials')['style']['background']);
});

test('A6 sections: server-side validation of the new fields', function (): void {
    [$s, $e] = builder_validate_settings('tiers', ['items' => array_fill(0, 5, ['name' => 'X', 'price' => '£1', 'features' => "a\nb", 'button_url' => 'javascript:x', 'icon' => 'nope'])]);
    ok(isset($e['items']), 'at most 4 tiers');
    ok(isset($e['items.0.button_url']), 'unsafe link');
    ok(isset($e['items.0.icon']), 'unknown icon');
    same(4, count($s['items']));
    same(['One', 'Two'], Sections\Tiers::lines("  One \n\n Two\r\n"));
    same(8, count(Sections\Tiers::lines(implode("\n", range(1, 12)))), 'benefits capped at 8 lines');
    [, $e] = builder_validate_settings('process', ['steps' => [['title' => 'Only one']]]);
    ok(isset($e['steps']), 'process needs two steps');
    [, $e] = builder_validate_settings('testimonials', ['items' => [['quote' => '', 'name' => 'A', 'rating' => '9']]]);
    ok(isset($e['items.0.quote']) && isset($e['items.0.rating']));
    [, $e] = builder_validate_settings('stats', ['layout' => 'radial', 'items' => [['metric' => 'custom', 'value' => '5', 'label' => 'x', 'icon' => 'users']]]);
    same(['layout'], array_keys($e));
    [, $e] = builder_validate_settings('features', ['style' => 'reveal', 'cards' => [['icon' => 'heart', 'title' => 'T', 'media_id' => 999999]]]);
    ok(isset($e['cards.0.media_id']), 'missing photo reported');
    [, $e] = builder_validate_settings('cta', ['heading' => 'Hi', 'tone' => 'image', 'media_id' => null]);
    same([], $e, 'image tone without a photo is allowed (falls back to dark)');
    contains('tone-dark', Sections\Cta::render(array_merge(Sections\Cta::defaults(), ['tone' => 'image']), ['heading_tag' => 'h2']));
});

test('A6 hero: video field accepts public videos only; fullscreen hero flags the overlay header and preloads its photo', function (): void {
    $img = fixture_image_media();
    [, $e] = builder_validate_settings('hero', ['heading' => 'Hi', 'video_media_id' => $img]);
    contains('public video', $e['video_media_id'] ?? '');
    $vid = db_insert('media', ['kind' => 'video', 'path' => 'x.mp4', 'original_name' => 'x.mp4', 'mime' => 'video/mp4', 'size' => 1, 'visibility' => 'public']);
    [$s, $e] = builder_validate_settings('hero', ['heading' => 'Hi', 'video_media_id' => (string) $vid, 'slides' => [['media_id' => $img, 'caption' => '']]]);
    same([], $e);
    same($vid, $s['video_media_id']);
    $html = builder_render([['id' => 'sabcdef1', 'type' => 'hero', 'hidden' => false, 'settings' => $s, 'style' => Sections\Hero::defaultStyle()]], ['viewer' => null, 'page' => null]);
    contains('<video class="hero-video" muted loop playsinline preload="none"', $html);
    contains('hero-full h-full ov-medium', $html);
    same(true, page_flag('overlay_header'));
    page_flag('overlay_header', false);
    $s['video_media_id'] = null;
    builder_render([['id' => 'sabcdef2', 'type' => 'hero', 'hidden' => false, 'settings' => $s, 'style' => Sections\Hero::defaultStyle()]], ['viewer' => null, 'page' => null]);
    ok((bool) preg_grep('/rel="preload" as="image" imagesrcset=/', head_preloads()), 'first slide preloaded');
    page_flag('overlay_header', false);
    [$split] = builder_validate_settings('hero', ['heading' => 'Hi', 'layout' => 'split']);
    not_contains('hero-full', builder_render([['id' => 'sabcdef3', 'type' => 'hero', 'hidden' => false, 'settings' => $split, 'style' => Sections\Hero::defaultStyle()]], ['viewer' => null, 'page' => null]));
    ok(page_flag('overlay_header') !== true, 'split hero keeps the solid header');
});

test('A6 themes and fonts: five themes on the shared scale, preloads for the active pair', function (): void {
    same(['civic', 'bold', 'harvest', 'heritage', 'monochrome'], array_keys(theme_list()));
    setting_set('site.theme', 'bold');
    setting_set('design', []);
    $css = theme_css();
    contains('--step-6', $css);
    contains('font-weight:200 800', $css, 'per-family weight range');
    contains('font-style:italic', $css, 'Newsreader italic face');
    same(['/assets/fonts/bricolage-grotesque-latin-wght-normal.woff2', '/assets/fonts/instrument-sans-latin-wght-normal.woff2'], design_preload_fonts());
    foreach (design_preload_fonts() as $f) {
        ok(is_file(BASE . '/public' . $f), "$f exists");
    }
    setting_set('site.theme', 'civic');
    ok(str_contains((string) file_get_contents(BASE . '/public/assets/img/icons.svg'), 'id="hand-heart"'), 'sprite has the Lucide icons used by sections');
    foreach (array_keys(icon_options()) as $icon) {
        ok(str_contains((string) file_get_contents(BASE . '/public/assets/img/icons.svg'), 'id="' . $icon . '"'), "icon $icon is in the sprite");
    }
});
