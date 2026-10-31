<?php /** @var array $values @var array $errors @var bool $locked */
$v = static fn (string $k): string => (string) ($values[$k] ?? '');
$err = static fn (string $k): ?string => $errors[$k] ?? null;
?>
<div class="page-head"><div><h1>Payment settings</h1><p>Currency, receipts, reminders and how payments appear to members. Payments are report-only: members pay by bank transfer, cash and so on, then report it; there is no online card payment.</p></div></div>
<form method="post" action="/admin/settings/payments" novalidate>
  <?= csrf_field() ?>
  <?= form_errors_html($errors) ?>
  <div class="form-layout">
    <section class="panel"><div class="panel-body fields">
      <div class="field"><label for="ps-currency">Currency</label>
        <select id="ps-currency" name="currency" aria-describedby="ps-currency-hint"<?= $locked ? ' disabled' : '' ?>><?php foreach (currency_options() as $code => $label): ?><option value="<?= e($code) ?>"<?= $v('currency') === $code ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select>
        <p class="hint" id="ps-currency-hint"><?= $locked ? 'Locked: payment items or payments already use this currency.' : 'One currency for the whole site. It cannot change once the first payment item is created.' ?></p></div>
      <div class="field"><label for="ps-bank">Bank details shown on “Report a payment”</label>
        <div class="rt" data-rt>
          <div class="rt-editor" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="ps-bank-lbl" hidden><?= \Core\Sanitizer::clean($v('bank_details')) ?></div>
          <textarea id="ps-bank" name="bank_details" data-richtext rows="6" aria-describedby="ps-bank-hint"><?= e($v('bank_details')) ?></textarea>
        </div><span id="ps-bank-lbl" class="sr-only">Bank details editor</span>
        <p class="hint" id="ps-bank-hint">Account name, number, bank, and what to use as the reference. Also used in reminder emails.</p></div>
      <?= field_html('receipt_required', 'Members must upload a receipt or proof with each report', 'checkbox', $v('receipt_required'), null, ['hint' => 'Admins recording a payment never have to.']) ?>
      <?= field_html('separation', 'Separation of duties: nobody approves a payment they recorded themselves', 'checkbox', $v('separation'), null, ['hint' => 'Payments recorded by an admin then wait for another admin. Off by default (small teams).']) ?>
      <?= field_html('notify_received', 'Email members when their report is received', 'checkbox', $v('notify_received'), null, ['hint' => 'They are always emailed when it is approved, rejected or needs more information.']) ?>
    </div></section>
    <div class="side">
      <section class="panel"><div class="panel-body fields">
        <?= field_html('reviewer_label', 'Members see reviewers as', 'text', $v('reviewer_label'), $err('reviewer_label'), ['max' => 40, 'hint' => 'Admins’ names are never shown to members. For example Treasurer or Admin.']) ?>
        <?= field_html('remind_before', 'Default reminder: days before the due date', 'number', $v('remind_before'), $err('remind_before'), ['min' => 0, 'max' => 90]) ?>
        <?= field_html('remind_after', 'Default reminder: days after the due date', 'number', $v('remind_after'), $err('remind_after'), ['min' => 0, 'max' => 180]) ?>
        <?= field_html('retention_years', 'Keep receipts of deleted members for (years)', 'number', $v('retention_years'), $err('retention_years'), ['min' => 1, 'max' => 30, 'hint' => 'Payment records are kept (anonymised); the uploaded receipt files are deleted after this many years.']) ?>
      </div></section>
      <div class="panel panel-body"><button class="btn accent" type="submit"><?= icon('check') ?> Save settings</button></div>
    </div>
  </div>
</form>
