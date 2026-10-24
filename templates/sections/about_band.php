<?php /** @var array $s @var array $d @var string $h */ $m = $d['media']; ?>
<div class="container">
  <div class="ab-head" data-reveal>
    <div>
      <?php if ($s['eyebrow'] !== ''): ?><span class="eyebrow"><?= e($s['eyebrow']) ?></span><?php endif ?>
      <<?= $h ?>><?= e($s['heading']) ?></<?= $h ?>>
    </div>
    <div class="ab-text">
      <?php foreach (array_filter(array_map('trim', preg_split('/\R{2,}/', (string) $s['text']) ?: [])) as $para): ?><p><?= e($para) ?></p><?php endforeach ?>
      <?= view('sections/_button', ['s' => $s, 'cls' => 'on-dark']) ?>
    </div>
  </div>
  <?php if ($m || $s['counters']): ?>
  <figure class="ab-media" data-reveal="zoom">
    <?= $m ? img_html($m, '(min-width: 80rem) 80rem, 100vw', null, '', true, 1600) : '' ?>
    <?php if ($s['counters']): ?>
    <ul class="ab-counters">
      <?php foreach ($s['counters'] as $c): ?><li><strong<?= preg_match('/\d/', (string) $c['value']) ? ' data-count' : '' ?>><?= e($c['value']) ?></strong><span><?= e($c['label']) ?></span></li><?php endforeach ?>
    </ul>
    <?php endif ?>
  </figure>
  <?php endif ?>
</div>
