<?php /** @var string $from @var string $to @var array $r @var int $ytd @var array $dash */ ?>
<div class="pay-admin">
<div class="page-head"><div><h1>Payment reports</h1><p>Confirmed payments (reversals subtracted) by date paid.</p></div>
  <div class="actions"><a class="btn ghost" href="/admin/payments/reports.csv?from=<?= e($from) ?>&amp;to=<?= e($to) ?>"><?= icon('download') ?> Export CSV</a></div></div>
<form class="toolbar panel panel-body" method="get" action="/admin/payments/reports">
  <label for="rp-from">From</label><input type="date" id="rp-from" name="from" value="<?= e($from) ?>">
  <label for="rp-to">To</label><input type="date" id="rp-to" name="to" value="<?= e($to) ?>">
  <button class="btn small ghost" type="submit">Show</button>
</form>
<dl class="pay-stats">
  <div><dt>Total <?= e((new DateTimeImmutable($from))->format('j M Y')) ?> – <?= e((new DateTimeImmutable($to))->format('j M Y')) ?></dt><dd class="tabular"><?= e(money($r['total'])) ?></dd></div>
  <div><dt>Year to date</dt><dd class="tabular"><?= e(money($ytd)) ?></dd></div>
  <div><dt>Collected this month</dt><dd class="tabular"><?= e(money($dash['month'])) ?></dd></div>
  <div><dt>Waiting for review</dt><dd class="tabular"><?= (int) $dash['pending'] ?></dd></div>
</dl>
<div class="dash-grid">
  <section class="panel"><div class="panel-head"><h2>By item</h2></div><div class="table-wrap"><table class="data"><caption class="sr-only">Totals by item</caption>
    <thead><tr><th scope="col">Item</th><th scope="col">Payments</th><th scope="col">Total</th></tr></thead><tbody>
    <?php if (!$r['items']): ?><tr><td colspan="3" class="empty-row">No payments in this period.</td></tr><?php endif ?>
    <?php foreach ($r['items'] as $i): ?><tr><td><?= $i['item_id'] !== null ? '<a href="/admin/payments/items/' . (int) $i['item_id'] . '">' . e($i['name']) . '</a>' : e($i['name']) ?></td><td class="tabular"><?= (int) $i['n'] ?></td><td class="tabular"><?= e(money((int) $i['total'])) ?></td></tr><?php endforeach ?>
  </tbody></table></div></section>
  <div>
    <section class="panel"><div class="panel-head"><h2>By month</h2></div><div class="table-wrap"><table class="data"><caption class="sr-only">Totals by month</caption>
      <thead><tr><th scope="col">Month</th><th scope="col">Payments</th><th scope="col">Total</th></tr></thead><tbody>
      <?php foreach ($r['months'] as $m): ?><tr><td><?= e((new DateTimeImmutable($m['ym'] . '-01'))->format('F Y')) ?></td><td class="tabular"><?= (int) $m['n'] ?></td><td class="tabular"><?= e(money((int) $m['total'])) ?></td></tr><?php endforeach ?>
    </tbody></table></div></section>
    <section class="panel"><div class="panel-head"><h2>By method</h2></div><div class="table-wrap"><table class="data"><caption class="sr-only">Totals by method</caption>
      <thead><tr><th scope="col">Method</th><th scope="col">Payments</th><th scope="col">Total</th></tr></thead><tbody>
      <?php foreach ($r['methods'] as $m): ?><tr><td><?= e(PAYMENT_METHODS[$m['method']] ?? (string) $m['method']) ?></td><td class="tabular"><?= (int) $m['n'] ?></td><td class="tabular"><?= e(money((int) $m['total'])) ?></td></tr><?php endforeach ?>
    </tbody></table></div></section>
    <?php if ($dash['outstanding']): ?><section class="panel"><div class="panel-head"><h2>Most outstanding</h2></div><ul class="activity"><?php foreach ($dash['outstanding'] as $o): ?><li><a href="/admin/payments/items/<?= (int) $o['id'] ?>"><?= e($o['name']) ?></a><span class="tabular"><?= e(money((int) $o['outstanding_minor'])) ?></span></li><?php endforeach ?></ul></section><?php endif ?>
  </div>
</div>
</div>
