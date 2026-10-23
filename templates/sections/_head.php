<?php
/** Section heading block. @var array $s @var string $h @var ?string $fallbackUrl */
$heading = trim((string) ($s['heading'] ?? ''));
$linkUrl = safe_url((string) ($s['link_url'] ?? '')) ?: (string) ($fallbackUrl ?? '');
$linkLabel = trim((string) ($s['link_label'] ?? ''));
$eyebrow = trim((string) ($s['eyebrow'] ?? ''));
$intro = trim((string) ($s['intro'] ?? ''));
if ($heading === '' && $eyebrow === '' && $intro === '' && ($linkLabel === '' || $linkUrl === '')) {
    return;
}
?>
<div class="blk-head">
  <div>
    <?php if ($eyebrow !== ''): ?><span class="eyebrow"><?= e($eyebrow) ?></span><?php endif ?>
    <?php if ($heading !== ''): ?><<?= $h ?>><?= e($heading) ?></<?= $h ?>><?php endif ?>
    <?php if ($intro !== ''): ?><p><?= e($intro) ?></p><?php endif ?>
  </div>
  <?php if ($linkLabel !== '' && $linkUrl !== ''): ?><a class="link-arrow" href="<?= e($linkUrl) ?>"><?= e($linkLabel) ?> <?= icon('arrow-right') ?></a><?php endif ?>
</div>
