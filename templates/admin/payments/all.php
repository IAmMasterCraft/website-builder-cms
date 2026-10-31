<?php /** @var array $rows @var array $filters @var array $items @var ?int $next @var bool $paged */
$query = array_filter($filters, static fn ($v) => $v !== '');
?>
<div class="pay-admin">
<div class="page-head">
  <div><h1>All payments</h1><p>Every payment record, newest first. Reversals are separate negative entries.</p></div>
  <div class="actions"><a class="btn ghost" href="<?= e(url_with('/admin/payments/all.csv', $query, [])) ?>"><?= icon('download') ?> Export CSV</a></div>
</div>
<div class="panel">
  <form class="toolbar" method="get" action="/admin/payments/all" role="search">
    <label for="aq" class="sr-only">Search reference, receipt number or member</label>
    <input type="search" id="aq" name="q" value="<?= e($filters['q']) ?>" placeholder="Reference, receipt, member name or number" maxlength="100">
    <label for="as" class="sr-only">Status</label>
    <select id="as" name="status"><option value="">All statuses</option><?php foreach (PAYMENT_STATUSES + ['reversed' => 'Reversed', 'reversal' => 'Reversal entries'] as $k => $l): ?><option value="<?= e($k) ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select>
    <label for="ai" class="sr-only">Item</label>
    <select id="ai" name="item"><option value="">All items</option><?php foreach ($items as $i): ?><option value="<?= (int) $i['id'] ?>"<?= $filters['item'] === (string) $i['id'] ? ' selected' : '' ?>><?= e($i['name']) ?></option><?php endforeach ?><option value="other"<?= $filters['item'] === 'other' ? ' selected' : '' ?>>Other</option></select>
    <label for="am" class="sr-only">Method</label>
    <select id="am" name="method"><option value="">All methods</option><?php foreach (PAYMENT_METHODS as $k => $l): ?><option value="<?= e($k) ?>"<?= $filters['method'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select>
    <label for="asrc" class="sr-only">Source</label>
    <select id="asrc" name="source"><option value="">Reported and recorded</option><option value="reported"<?= $filters['source'] === 'reported' ? ' selected' : '' ?>>Reported by members</option><option value="recorded"<?= $filters['source'] === 'recorded' ? ' selected' : '' ?>>Recorded by admins</option></select>
    <label for="af">Paid from</label><input type="date" id="af" name="from" value="<?= e($filters['from']) ?>">
    <label for="at">to</label><input type="date" id="at" name="to" value="<?= e($filters['to']) ?>">
    <?php if ($filters['member'] !== ''): ?><input type="hidden" name="member" value="<?= e($filters['member']) ?>"><?php endif ?>
    <button class="btn small ghost" type="submit">Filter</button>
    <?php if ($query): ?><a class="btn small ghost" href="/admin/payments/all">Clear</a><?php endif ?>
  </form>
  <div class="table-wrap">
    <table class="data">
      <caption class="sr-only">Payment records</caption>
      <thead><tr><th scope="col">#</th><th scope="col">Member</th><th scope="col">For</th><th scope="col">Amount</th><th scope="col">Paid</th><th scope="col">Method / reference</th><th scope="col">Status</th><th scope="col">Receipt</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="8" class="empty-row">No payments found.</td></tr><?php endif ?>
      <?php foreach ($rows as $p): ?>
        <tr>
          <td class="tabular"><a href="/admin/payments/<?= (int) $p['id'] ?>"><?= (int) $p['id'] ?></a></td>
          <td><?= $p['member_id'] !== null ? '<a href="/admin/payments/member/' . (int) $p['member_id'] . '">' . e(payment_member_label($p)) . '</a>' : e(payment_member_label($p)) ?><br><span class="muted tabular"><?= e((string) ($p['member_number'] ?? '')) ?></span></td>
          <td><?= e(payment_what($p)) ?></td>
          <td class="tabular"><?= e(money((int) $p['amount_minor'], (string) $p['currency'])) ?></td>
          <td class="tabular"><?= e((new DateTimeImmutable((string) $p['paid_on']))->format('j M Y')) ?></td>
          <td><?= e(PAYMENT_METHODS[$p['method']] ?? $p['method']) ?><?= $p['reference'] !== '' ? '<br><span class="muted tabular">' . e($p['reference']) . '</span>' : '' ?></td>
          <td><?= view('payments/_status', ['p' => $p]) ?><?= $p['recorded_by'] !== null ? '<br><span class="muted small-text">recorded</span>' : '' ?></td>
          <td class="tabular"><?= $p['receipt_number'] !== null ? '<a href="/admin/payments/' . (int) $p['id'] . '/receipt">' . e($p['receipt_number']) . '</a>' : '—' ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <div class="pager"><span class="muted"><?= count($rows) ?> shown</span><span class="btn-row"><?php if ($paged): ?><a class="btn small ghost" href="<?= e(url_with('/admin/payments/all', $query, [])) ?>">Newest</a><?php endif ?><?php if ($next !== null): ?><a class="btn small ghost" href="<?= e(url_with('/admin/payments/all', $query, ['before' => $next])) ?>">Older</a><?php endif ?></span></div>
</div>
</div>
