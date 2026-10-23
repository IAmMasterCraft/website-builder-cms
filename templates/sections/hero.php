<?php /** @var array $s @var array $d @var string $h */
$centered = $s['layout'] === 'centered';
$slides = $d['slides'];
$first = $h === 'h1'; ?>
<?php if ($centered && $slides): ?>
  <div class="blk-bg hero-backdrop" aria-hidden="<?= count($slides) > 1 ? 'false' : 'true' ?>"><?= view('sections/_slider', ['slides' => $slides, 'autoplay' => (bool) $s['autoplay'], 'sizes' => '100vw', 'eager' => $first]) ?></div>
<?php endif ?>
<div class="container hero-grid<?= $centered ? ' hero-centered' : '' ?><?= !$centered && $slides ? ' has-media' : '' ?>">
  <div class="hero-copy">
    <?php if ($s['eyebrow'] !== ''): ?><span class="eyebrow"><?= e($s['eyebrow']) ?></span><?php endif ?>
    <<?= $h ?> class="hero-title"><?= e($s['heading']) ?></<?= $h ?>>
    <?php if ($s['text'] !== ''): ?><p class="lede"><?= e($s['text']) ?></p><?php endif ?>
    <?= view('sections/_buttons', ['s' => $s, 'first' => 'highlight', 'second' => 'on-dark']) ?>
  </div>
  <?php if (!$centered && $slides): ?>
    <div class="hero-media"><?= view('sections/_slider', ['slides' => $slides, 'autoplay' => (bool) $s['autoplay'], 'sizes' => '(min-width: 56rem) 34rem, 100vw', 'eager' => $first]) ?></div>
  <?php endif ?>
</div>
