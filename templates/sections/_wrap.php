<?php
/** @var array $section @var array $settings @var array $style @var string $inner @var bool $preview */
$type = (string) $section['type'];
$bg = $style['background'] === 'image' ? media_find((int) ($style['bg_media_id'] ?? 0) ?: null) : null;
$background = $style['background'] === 'image' && $bg === null ? 'none' : $style['background'];
$classes = ['blk', 'blk-' . str_replace('_', '-', $type), 'bg-' . $background, 'sp-' . $style['spacing'], 'w-' . $style['width']];
$settings ??= [];
if ($type === 'hero' && ($settings['layout'] ?? '') === 'fullscreen') {
    $classes[] = 'hero-full';
    $classes[] = 'h-' . (in_array($settings['height'] ?? '', ['full', 'tall', 'auto'], true) ? $settings['height'] : 'full');
    $classes[] = 'ov-' . (in_array($settings['overlay'] ?? '', ['light', 'medium', 'strong'], true) ? $settings['overlay'] : 'medium');
} elseif ($type === 'hero' && $background === 'brand') {
    $classes[] = 'hero';
}
if ($preview && !empty($section['hidden'])) {
    $classes[] = 'is-hidden-preview';
}
$label = (builder_section_class($type))::LABEL . (!empty($section['hidden']) ? ' (hidden)' : '');
?>
<section class="<?= e(implode(' ', $classes)) ?>"<?= $style['anchor'] !== '' ? ' id="' . e($style['anchor']) . '"' : '' ?><?= $preview ? ' data-section-id="' . e($section['id']) . '" data-section-label="' . e($label) . '"' : '' ?>>
<?php if ($preview): ?><span class="sec-label" aria-hidden="true"><?= e($label) ?></span><?php endif ?>
<?php if ($bg): ?><div class="blk-bg" aria-hidden="true"><?= img_html($bg, '100vw', '', '', true, 1600) ?></div><?php endif ?>
<?= $inner ?>
</section>
