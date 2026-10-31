<?php /** @var array $items @var array $counts */ ?>
<div class="pay-admin">
<div class="page-head"><div><h1>Dues and items</h1><p>What members pay for: dues, levies, event fees and donations, with how much has come in.</p></div>
  <div class="actions"><a class="btn accent" href="/admin/payments/items/new"><?= icon('plus') ?> New item</a></div></div>
<div class="panel"><div class="table-wrap"><table class="data">
  <caption class="sr-only">Payment items</caption>
  <thead><tr><th scope="col">Item</th><th scope="col">Amount</th><th scope="col">Due</th><th scope="col">Expected</th><th scope="col">Collected</th><th scope="col">Outstanding</th><th scope="col">Paid</th><th scope="col">Members: paid / part / unpaid / waived</th></tr></thead>
  <tbody>
  <?php if (!$items): ?><tr><td colspan="8" class="empty-row">No items yet. Start with this year’s dues.</td></tr><?php endif ?>
  <?php foreach ($items as $i): $c = $counts[(int) $i['id']]; $pct = (int) $i['expected_minor'] > 0 ? (int) min(100, floor((int) $i['collected_minor'] * 100 / (int) $i['expected_minor'])) : null; ?>
    <tr>
      <td><a href="/admin/payments/items/<?= (int) $i['id'] ?>"><?= e($i['name']) ?></a><br><span class="badge"><?= e($i['status']) ?></span> <span class="muted small-text"><?= e(PAYMENT_ITEM_KINDS[$i['item_kind']] ?? '') ?></span></td>
      <td class="tabular"><?= e(money($i['amount_minor'] !== null ? (int) $i['amount_minor'] : null)) ?></td>
      <td class="tabular"><?= $i['due_date'] !== null ? e((new DateTimeImmutable((string) $i['due_date']))->format('j M Y')) : '—' ?></td>
      <td class="tabular"><?= e(money((int) $i['expected_minor'])) ?></td>
      <td class="tabular"><?= e(money((int) $i['collected_minor'])) ?></td>
      <td class="tabular"><?= e(money((int) $i['outstanding_minor'])) ?></td>
      <td><?php if ($pct !== null): ?><progress class="pay-bar" max="100" value="<?= $pct ?>" aria-label="<?= e($i['name']) ?>: <?= $pct ?>% collected"><?= $pct ?>%</progress> <span class="tabular small-text"><?= $pct ?>%</span><?php else: ?><span class="muted">—</span><?php endif ?></td>
      <td class="tabular"><?= (int) $c['paid'] ?> / <?= (int) $c['part'] ?> / <?= (int) $c['unpaid'] ?> / <?= (int) $c['waived'] ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table></div></div>
</div>
