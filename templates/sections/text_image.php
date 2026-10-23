<?php /** @var array $s @var array $d @var string $h */ ?>
<div class="container split<?= $s['image_position'] === 'left' ? ' media-left' : '' ?><?= $d['media'] ? '' : ' no-media' ?>">
  <div class="split-copy">
    <?php if ($s['eyebrow'] !== ''): ?><span class="eyebrow"><?= e($s['eyebrow']) ?></span><?php endif ?>
    <?php if ($s['heading'] !== ''): ?><<?= $h ?>><?= e($s['heading']) ?></<?= $h ?>><?php endif ?>
    <div class="prose"><?= rich($s['body']) ?></div>
    <?php if ($s['button_label'] !== '' && safe_url($s['button_url']) !== ''): ?><p class="split-action"><a class="btn" href="<?= e(safe_url($s['button_url'])) ?>"><?= e($s['button_label']) ?> <?= icon('arrow-right') ?></a></p><?php endif ?>
  </div>
  <?php if ($d['media']): ?><figure class="split-media"><?= img_html($d['media'], '(min-width: 56rem) 34rem, 100vw', null, '', true, 1600) ?></figure><?php endif ?>
</div>
