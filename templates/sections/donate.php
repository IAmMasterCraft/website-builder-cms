<?php /** @var array $s @var array $d @var string $h */ ?>
<div class="container">
  <div class="donate">
    <div>
      <<?= $h ?>><?= e($s['heading']) ?></<?= $h ?>>
      <?php if ($s['text'] !== ''): ?><p class="lede"><?= e($s['text']) ?></p><?php endif ?>
      <?php if ($d['links']): ?>
        <div class="hero-actions">
          <?php foreach ($d['links'] as $i => $l): ?><a class="btn <?= $i === 0 ? 'accent' : 'ghost' ?>" href="<?= e($l['url']) ?>" target="_blank" rel="noopener"><?= icon('heart') ?> <?= e($l['label']) ?><span class="sr-only"> (opens in a new tab)</span></a><?php endforeach ?>
        </div>
      <?php endif ?>
    </div>
    <?php if (trim($d['bank_text']) !== ''): ?>
      <div class="aside-box bank-box"><h3 class="h3">Bank transfer</h3><p class="bank-text"><?= nl2br(e($d['bank_text']), false) ?></p></div>
    <?php endif ?>
  </div>
</div>
