<?php /** @var array $s @var array $d @var string $h */ ?>
<div class="container">
  <div class="radio-card size-<?= e($s['size']) ?>">
    <span class="radio-icon" aria-hidden="true"><?= icon('radio') ?></span>
    <div>
      <<?= $h ?> class="h3"><?= e($s['heading']) ?></<?= $h ?>>
      <?php if ($d['on']): ?>
        <p><a class="link-arrow" href="/radio">Open the radio player <?= icon('arrow-right') ?></a></p>
      <?php else: ?>
        <p class="muted"><span class="badge muted">Off air</span> Our community radio is not broadcasting yet. Check back soon.</p>
      <?php endif ?>
    </div>
  </div>
</div>
