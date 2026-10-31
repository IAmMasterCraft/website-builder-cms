<?php /** Public receipt check: valid or not, organisation, amount, date, initials only. @var ?array $row @var bool $valid @var bool $limited */
[$heading, $text, $ic, $state] = match (true) {
    $limited => ['Too many checks', 'Please try again in a few minutes.', 'clock', 'expired'],
    $valid => ['Valid receipt', 'This receipt was issued by ' . setting('site.name') . ' and the payment is confirmed.', 'check', 'valid'],
    $row !== null => ['Not a valid receipt', 'This receipt was cancelled: the payment was reversed.', 'alert', 'invalid'],
    default => ['Not a valid receipt', 'We could not find a receipt for this code. Please check it, or contact ' . setting('site.name') . '.', 'alert', 'invalid'],
}; ?>
<section class="section">
  <div class="verify-card state-<?= e($state) ?>">
    <div class="verify-status" role="status"><span class="verify-badge" aria-hidden="true"><?= icon($ic) ?></span><div><h1><?= e($heading) ?></h1><p><?= e($text) ?></p></div></div>
    <?php if ($row !== null && !$limited): ?>
      <dl class="receipt-check-facts">
        <div><dt>Receipt</dt><dd class="tabular"><?= e((string) $row['receipt_number']) ?></dd></div>
        <div><dt>Amount</dt><dd class="tabular"><?= e(money((int) $row['amount_minor'], (string) $row['currency'])) ?></dd></div>
        <div><dt>Date paid</dt><dd><?= e((new DateTimeImmutable((string) $row['paid_on']))->format('j F Y')) ?></dd></div>
        <div><dt>Paid by</dt><dd><?= e(payment_initials($row)) ?></dd></div>
      </dl>
    <?php endif ?>
    <p class="verify-org">Checked by <?= e(setting('site.name')) ?> on <?= e((new DateTimeImmutable('now', site_tz()))->format('j F Y, H:i')) ?></p>
  </div>
</section>
