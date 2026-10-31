<?php /** Pending queue. @var array $rows @var array $dups @var int $pending @var int $needs_info @var ?string $next @var bool $first_page @var bool $emails_on @var array $settings */ ?>
<div class="pay-admin">
<div class="page-head">
  <div><h1>Payments</h1><p class="tabular"><?= e(pluralise($pending, 'payment report')) ?> waiting for review, oldest first<?= $needs_info ? ' · <a href="/admin/payments/all?status=needs_info">' . e(pluralise($needs_info, 'question')) . ' waiting for the member</a>' : '' ?></p></div>
  <div class="actions"><a class="btn ghost" href="/admin/payments/record"><?= icon('plus') ?> Record a payment</a><a class="btn ghost" href="/admin/payments/items"><?= icon('tag') ?> Dues and items</a></div>
</div>
<div class="pay-queue-page">
  <div class="panel">
    <?php if (!$rows): ?><p class="empty-row">Nothing to review. New reports from members appear here.</p><?php else: ?>
    <form method="post" action="/admin/payments/bulk-approve" id="bulk-form"><?= csrf_field() ?></form>
    <ul class="pay-queue">
      <?php foreach ($rows as $p): $warn = ($dups[(int) $p['id']] ?? []) !== [] || ($p['charge_id'] !== null && (int) $p['amount_minor'] > max(0, (int) $p['charge_amount'] - (int) $p['charge_paid'])); ?>
        <li>
          <div><?php if (!$warn): ?><input type="checkbox" form="bulk-form" name="ids[]" value="<?= (int) $p['id'] ?>" aria-label="Select payment #<?= (int) $p['id'] ?> from <?= e(payment_member_label($p)) ?> for bulk approval"><?php else: ?><span class="sr-only">Has warnings: review on its own</span><?php endif ?></div>
          <div><?= view('admin/payments/_receipt_thumb', ['p' => $p]) ?></div>
          <div>
            <h2><a href="/admin/payments/<?= (int) $p['id'] ?>"><?= e(payment_member_label($p)) ?></a> <span class="muted tabular"><?= e((string) ($p['member_number'] ?? '')) ?></span></h2>
            <p><strong class="tabular"><?= e(money((int) $p['amount_minor'], (string) $p['currency'])) ?></strong> for <?= e(payment_what($p)) ?><?= view('admin/payments/_balance', ['p' => $p]) ?></p>
            <p class="small-text">Paid <?= e((new DateTimeImmutable((string) $p['paid_on']))->format('j M Y')) ?> · <?= e(PAYMENT_METHODS[$p['method']] ?? $p['method']) ?><?= $p['reference'] !== '' ? ' · ref <span class="tabular">' . e($p['reference']) . '</span>' : ' · no reference' ?><?= $p['payer_name'] !== '' ? ' · paid by ' . e($p['payer_name']) : '' ?></p>
            <p class="small-text muted"><?= $p['recorded_by'] !== null ? 'Recorded by ' . e((string) $p['recorder_name']) : 'Reported' ?> <?= e(fmt_date((string) $p['created_at'], 'j M, H:i')) ?></p>
            <?= view('admin/payments/_dups', ['dups' => $dups[(int) $p['id']] ?? []]) ?>
          </div>
          <div class="pay-review">
            <form method="post" action="/admin/payments/<?= (int) $p['id'] ?>/approve"><?= csrf_field() ?><input type="hidden" name="return" value="queue"><button class="btn small accent" type="submit"><?= icon('check') ?> Approve<span class="sr-only"> payment #<?= (int) $p['id'] ?></span></button></form>
            <details><summary>Reject or ask for info<span class="sr-only"> (payment #<?= (int) $p['id'] ?>)</span></summary>
              <form method="post" action="/admin/payments/<?= (int) $p['id'] ?>/reject"><?= csrf_field() ?><input type="hidden" name="return" value="queue">
                <label for="rs-<?= (int) $p['id'] ?>">Reason (sent to the member)</label>
                <select id="rs-<?= (int) $p['id'] ?>" name="preset" data-pay-preset><option value="">Choose a reason or type one…</option><?php foreach (PAYMENT_REJECT_PRESETS as $r): ?><option><?= e($r) ?></option><?php endforeach ?></select>
                <label class="sr-only" for="rr-<?= (int) $p['id'] ?>">Reason in your own words</label><input type="text" id="rr-<?= (int) $p['id'] ?>" name="reason" maxlength="1000" placeholder="Or type the reason" data-pay-reason>
                <button class="btn small danger-ghost" type="submit">Reject</button></form>
              <form method="post" action="/admin/payments/<?= (int) $p['id'] ?>/info"><?= csrf_field() ?><input type="hidden" name="return" value="queue">
                <label for="iq-<?= (int) $p['id'] ?>">Question for the member</label><input type="text" id="iq-<?= (int) $p['id'] ?>" name="question" maxlength="1000">
                <button class="btn small ghost" type="submit">Ask for info</button></form>
            </details>
          </div>
        </li>
      <?php endforeach ?>
    </ul>
    <div class="pager">
      <button class="btn small ghost" type="submit" form="bulk-form"><?= icon('check-check') ?> Approve selected</button>
      <span class="muted small-text">Only reports without warnings can be ticked.</span>
      <span class="btn-row"><?php if (!$first_page): ?><a class="btn small ghost" href="/admin/payments">First page</a><?php endif ?><?php if ($next !== null): ?><a class="btn small ghost" href="/admin/payments?after=<?= e(rawurlencode($next)) ?>">Next page</a><?php endif ?></span>
    </div>
    <?php endif ?>
  </div>
  <form class="panel panel-body pay-inline-form" method="post" action="/admin/payments/preferences" id="payment-emails">
    <?= csrf_field() ?>
    <?= field_html('payment_emails', 'Email me about new payment reports', 'checkbox', $emails_on ? '1' : '0', null, ['hint' => 'At most one email every 15 minutes, listing the new and changed reports.']) ?>
    <button class="btn small ghost" type="submit"><?= icon('check') ?> Save</button>
    <?php if ($settings['separation']): ?><p class="hint">Separation of duties is on: you cannot approve payments you recorded yourself.</p><?php endif ?>
  </form>
</div>
</div>
