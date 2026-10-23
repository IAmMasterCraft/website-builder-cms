<?php /** @var array $s @var string $h */
$side = $s['sidebar'] !== 'none' && (trim(strip_tags((string) $s['sidebar_body'])) !== '' || $s['sidebar_title'] !== ''); ?>
<div class="container<?= $side ? '' : ' narrow' ?>">
  <div class="<?= $side ? 'layout-with-aside' . ($s['sidebar'] === 'left' ? ' aside-left' : '') : '' ?>">
    <div>
      <?php if ($s['eyebrow'] !== ''): ?><span class="eyebrow"><?= e($s['eyebrow']) ?></span><?php endif ?>
      <?php if ($s['heading'] !== ''): ?><<?= $h ?>><?= e($s['heading']) ?></<?= $h ?>><?php endif ?>
      <div class="prose"><?= rich($s['body']) ?></div>
    </div>
    <?php if ($side): ?>
      <aside class="aside-box">
        <?php if ($s['sidebar_title'] !== ''): ?><h3><?= e($s['sidebar_title']) ?></h3><?php endif ?>
        <div class="prose"><?= rich($s['sidebar_body']) ?></div>
      </aside>
    <?php endif ?>
  </div>
</div>
