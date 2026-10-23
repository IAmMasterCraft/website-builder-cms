<?php /** @var array $s @var string $h */ $sub = $h === 'h1' ? 'h2' : 'h3'; ?>
<div class="container">
  <?= view('sections/_head', ['s' => $s, 'h' => $h]) ?>
  <ul class="features cols-<?= e($s['columns']) ?>">
    <?php foreach ($s['cards'] as $card): $url = safe_url($card['link_url'] ?? ''); ?>
      <li class="feature">
        <span class="feature-icon" aria-hidden="true"><?= icon(array_key_exists($card['icon'] ?? '', icon_options()) ? $card['icon'] : 'heart') ?></span>
        <<?= $sub ?>><?= e($card['title']) ?></<?= $sub ?>>
        <?php if (($card['text'] ?? '') !== ''): ?><p><?= e($card['text']) ?></p><?php endif ?>
        <?php if (($card['link_label'] ?? '') !== '' && $url !== ''): ?><a class="link-arrow" href="<?= e($url) ?>"><?= e($card['link_label']) ?> <?= icon('arrow-right') ?></a><?php endif ?>
      </li>
    <?php endforeach ?>
  </ul>
</div>
