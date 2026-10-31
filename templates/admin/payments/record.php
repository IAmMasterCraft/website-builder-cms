<?php /** @var ?array $member @var string $q @var array $matches @var array $values @var array $errors @var array $warnings @var array $choices @var array $settings */
$v = static fn (string $k): string => (string) ($values[$k] ?? '');
$err = static fn (string $k): ?string => $errors[$k] ?? null;
?>
<div class="pay-admin">
<div class="page-head"><div><h1>Record a payment</h1><p>For money received directly: cash at a meeting, a line on the bank statement. <?= $settings['separation'] ? 'Separation of duties is on: another admin approves what you record.' : 'It is saved as approved and the member gets a receipt.' ?></p></div></div>
<?php if ($member === null): ?>
  <section class="panel"><div class="panel-body">
    <form class="toolbar" method="get" action="/admin/payments/record" role="search">
      <label for="rq">Find the member</label>
      <input type="search" id="rq" name="q" value="<?= e($q) ?>" placeholder="Name, member number or email" maxlength="100">
      <button class="btn small ghost" type="submit"><?= icon('search') ?> Search</button>
    </form>
    <?php if ($q !== ''): ?>
      <?php if (!$matches): ?><p class="muted">No member matches “<?= e($q) ?>”.</p><?php else: ?>
        <ul class="area-list"><?php foreach ($matches as $m): ?><li><a class="btn small ghost" href="/admin/payments/record?member=<?= (int) $m['id'] ?>"><?= e(member_full_name($m)) ?></a> <span class="muted tabular"><?= e((string) $m['member_number']) ?> · <?= e($m['email']) ?></span><?= $m['status'] !== 'active' ? ' <span class="badge warn">' . e($m['status']) . '</span>' : '' ?></li><?php endforeach ?></ul>
      <?php endif ?>
    <?php endif ?>
  </div></section>
<?php else: ?>
  <form class="panel" method="post" action="/admin/payments/record" enctype="multipart/form-data" novalidate data-pay-form>
    <?= csrf_field() ?><input type="hidden" name="member_id" value="<?= (int) $member['id'] ?>">
    <div class="panel-head"><h2><?= e(member_full_name($member)) ?> <span class="muted tabular"><?= e((string) $member['member_number']) ?></span></h2><a class="btn small ghost" href="/admin/payments/record">Choose someone else</a></div>
    <div class="panel-body fields">
      <?= form_errors_html($errors) ?>
      <?php if ($warnings): ?><div class="notice-box" role="alert"><p><strong>Please check before saving:</strong></p><ul><?php foreach ($warnings as $w): ?><li><?= e($w) ?></li><?php endforeach ?></ul></div><?php endif ?>
      <div class="field"><label for="rf-target">What was it for?</label>
        <select id="rf-target" name="target" data-pay-target<?= $err('target') ? ' aria-invalid="true" aria-describedby="rf-target-err"' : '' ?>>
          <option value="">Choose…</option>
          <?php if ($choices['charges']): ?><optgroup label="What the member owes"><?php foreach ($choices['charges'] as $c): $bal = payment_charge_balance($c); ?><option value="c<?= (int) $c['id'] ?>" data-amount="<?= e(money_input($bal ?: null)) ?>" data-balance="<?= e(money($bal)) ?>" data-part="<?= (int) $c['allow_part'] ?>"<?= $v('target') === 'c' . $c['id'] ? ' selected' : '' ?>><?= e($c['item_name']) ?> · <?= e(money($bal)) ?> to pay</option><?php endforeach ?></optgroup><?php endif ?>
          <?php if ($choices['optional']): ?><optgroup label="Other open items"><?php foreach ($choices['optional'] as $i): ?><option value="i<?= (int) $i['id'] ?>" data-amount="<?= e(money_input($i['amount_minor'] !== null ? (int) $i['amount_minor'] : null)) ?>" data-part="<?= (int) $i['allow_part'] ?>"<?= $v('target') === 'i' . $i['id'] ? ' selected' : '' ?>><?= e($i['name']) ?></option><?php endforeach ?></optgroup><?php endif ?>
          <optgroup label="Something else"><option value="other"<?= $v('target') === 'other' ? ' selected' : '' ?>>Other (say what below)</option></optgroup>
        </select><?php if ($err('target')): ?><p class="error" id="rf-target-err"><?= e($err('target')) ?></p><?php endif ?></div>
      <div data-pay-other><?= field_html('other_name', 'If “Other”: what was it for?', 'text', $v('other_name'), $err('other_name'), ['max' => 120, 'prefix' => 'rf']) ?></div>
      <div class="field"><label for="rf-amount">Amount (<?= e(site_currency()) ?>)</label>
        <input type="text" id="rf-amount" name="amount" inputmode="decimal" autocomplete="off" value="<?= e($v('amount')) ?>" data-pay-amount data-decimals="<?= currency_decimals() ?>" aria-describedby="rf-amount-hint<?= $err('amount') ? ' rf-amount-err' : '' ?>"<?= $err('amount') ? ' aria-invalid="true"' : '' ?>>
        <p class="hint" id="rf-amount-hint" data-pay-amount-hint>For example <?= e(money_input(2500)) ?>.</p><?php if ($err('amount')): ?><p class="error" id="rf-amount-err"><?= e($err('amount')) ?></p><?php endif ?></div>
      <?= field_html('paid_on', 'Date paid', 'date', $v('paid_on'), $err('paid_on'), ['prefix' => 'rf']) ?>
      <?= field_html('method', 'Method', 'select', $v('method'), $err('method'), ['options' => PAYMENT_METHODS, 'prefix' => 'rf']) ?>
      <?= field_html('reference', 'Reference (optional)', 'text', $v('reference'), $err('reference'), ['max' => 120, 'prefix' => 'rf', 'hint' => 'Bank reference, cheque number or receipt book number.']) ?>
      <?= field_html('payer_name', 'Paid by (optional)', 'text', $v('payer_name'), $err('payer_name'), ['max' => 120, 'prefix' => 'rf', 'hint' => 'Only if someone else paid for the member.']) ?>
      <div class="field"><label for="rf-receipt">Receipt or proof (optional)</label><input type="file" id="rf-receipt" name="receipt" accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,.pdf" data-pay-receipt aria-describedby="rf-receipt-hint<?= $err('receipt') ? ' rf-receipt-err' : '' ?>"><p class="hint" id="rf-receipt-hint">Photo or PDF, up to 5 MB.</p><div class="pay-preview" data-pay-preview hidden></div><?php if ($err('receipt')): ?><p class="error" id="rf-receipt-err"><?= e($err('receipt')) ?></p><?php endif ?></div>
      <?= field_html('note', 'Note (optional)', 'textarea', $v('note'), $err('note'), ['rows' => 3, 'prefix' => 'rf']) ?>
      <?php if ($warnings): ?><?= field_html('confirm', 'I have checked: save this payment anyway', 'checkbox', '0', null, ['prefix' => 'rf']) ?><?php endif ?>
      <div class="form-actions"><button class="btn accent" type="submit"><?= icon('check') ?> Record payment</button></div>
    </div>
  </form>
<?php endif ?>
</div>
