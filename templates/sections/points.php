<?php /** @var array $s @var string $h */
$sub = $h === 'h1' ? 'h2' : 'h3';
$style = in_array($s['style'], ['dots', 'checks', 'numbers'], true) ? $s['style'] : 'dots'; ?>
<div class="container pt-grid">
  <div class="pt-copy" data-reveal>
    <?php if ($s['eyebrow'] !== ''): ?><span class="eyebrow"><?= e($s['eyebrow']) ?></span><?php endif ?>
    <<?= $h ?>><?= e($s['heading']) ?></<?= $h ?>>
    <?php if ($s['text'] !== ''): ?><p><?= e($s['text']) ?></p><?php endif ?>
    <?= view('sections/_button', ['s' => $s]) ?>
  </div>
  <div>
    <?php if (trim((string) $s['list_label']) !== ''): ?><p class="pt-label"><?= e($s['list_label']) ?></p><?php endif ?>
    <ul class="points style-<?= e($style) ?>" data-reveal-group>
      <?php foreach ($s['points'] as $p): ?>
        <li class="point" data-reveal><span class="point-mark" aria-hidden="true"><?= $style === 'checks' ? icon('check') : '' ?></span><<?= $sub ?>><?= e($p['title']) ?></<?= $sub ?>><?php if (($p['text'] ?? '') !== ''): ?><p><?= e($p['text']) ?></p><?php endif ?></li>
      <?php endforeach ?>
    </ul>
  </div>
</div>
