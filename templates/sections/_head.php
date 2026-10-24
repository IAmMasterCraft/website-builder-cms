<?php
/**
 * Section heading block. @var array $s @var string $h @var ?string $fallbackUrl
 * Layout from $layout or the section's head_layout: stacked, split (heading
 * left, intro and link right) or center.
 */
$heading = trim((string) ($s['heading'] ?? ''));
$linkUrl = safe_url((string) ($s['link_url'] ?? '')) ?: (string) ($fallbackUrl ?? '');
$linkLabel = trim((string) ($s['link_label'] ?? ''));
$eyebrow = trim((string) ($s['eyebrow'] ?? ''));
$intro = trim((string) ($s['intro'] ?? ''));
$mode = (string) ($layout ?? ($s['head_layout'] ?? 'stacked'));
if ($heading === '' && $eyebrow === '' && $intro === '' && ($linkLabel === '' || $linkUrl === '')) {
    return;
}
$link = $linkLabel !== '' && $linkUrl !== '' ? '<a class="link-arrow" href="' . e($linkUrl) . '">' . e($linkLabel) . ' ' . icon('arrow-right') . '</a>' : '';
?>
<?php if ($mode === 'split'): ?>
<div class="blk-head is-split" data-reveal>
  <div>
    <?php if ($eyebrow !== ''): ?><span class="eyebrow"><?= e($eyebrow) ?></span><?php endif ?>
    <?php if ($heading !== ''): ?><<?= $h ?>><?= e($heading) ?></<?= $h ?>><?php endif ?>
  </div>
  <?php if ($intro !== '' || $link !== ''): ?><div class="head-aside"><?php if ($intro !== ''): ?><p><?= e($intro) ?></p><?php endif ?><?= $link ?></div><?php endif ?>
</div>
<?php else: ?>
<div class="blk-head<?= $mode === 'center' ? ' is-center' : '' ?>" data-reveal>
  <div>
    <?php if ($eyebrow !== ''): ?><span class="eyebrow"><?= e($eyebrow) ?></span><?php endif ?>
    <?php if ($heading !== ''): ?><<?= $h ?>><?= e($heading) ?></<?= $h ?>><?php endif ?>
    <?php if ($intro !== ''): ?><p><?= e($intro) ?></p><?php endif ?>
  </div>
  <?= $link ?>
</div>
<?php endif ?>
