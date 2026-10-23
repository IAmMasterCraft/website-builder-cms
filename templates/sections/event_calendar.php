<?php /** @var array $s @var array $d @var string $h */ ?>
<div class="container">
  <?= view('sections/_head', ['s' => $s, 'h' => $h]) ?>
  <div class="cal-toolbar"><h3 class="h3"><?= e($d['label']) ?></h3>
    <div class="cal-nav"><a class="btn ghost small" href="<?= e($d['next']) ?>">Next month <?= icon('chevron-right') ?></a></div></div>
  <?= view('partials/calendar_month', ['weeks' => $d['weeks'], 'label' => $d['label']]) ?>
</div>
