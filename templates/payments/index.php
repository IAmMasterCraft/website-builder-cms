<?php /** @var array $owe @var array $records @var array $years @var int $year @var array $totals @var array $optional @var string $today @var array $settings @var bool $impersonating */ ?>
<div class="pay">
  <?php if ($impersonating): ?><p class="notice warn" role="status"><?= icon('eye') ?> You are viewing the member’s payments. Reporting, editing and withdrawing are not available while you view the site as a member.</p><?php endif ?>
  <section class="dash-card" aria-labelledby="owe-title">
    <div class="dash-card-head"><h2 id="owe-title">What I owe</h2><?php if (!$impersonating): ?><a class="btn accent small" href="/account/payments/new"><?= icon('plus') ?> Report a payment</a><?php endif ?></div>
    <dl class="pay-totals">
      <div><dt>Outstanding</dt><dd class="tabular"><?= e(money($totals['outstanding'])) ?></dd></div>
      <div><dt>Paid in <?= (int) $totals['year'] ?></dt><dd class="tabular"><?= e(money($totals['paid_year'])) ?></dd></div>
    </dl>
    <?php if (!$owe): ?>
      <p class="muted">You have nothing to pay at the moment. Thank you!</p>
    <?php else: ?>
      <ul class="pay-owe">
        <?php foreach ($owe as $c): $overdue = $c['due_date'] !== null && $c['due_date'] < $today; ?>
          <li>
            <div class="pay-owe-main">
              <h3><?= e($c['item_name']) ?><?= $overdue ? ' <span class="badge warn">Overdue</span>' : '' ?></h3>
              <p class="muted tabular">Amount <?= e(money((int) $c['amount_minor'])) ?> · paid <?= e(money((int) $c['paid_minor'])) ?><?= $c['due_date'] !== null ? ' · due ' . e((new DateTimeImmutable((string) $c['due_date']))->format('j M Y')) : '' ?></p>
              <?php if (($waiting[(int) $c['id']] ?? 0) > 0): ?><p class="small-text"><?= icon('clock') ?> <?= e(money($waiting[(int) $c['id']])) ?> reported, waiting for confirmation</p><?php endif ?>
            </div>
            <p class="pay-owe-balance"><span class="muted">Balance</span> <strong class="tabular"><?= e(money(payment_charge_balance($c))) ?></strong></p>
            <?php if (!$impersonating): ?><a class="btn small" href="/account/payments/new?charge=<?= (int) $c['id'] ?>">Report a payment<span class="sr-only"> for <?= e($c['item_name']) ?></span></a><?php endif ?>
          </li>
        <?php endforeach ?>
      </ul>
    <?php endif ?>
    <?php if ($optional && !$impersonating): ?>
      <p class="pay-optional"><?= icon('gift') ?> You can also pay for: <?php foreach ($optional as $i => $item): ?><?= $i > 0 ? ', ' : '' ?><a href="/account/payments/new?item=<?= (int) $item['id'] ?>"><?= e($item['name']) ?></a><?php endforeach ?>.</p>
    <?php endif ?>
  </section>

  <section class="dash-card" aria-labelledby="hist-title">
    <div class="dash-card-head"><h2 id="hist-title">My payment history</h2>
      <?php if (count($years) > 1): ?>
        <form method="get" action="/account/payments" class="pay-year"><label for="py">Year</label>
          <select id="py" name="year"><option value="">All years</option><?php foreach ($years as $y): ?><option value="<?= $y ?>"<?= $y === $year ? ' selected' : '' ?>><?= $y ?></option><?php endforeach ?></select>
          <button class="btn small ghost" type="submit">Show</button></form>
      <?php endif ?>
    </div>
    <?php if (!$records): ?>
      <p class="muted">No payments yet.</p>
    <?php else: ?>
      <div class="table-card">
        <table class="simple-table pay-table">
          <caption class="sr-only">Your payment records<?= $year ? ' in ' . $year : '' ?></caption>
          <thead><tr><th scope="col">Date paid</th><th scope="col">For</th><th scope="col">Amount</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Receipt and details</span></th></tr></thead>
          <tbody>
          <?php foreach ($records as $r): ?>
            <tr>
              <td class="tabular" data-label="Date paid"><?= e((new DateTimeImmutable((string) $r['paid_on']))->format('j M Y')) ?></td>
              <td data-label="For"><a href="/account/payments/<?= (int) $r['id'] ?>"><?= e(payment_what($r)) ?></a></td>
              <td class="tabular" data-label="Amount"><?= e(money((int) $r['amount_minor'], (string) $r['currency'])) ?></td>
              <td data-label="Status"><?= view('payments/_status', ['p' => $r]) ?></td>
              <td class="pay-actions"><?php if ($r['status'] === 'approved' && $r['entry_type'] === 'payment'): ?><a class="btn small ghost" href="/account/payments/<?= (int) $r['id'] ?>/receipt"><?= icon('file') ?> Receipt<span class="sr-only"> <?= e((string) $r['receipt_number']) ?></span></a><?php elseif ($r['status'] === 'needs_info'): ?><a class="btn small" href="/account/payments/<?= (int) $r['id'] ?>">Answer<span class="sr-only"> the question about payment #<?= (int) $r['id'] ?></span></a><?php endif ?></td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
    <?php endif ?>
  </section>
</div>
