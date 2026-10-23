<?php /** @var array $s @var string $h */ ?>
<div class="container">
  <div class="cta-band tone-<?= e($s['tone']) ?>">
    <div><<?= $h ?>><?= e($s['heading']) ?></<?= $h ?>><?php if ($s['text'] !== ''): ?><p><?= e($s['text']) ?></p><?php endif ?></div>
    <?= view('sections/_buttons', ['s' => $s, 'first' => $s['tone'] === 'soft' ? 'accent' : 'highlight', 'second' => $s['tone'] === 'soft' ? 'ghost' : 'on-dark']) ?>
  </div>
</div>
