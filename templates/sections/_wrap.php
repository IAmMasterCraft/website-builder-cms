<?php
/** @var array $section @var array $style @var string $inner @var bool $preview */
$type = (string) $section['type'];
$bg = $style['background'] === 'image' ? media_find((int) ($style['bg_media_id'] ?? 0) ?: null) : null;
$background = $style['background'] === 'image' && $bg === null ? 'none' : $style['background'];
$classes = ['blk', 'blk-' . str_replace('_', '-', $type), 'bg-' . $background, 'sp-' . $style['spacing'], 'w-' . $style['width']];
if ($type === 'hero' && $background === 'brand') {
    $classes[] = 'hero';
}
if ($preview && !empty($section['hidden'])) {
    $classes[] = 'is-hidden-preview';
}
?>
<section class="<?= e(implode(' ', $classes)) ?>"<?= $style['anchor'] !== '' ? ' id="' . e($style['anchor']) . '"' : '' ?><?= $preview ? ' data-section-id="' . e($section['id']) . '" data-section-label="' . e((builder_section_class($type))::LABEL) . (!empty($section['hidden']) ? ' (hidden)' : '') . '"' : '' ?>>
<?php if ($bg): ?><div class="blk-bg" aria-hidden="true"><?= img_html($bg, '100vw', '', '', true, 1600) ?></div><?php endif ?>
<?= $inner ?>
</section>
