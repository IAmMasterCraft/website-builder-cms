<?php /** @var array $s @var array $d @var string $h */
if (!$d['people'] && $s['hide_when_empty']) {
    return;
} ?>
<div class="container">
  <div class="blk-head"><div>
    <<?= $h ?>><?= e($s['heading']) ?></<?= $h ?>>
    <?php if ($s['intro'] !== ''): ?><p><?= e($s['intro']) ?></p><?php endif ?>
  </div></div>
  <?php if ($d['people']): ?>
    <ul class="celebrants">
      <?php foreach ($d['people'] as $p): ?>
        <li><span class="celebrant-initial" aria-hidden="true"><?= e($p['initial']) ?></span><span><strong><?= e($p['name']) ?></strong><?php if ($s['range'] === 'week'): ?><br><span class="muted"><?= e((new DateTimeImmutable(sprintf('2000-%02d-%02d', $p['month'], $p['day'])))->format('j F')) ?></span><?php endif ?></span></li>
      <?php endforeach ?>
    </ul>
  <?php else: ?>
    <?= view('partials/empty', ['message' => $s['empty_text']]) ?>
  <?php endif ?>
</div>
