<?php /** @var array $s @var array $d @var string $h */
$sub = $h === 'h1' ? 'h2' : 'h3';
$horizontal = $s['layout'] === 'horizontal'; ?>
<div class="container<?= $horizontal ? '' : ' pr-grid' ?>">
  <?php if ($horizontal): ?>
    <?= view('sections/_head', ['s' => $s, 'h' => $h, 'layout' => 'split']) ?>
  <?php else: ?>
  <div class="pr-intro" data-reveal>
    <?php if ($s['eyebrow'] !== ''): ?><span class="eyebrow"><?= e($s['eyebrow']) ?></span><?php endif ?>
    <?php if ($s['heading'] !== ''): ?><<?= $h ?>><?= e($s['heading']) ?></<?= $h ?>><?php endif ?>
    <?php if ($s['intro'] !== ''): ?><p><?= e($s['intro']) ?></p><?php endif ?>
    <?= view('sections/_button', ['s' => $s]) ?>
    <?php if ($d['media']): ?><figure class="pr-media"><?= img_html($d['media'], '(min-width: 60rem) 28rem, 100vw', null, '', true, 800) ?></figure><?php endif ?>
  </div>
  <?php endif ?>
  <ol class="steps<?= $horizontal ? ' is-horizontal' : '' ?>"<?= $horizontal ? '' : ' data-steps' ?> data-reveal-group>
    <?php foreach ($s['steps'] as $i => $step): ?>
      <li class="step" data-reveal>
        <span class="step-dot" aria-hidden="true"></span>
        <span class="step-badge">Step <?= $i + 1 ?></span>
        <div class="step-body"><<?= $sub ?>><?= e($step['title']) ?></<?= $sub ?>><?php if (($step['text'] ?? '') !== ''): ?><p><?= e($step['text']) ?></p><?php endif ?></div>
      </li>
    <?php endforeach ?>
  </ol>
  <?php if ($horizontal): ?><?= view('sections/_button', ['s' => $s]) ?><?php endif ?>
</div>
