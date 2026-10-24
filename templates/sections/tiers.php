<?php /** @var array $s @var string $h */ $sub = $h === 'h1' ? 'h2' : 'h3'; ?>
<div class="container">
  <?= view('sections/_head', ['s' => $s, 'h' => $h]) ?>
  <ul class="tiers" data-reveal-group>
    <?php foreach ($s['items'] as $t): $ic = (string) ($t['icon'] ?? ''); $url = safe_url((string) ($t['button_url'] ?? '')); $featured = !empty($t['featured']); ?>
      <li class="tier<?= $featured ? ' is-featured' : '' ?>" data-reveal>
        <div class="tier-top">
          <?php if ($ic !== '' && array_key_exists($ic, icon_options())): ?><span class="icon-tile soft" aria-hidden="true"><?= icon($ic) ?></span><?php else: ?><span></span><?php endif ?>
          <?php if (trim((string) ($t['badge'] ?? '')) !== ''): ?><span class="badge featured"><?= icon('zap') ?><?= e($t['badge']) ?></span><?php endif ?>
        </div>
        <<?= $sub ?>><?= e($t['name']) ?></<?= $sub ?>>
        <p class="tier-price"><?php if (trim((string) ($t['from_label'] ?? '')) !== ''): ?><span class="tier-from"><?= e($t['from_label']) ?></span><?php endif ?><strong><?= e($t['price']) ?></strong><?php if (trim((string) ($t['period'] ?? '')) !== ''): ?><span class="per"><?= e($t['period']) ?></span><?php endif ?></p>
        <?php if (trim((string) ($t['description'] ?? '')) !== ''): ?><p class="tier-desc"><?= e($t['description']) ?></p><?php endif ?>
        <?php $lines = \Sections\Tiers::lines((string) ($t['features'] ?? '')); if ($lines): ?>
          <ul class="tier-list"><?php foreach ($lines as $l): ?><li><?= icon('check') ?><span><?= e($l) ?></span></li><?php endforeach ?></ul>
        <?php endif ?>
        <?php if (trim((string) ($t['button_label'] ?? '')) !== '' && $url !== ''): ?><a class="btn block <?= $featured ? 'highlight' : '' ?>" href="<?= e($url) ?>"><?= e($t['button_label']) ?> <?= icon('arrow-right') ?></a><?php endif ?>
      </li>
    <?php endforeach ?>
  </ul>
</div>
