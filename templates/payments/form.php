<?php
/** Report a payment / edit a waiting report.
 * @var array $values @var array $errors @var array $warnings @var array $choices @var ?array $record @var ?array $from_record @var ?array $held_receipt @var array $settings */
$v = static fn (string $k): string => (string) ($values[$k] ?? '');
$err = static fn (string $k): ?string => $errors[$k] ?? null;
$action = $record !== null ? '/account/payments/' . (int) $record['id'] : '/account/payments';
$symbol = currency_info()[1];
$sameReceipt = $from_record !== null && $from_record['receipt_media_id'] !== null;
$fieldErr = static function (string $id, ?string $e): string { return $e !== null ? '<p class="error" id="' . e($id) . '-err">' . e($e) . '</p>' : ''; };
$described = static function (string $id, ?string $e, bool $hint = false): string {
    $d = trim(($hint ? $id . '-hint ' : '') . ($e !== null ? $id . '-err' : ''));
    return ($d !== '' ? ' aria-describedby="' . e($d) . '"' : '') . ($e !== null ? ' aria-invalid="true"' : '');
};
?>
<div class="pay">
  <p class="pay-back"><a href="<?= $record !== null ? '/account/payments/' . (int) $record['id'] : '/account/payments' ?>"><?= icon('chevron-left') ?> Back</a></p>
  <?php if ($record !== null && $record['status'] === 'needs_info'): ?>
    <div class="notice warn" role="status"><p><strong><?= e($settings['reviewer_label']) ?> asked:</strong> <?= nl2br(e((string) $record['review_note'])) ?></p><p>Change what is needed below (for example add the reference or a clearer receipt) and press <strong>Send answer</strong>.</p></div>
  <?php endif ?>
  <?php if ($settings['bank_details'] !== '' && $record === null): ?>
    <section class="dash-card pay-bank" aria-labelledby="bank-title"><h2 id="bank-title">How to pay</h2><div class="prose"><?= rich($settings['bank_details']) ?></div></section>
  <?php endif ?>
  <section class="dash-card">
    <?= $errors ? '<div class="flash error" role="alert">' . icon('alert') . '<span>Please check the highlighted fields.</span></div>' : '' ?>
    <?php if ($warnings): ?>
      <div class="notice warn pay-warnings" role="alert">
        <p><strong>Please check before saving:</strong></p>
        <ul><?php foreach ($warnings as $w): ?><li><?= e($w) ?></li><?php endforeach ?></ul>
      </div>
    <?php endif ?>
    <form class="form pay-form" method="post" action="<?= e($action) ?>" enctype="multipart/form-data" novalidate data-pay-form>
      <?= csrf_field() ?>
      <?php if ($from_record !== null): ?><input type="hidden" name="from" value="<?= (int) $from_record['id'] ?>"><?php endif ?>
      <?php if ($held_receipt !== null): ?><input type="hidden" name="held_receipt" value="<?= e($held_receipt['token']) ?>"><?php endif ?>

      <div class="field">
        <label for="pf-target">What did you pay for?</label>
        <select id="pf-target" name="target" required<?= $described('pf-target', $err('target')) ?> data-pay-target>
          <option value="">Choose…</option>
          <?php if ($choices['charges']): ?><optgroup label="What you owe">
            <?php foreach ($choices['charges'] as $c): $bal = payment_charge_balance($c); ?>
              <option value="c<?= (int) $c['id'] ?>" data-amount="<?= e(money_input($bal ?: null)) ?>" data-balance="<?= e(money($bal)) ?>" data-part="<?= (int) $c['allow_part'] ?>"<?= $v('target') === 'c' . $c['id'] ? ' selected' : '' ?>><?= e($c['item_name']) ?> · <?= e(money($bal)) ?> to pay<?= $c['due_date'] !== null ? ' (due ' . e((new DateTimeImmutable((string) $c['due_date']))->format('j M')) . ')' : '' ?></option>
            <?php endforeach ?>
          </optgroup><?php endif ?>
          <?php if ($choices['optional']): ?><optgroup label="Optional">
            <?php foreach ($choices['optional'] as $i): ?>
              <option value="i<?= (int) $i['id'] ?>" data-amount="<?= e(money_input($i['amount_minor'] !== null ? (int) $i['amount_minor'] : null)) ?>" data-part="<?= (int) $i['allow_part'] ?>"<?= $v('target') === 'i' . $i['id'] ? ' selected' : '' ?>><?= e($i['name']) ?><?= $i['amount_minor'] !== null ? ' · ' . e(money((int) $i['amount_minor'])) : ' · any amount' ?></option>
            <?php endforeach ?>
          </optgroup><?php endif ?>
          <optgroup label="Something else"><option value="other"<?= $v('target') === 'other' ? ' selected' : '' ?>>Other (say what below)</option></optgroup>
        </select>
        <?= $fieldErr('pf-target', $err('target')) ?>
      </div>
      <div class="field" data-pay-other>
        <label for="pf-other">If “Other”: what was it for?</label>
        <input type="text" id="pf-other" name="other_name" maxlength="120" value="<?= e($v('other_name')) ?>"<?= $described('pf-other', $err('other_name')) ?>>
        <?= $fieldErr('pf-other', $err('other_name')) ?>
      </div>
      <div class="pay-grid">
        <div class="field">
          <label for="pf-amount">Amount (<?= e(site_currency()) ?>)</label>
          <div class="pay-money"><span aria-hidden="true"><?= e($symbol) ?></span><input type="text" id="pf-amount" name="amount" inputmode="decimal" autocomplete="off" required value="<?= e($v('amount')) ?>"<?= $described('pf-amount', $err('amount'), true) ?> data-pay-amount data-decimals="<?= currency_decimals() ?>"></div>
          <p class="hint" id="pf-amount-hint" data-pay-amount-hint>The amount you paid. Part payments are fine unless the item says otherwise.</p>
          <?= $fieldErr('pf-amount', $err('amount')) ?>
        </div>
        <div class="field">
          <label for="pf-date">Date paid</label>
          <input type="date" id="pf-date" name="paid_on" required max="<?= e(payment_today()) ?>" min="2000-01-01" value="<?= e($v('paid_on')) ?>"<?= $described('pf-date', $err('paid_on')) ?>>
          <?= $fieldErr('pf-date', $err('paid_on')) ?>
        </div>
        <div class="field">
          <label for="pf-method">How did you pay?</label>
          <select id="pf-method" name="method" required<?= $described('pf-method', $err('method')) ?>><?php foreach (PAYMENT_METHODS as $k => $l): ?><option value="<?= e($k) ?>"<?= $v('method') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select>
          <?= $fieldErr('pf-method', $err('method')) ?>
        </div>
        <div class="field">
          <label for="pf-ref">Bank or transaction reference <span class="muted">(recommended)</span></label>
          <input type="text" id="pf-ref" name="reference" maxlength="120" autocomplete="off" value="<?= e($v('reference')) ?>"<?= $described('pf-ref', $err('reference'), true) ?>>
          <p class="hint" id="pf-ref-hint">From your bank app or the teller slip. It helps us find the payment.</p>
          <?= $fieldErr('pf-ref', $err('reference')) ?>
        </div>
      </div>
      <div class="field">
        <label for="pf-payer">Paid by <span class="muted">(optional)</span></label>
        <input type="text" id="pf-payer" name="payer_name" maxlength="120" value="<?= e($v('payer_name')) ?>"<?= $described('pf-payer', $err('payer_name'), true) ?>>
        <p class="hint" id="pf-payer-hint">Only if someone else paid for you, e.g. your spouse. Use the name on the bank account.</p>
        <?= $fieldErr('pf-payer', $err('payer_name')) ?>
      </div>
      <div class="field">
        <label for="pf-receipt">Receipt or proof of payment<?= $settings['receipt_required'] && $record === null && !$sameReceipt && $held_receipt === null ? '' : ' <span class="muted">(optional)</span>' ?></label>
        <?php if ($held_receipt !== null): ?><p class="hint">Your file <strong><?= e((string) $held_receipt['original_name']) ?></strong> is kept. Choose another file only to replace it.</p><?php endif ?>
        <?php if ($sameReceipt): ?><p class="hint">The receipt from payment #<?= (int) $from_record['id'] ?> is used for this payment too (same transfer). Choose a file only if this payment has its own.</p><?php endif ?>
        <?php if ($record !== null && !empty($receipt)): ?><p class="hint">Current file: <a href="<?= e(media_original_url($receipt)) ?>" target="_blank" rel="noopener"><?= e((string) $receipt['original_name']) ?></a>. Choose a file only to replace it.</p><?php endif ?>
        <input type="file" id="pf-receipt" name="receipt" accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,.pdf"<?= $described('pf-receipt', $err('receipt'), true) ?> data-pay-receipt>
        <p class="hint" id="pf-receipt-hint">A photo or screenshot (JPG, PNG, WebP) or a PDF, up to 5 MB. Only you and the organisation’s payment team can see it.</p>
        <div class="pay-preview" data-pay-preview hidden></div>
        <?= $fieldErr('pf-receipt', $err('receipt')) ?>
      </div>
      <div class="field">
        <label for="pf-note">Note <span class="muted">(optional)</span></label>
        <textarea id="pf-note" name="note" rows="3" maxlength="2000"<?= $described('pf-note', $err('note')) ?>><?= e($v('note')) ?></textarea>
        <?= $fieldErr('pf-note', $err('note')) ?>
      </div>
      <?php if ($warnings): ?>
        <div class="field"><label class="check-line"><input type="checkbox" name="confirm" value="1"> <span><strong>I have checked: save this payment anyway</strong></span></label></div>
      <?php endif ?>
      <p class="btn-row"><button class="btn accent" type="submit"><?= icon('check') ?> <?= $record === null ? 'Send payment report' : ($record['status'] === 'needs_info' ? 'Send answer' : 'Save changes') ?></button></p>
    </form>
  </section>
</div>
