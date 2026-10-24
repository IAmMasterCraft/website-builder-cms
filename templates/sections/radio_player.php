<?php /** @var array $s @var array $d @var string $h */ ?>
<div class="container">
  <?php if ($d['on']): ?>
    <?= view('partials/radio_player', ['size' => $s['size'], 'heading' => $s['heading'], 'headingTag' => $h]) ?>
    <?php if ($s['size'] === 'mini'): ?><p class="rp-more"><a class="link-arrow" href="/radio">Radio schedule and programmes <?= icon('arrow-right') ?></a></p><?php endif ?>
  <?php else: ?>
    <div class="radio-card size-<?= e($s['size']) ?>">
      <span class="radio-icon" aria-hidden="true"><?= icon('radio') ?></span>
      <div>
        <<?= $h ?> class="h3"><?= e($s['heading']) ?></<?= $h ?>>
        <p class="muted"><span class="badge muted">Off air</span> Our community radio is not broadcasting right now. <a href="/radio">Listen to past programmes</a>.</p>
      </div>
    </div>
  <?php endif ?>
</div>
