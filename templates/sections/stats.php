<?php /** @var array $s @var array $d @var string $h */ ?>
<div class="container">
  <?= view('sections/_head', ['s' => $s, 'h' => $h]) ?>
  <ul class="stats" role="list">
    <?php foreach ($s['items'] as $i => $item): $v = (string) ($d['values'][$i] ?? ''); ?>
      <li class="stat"><strong<?= $s['animate'] && preg_match('/^\d{1,9}$/', $v) ? ' data-count="' . e($v) . '"' : '' ?>><?= e($v) ?></strong><span><?= e($item['label']) ?></span></li>
    <?php endforeach ?>
  </ul>
</div>
