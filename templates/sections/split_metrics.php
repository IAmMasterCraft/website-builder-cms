<?php /** @var array $s @var array $d @var string $h */ $m = $d['media']; ?>
<div class="container sm-grid<?= $s['image_position'] === 'right' ? ' media-right' : '' ?>">
  <?php if ($m): ?>
  <figure class="sm-media" data-reveal="zoom">
    <?= img_html($m, '(min-width: 60rem) 40rem, 100vw', null, '', true, 1600) ?>
    <?php if (trim((string) $s['chip_value']) !== ''): ?><figcaption class="float-chip"><strong<?= preg_match('/\d/', (string) $s['chip_value']) ? ' data-count' : '' ?>><?= e($s['chip_value']) ?></strong><span><?= e($s['chip_label']) ?></span></figcaption><?php endif ?>
  </figure>
  <?php endif ?>
  <div class="sm-copy" data-reveal>
    <?php if ($s['eyebrow'] !== ''): ?><span class="eyebrow"><?= e($s['eyebrow']) ?></span><?php endif ?>
    <<?= $h ?>><?= e($s['heading']) ?></<?= $h ?>>
    <?php if ($s['text'] !== ''): ?><p><?= e($s['text']) ?></p><?php endif ?>
    <?= view('sections/_button', ['s' => $s, 'cls' => 'ghost']) ?>
    <?php if ($s['metrics']): ?>
    <ul class="metrics" data-reveal-group>
      <?php foreach ($s['metrics'] as $mt): ?><li class="metric" data-reveal><span class="metric-label"><?= e($mt['label']) ?></span><strong class="metric-value"<?= preg_match('/\d/', (string) $mt['value']) ? ' data-count' : '' ?>><?= e($mt['value']) ?></strong></li><?php endforeach ?>
    </ul>
    <?php endif ?>
  </div>
</div>
