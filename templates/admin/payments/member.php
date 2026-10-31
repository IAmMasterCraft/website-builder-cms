<?php /** A member's payments. @var array $m @var array $charges @var array $charge_events @var array $records @var array $totals @var array $addable */ ?>
<div class="pay-admin">
<div class="page-head">
  <div><p class="crumbs"><a href="/admin/payments/all">Payments</a> /</p><h1><?= e(member_full_name($m)) ?></h1>
    <p><span class="tabular"><?= e((string) $m['member_number']) ?></span> · fee status <?= e((string) ($m['membership_fee_status'] ?? 'not set')) ?><?= $m['card_valid_until'] ? ' · card valid until ' . e(fmt_date((string) $m['card_valid_until'])) : '' ?></p></div>
  <div class="actions">
    <a class="btn accent" href="/admin/payments/record?member=<?= (int) $m['id'] ?>"><?= icon('plus') ?> Record a payment</a>
    <?php if (user_can(current_user(), 'members')): ?><a class="btn ghost" href="/admin/members/<?= (int) $m['id'] ?>"><?= icon('user') ?> Member page</a><?php endif ?>
  </div>
</div>
<dl class="pay-stats"><div><dt>Outstanding (open items)</dt><dd class="tabular"><?= e(money($totals['outstanding'])) ?></dd></div><div><dt>Paid in <?= (int) $totals['year'] ?></dt><dd class="tabular"><?= e(money($totals['paid_year'])) ?></dd></div></dl>
<section class="panel" aria-labelledby="ch-title">
  <div class="panel-head"><h2 id="ch-title">Charges</h2></div>
  <div class="table-wrap"><table class="data">
    <caption class="sr-only">What the member is expected to pay</caption>
    <thead><tr><th scope="col">Item</th><th scope="col">Amount</th><th scope="col">Paid</th><th scope="col">Balance</th><th scope="col">Status</th><th scope="col">Change</th></tr></thead>
    <tbody>
    <?php if (!$charges): ?><tr><td colspan="6" class="empty-row">No charges.</td></tr><?php endif ?>
    <?php foreach ($charges as $c): ?>
      <tr id="charge-<?= (int) $c['id'] ?>">
        <td><a href="/admin/payments/items/<?= (int) $c['item_id'] ?>"><?= e($c['item_name']) ?></a><?= $c['item_status'] !== 'open' ? ' <span class="badge">' . e($c['item_status']) . '</span>' : '' ?>
          <?php foreach ($charge_events[(int) $c['id']] ?? [] as $ev): ?><br><span class="muted small-text"><?= e(fmt_date((string) $ev['created_at'])) ?> · <?= e(str_replace('_', ' ', (string) $ev['event_type'])) ?> by <?= e((string) ($ev['display_name'] ?? 'System')) ?>: <?= e((string) $ev['note']) ?></span><?php endforeach ?></td>
        <td class="tabular"><?= e(money((int) $c['amount_minor'])) ?></td>
        <td class="tabular"><?= e(money((int) $c['paid_minor'])) ?></td>
        <td class="tabular"><?= e(money(payment_charge_balance($c))) ?></td>
        <td><span class="badge"><?= e($c['charge_status'] === 'part' ? 'part-paid' : $c['charge_status']) ?></span><?= $c['waive_reason'] !== '' ? '<br><span class="muted small-text">' . e($c['waive_reason']) . '</span>' : '' ?></td>
        <td>
          <details><summary>Change<span class="sr-only"> <?= e($c['item_name']) ?></span></summary>
            <form method="post" action="/admin/payments/charges/<?= (int) $c['id'] ?>" class="pay-inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="amount">
              <label for="ca-<?= (int) $c['id'] ?>">New amount</label><input type="text" id="ca-<?= (int) $c['id'] ?>" name="amount" inputmode="decimal" value="<?= e(money_input((int) $c['amount_minor'])) ?>" size="8">
              <label for="cr-<?= (int) $c['id'] ?>">Reason</label><input type="text" id="cr-<?= (int) $c['id'] ?>" name="reason" maxlength="255" required>
              <button class="btn small ghost" type="submit">Change amount</button></form>
            <form method="post" action="/admin/payments/charges/<?= (int) $c['id'] ?>" class="pay-inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="<?= (int) $c['waived'] ? 'unwaive' : 'waive' ?>">
              <label for="cw-<?= (int) $c['id'] ?>">Reason</label><input type="text" id="cw-<?= (int) $c['id'] ?>" name="reason" maxlength="255" required>
              <button class="btn small ghost" type="submit"><?= (int) $c['waived'] ? 'Stop waiving' : 'Waive' ?></button></form>
          </details>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table></div>
  <?php if ($addable): ?>
  <form method="post" action="/admin/payments/member/<?= (int) $m['id'] ?>/charges" class="panel-body pay-inline-form"><?= csrf_field() ?>
    <label for="add-item">Add a charge</label><select id="add-item" name="item_id"><?php foreach ($addable as $i): ?><option value="<?= (int) $i['id'] ?>"><?= e($i['name']) ?> (<?= e(money((int) $i['amount_minor'])) ?>)</option><?php endforeach ?></select>
    <button class="btn small ghost" type="submit"><?= icon('plus') ?> Add</button></form>
  <?php endif ?>
</section>
<section class="panel" aria-labelledby="rc-title">
  <div class="panel-head"><h2 id="rc-title">Payment records</h2><a class="btn small ghost" href="/admin/payments/all?member=<?= (int) $m['id'] ?>">Filter all payments</a></div>
  <div class="table-wrap"><table class="data">
    <caption class="sr-only">Payment records of <?= e(member_full_name($m)) ?></caption>
    <thead><tr><th scope="col">#</th><th scope="col">For</th><th scope="col">Amount</th><th scope="col">Paid</th><th scope="col">Status</th><th scope="col">Receipt</th></tr></thead>
    <tbody>
    <?php if (!$records): ?><tr><td colspan="6" class="empty-row">No payments yet.</td></tr><?php endif ?>
    <?php foreach ($records as $p): ?><tr><td class="tabular"><a href="/admin/payments/<?= (int) $p['id'] ?>"><?= (int) $p['id'] ?></a></td><td><?= e(payment_what($p)) ?></td><td class="tabular"><?= e(money((int) $p['amount_minor'], (string) $p['currency'])) ?></td><td class="tabular"><?= e((new DateTimeImmutable((string) $p['paid_on']))->format('j M Y')) ?></td><td><?= view('payments/_status', ['p' => $p]) ?></td><td class="tabular"><?= $p['receipt_number'] !== null ? '<a href="/admin/payments/' . (int) $p['id'] . '/receipt">' . e($p['receipt_number']) . '</a>' : '—' ?></td></tr><?php endforeach ?>
    </tbody>
  </table></div>
</section>
</div>
