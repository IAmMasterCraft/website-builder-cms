<?php /** @var array $s @var array $d @var string $h */
$tone = (string) $s['tone'];
$m = $d['media'] ?? null;
if ($tone === 'image' && $m === null) {
    $tone = 'dark';
}
$eyebrow = trim((string) ($s['eyebrow'] ?? ''));
$copy = ($eyebrow !== '' ? '<span class="eyebrow">' . e($eyebrow) . '</span>' : '') . '<' . $h . '>' . e($s['heading']) . '</' . $h . '>' . ($s['text'] !== '' ? '<p>' . e($s['text']) . '</p>' : '');
$light = $tone === 'soft'; ?>
<div class="container">
  <div class="cta-band tone-<?= e($tone) ?><?= $light ? '' : ' on-dark' ?>" data-reveal="zoom">
    <?php if ($tone === 'image'): ?>
      <?= img_html($m, '(min-width: 80rem) 80rem, 100vw', '', '', true, 1600) ?>
      <div class="cta-glass"><?= $copy ?><?= view('sections/_buttons', ['s' => $s, 'first' => 'highlight', 'second' => 'on-dark']) ?></div>
    <?php else: ?>
      <div><?= $copy ?></div>
      <?= view('sections/_buttons', ['s' => $s, 'first' => $light ? 'accent' : ($tone === 'accent' ? 'light' : 'highlight'), 'second' => $light ? 'ghost' : 'on-dark']) ?>
    <?php endif ?>
  </div>
</div>
