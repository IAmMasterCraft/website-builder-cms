<?php /** @var array $groups @var array $summary */
$label = ['ok' => 'OK', 'warn' => 'Check', 'bad' => 'Problem'];
$badge = ['ok' => 'ok', 'warn' => 'warn', 'bad' => 'bad'];
?>
<div class="page-head"><div><h1>System health</h1>
  <p class="tabular"><?= (int) $summary['ok'] ?> OK · <?= (int) $summary['warn'] ?> to check · <?= (int) $summary['bad'] ?> problem<?= $summary['bad'] === 1 ? '' : 's' ?></p></div>
  <div class="actions"><a class="btn ghost" href="/admin/settings/email#test"><?= icon('mail') ?> Send a test email</a><a class="btn ghost" href="/admin/backups"><?= icon('download') ?> Backups</a></div></div>
<?php if ($summary['bad'] > 0): ?><div class="flash error" role="alert"><?= icon('alert') ?><span>Some checks failed. Fix the items marked “Problem”.</span></div><?php endif ?>
<div class="health-grid">
<?php foreach ($groups as $title => $items): ?>
  <section class="panel"><div class="panel-head"><h2><?= e($title) ?></h2></div>
    <ul class="row-list health-list">
      <?php foreach ($items as $i): ?>
        <li><div><span class="row-title"><?= e($i['label']) ?></span><p class="muted small-text"><?= e($i['detail']) ?></p></div>
          <div class="row-meta"><span class="badge <?= e($badge[$i['status']]) ?>"><?= e($label[$i['status']]) ?></span></div></li>
      <?php endforeach ?>
    </ul>
  </section>
<?php endforeach ?>
</div>
