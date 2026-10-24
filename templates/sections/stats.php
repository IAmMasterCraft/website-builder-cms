<?php /** @var array $s @var array $d @var string $h */
$layout = in_array($s['layout'] ?? 'cards', ['cards', 'band', 'rules'], true) ? $s['layout'] : 'cards'; ?>
<div class="container">
  <?= view('sections/_head', ['s' => $s, 'h' => $h]) ?>
  <ul class="stats is-<?= e($layout) ?>" role="list" data-reveal-group>
    <?php foreach ($s['items'] as $i => $item): $v = (string) ($d['values'][$i] ?? ''); $ic = (string) ($item['icon'] ?? ''); ?>
      <li class="stat" data-reveal>
        <?php if ($ic !== '' && array_key_exists($ic, icon_options())): ?><span class="icon-tile" aria-hidden="true"><?= icon($ic) ?></span><?php endif ?>
        <strong class="stat-value"<?= $s['animate'] && preg_match('/\d/', $v) ? ' data-count' : '' ?>><?= e($v) ?></strong>
        <span class="stat-label"><?= e($item['label']) ?></span>
      </li>
    <?php endforeach ?>
  </ul>
</div>
