<?php /** @var array $p @var ?array $receipt @var array $timeline @var bool $can_edit @var bool $impersonating @var array $settings */
$isImage = $receipt !== null && $receipt['kind'] === 'image';
?>
<div class="pay">
  <p class="pay-back"><a href="/account/payments"><?= icon('chevron-left') ?> All payments</a></p>
  <?php if ($p['status'] === 'needs_info'): ?>
    <div class="notice warn" role="status"><p><strong><?= e($settings['reviewer_label']) ?> asked:</strong> <?= nl2br(e((string) $p['review_note'])) ?></p>
      <?php if (!$impersonating): ?><p><a class="btn small" href="/account/payments/<?= (int) $p['id'] ?>/edit">Answer by editing the payment</a></p><?php endif ?></div>
  <?php elseif ($p['status'] === 'rejected'): ?>
    <div class="notice warn" role="status"><p><strong>Not confirmed.</strong> Reason: <?= nl2br(e((string) $p['review_note'])) ?></p></div>
  <?php endif ?>
  <section class="dash-card">
    <div class="dash-card-head"><h2><?= e(payment_what($p)) ?></h2><?= view('payments/_status', ['p' => $p]) ?></div>
    <dl class="pay-facts">
      <div><dt>Amount</dt><dd class="tabular"><?= e(money((int) $p['amount_minor'], (string) $p['currency'])) ?></dd></div>
      <div><dt>Date paid</dt><dd><?= e((new DateTimeImmutable((string) $p['paid_on']))->format('j F Y')) ?></dd></div>
      <div><dt>Method</dt><dd><?= e(PAYMENT_METHODS[$p['method']] ?? $p['method']) ?></dd></div>
      <div><dt>Reference</dt><dd><?= $p['reference'] !== '' ? e($p['reference']) : '<span class="muted">none</span>' ?></dd></div>
      <?php if ($p['payer_name'] !== ''): ?><div><dt>Paid by</dt><dd><?= e($p['payer_name']) ?></dd></div><?php endif ?>
      <?php if ($p['receipt_number'] !== null): ?><div><dt>Receipt number</dt><dd class="tabular"><?= e($p['receipt_number']) ?></dd></div><?php endif ?>
      <?php if ((string) $p['note'] !== ''): ?><div class="wide"><dt>Note</dt><dd><?= nl2br(e((string) $p['note'])) ?></dd></div><?php endif ?>
      <?php if ($p['reversal_id'] !== null): ?><div class="wide"><dt>Reversed</dt><dd><?= e(fmt_date((string) $p['reversed_at'])) ?>: <?= e((string) $p['reversal_reason']) ?></dd></div><?php endif ?>
    </dl>
    <?php if ($receipt !== null): ?>
      <div class="pay-receipt-file">
        <h3 class="h-small">Your receipt or proof</h3>
        <?php if ($isImage): ?><a href="<?= e(media_original_url($receipt)) ?>" target="_blank" rel="noopener"><img src="/media/<?= (int) $receipt['id'] ?>/w/400" alt="Receipt you uploaded for this payment (opens full size)" width="200" loading="lazy"></a>
        <?php else: ?><a class="btn small ghost" href="<?= e(media_original_url($receipt)) ?>" target="_blank" rel="noopener"><?= icon('file') ?> <?= e((string) $receipt['original_name']) ?> (PDF)</a><?php endif ?>
      </div>
    <?php endif ?>
    <div class="btn-row">
      <?php if ($p['status'] === 'approved' && $p['entry_type'] === 'payment'): ?><a class="btn accent small" href="/account/payments/<?= (int) $p['id'] ?>/receipt"><?= icon('file') ?> View and print receipt</a><?php endif ?>
      <?php if (!$impersonating): ?>
        <?php if ($can_edit): ?><a class="btn small" href="/account/payments/<?= (int) $p['id'] ?>/edit"><?= icon('pen') ?> Edit</a>
          <form method="post" action="/account/payments/<?= (int) $p['id'] ?>/withdraw" data-confirm="Withdraw this payment report?"><?= csrf_field() ?><button class="btn small ghost" type="submit"><?= icon('trash') ?> Withdraw</button></form><?php endif ?>
        <?php if ($p['entry_type'] === 'payment' && $p['status'] !== 'withdrawn'): ?><a class="btn small ghost" href="/account/payments/new?from=<?= (int) $p['id'] ?>"><?= icon('plus') ?> Report another payment for the same transfer</a><?php endif ?>
      <?php endif ?>
    </div>
  </section>
  <section class="dash-card" aria-labelledby="tl-title">
    <h2 id="tl-title">History</h2>
    <ol class="pay-timeline">
      <?php foreach ($timeline as $t): ?>
        <li><time datetime="<?= e(iso_date($t['when'])) ?>"><?= e(fmt_date($t['when'], 'j M Y, H:i')) ?></time> <strong><?= e($t['what']) ?></strong> <span class="muted">by <?= e($t['who']) ?></span><?php if ($t['note'] !== ''): ?><br><span class="pay-note"><?= nl2br(e($t['note'])) ?></span><?php endif ?></li>
      <?php endforeach ?>
    </ol>
  </section>
</div>
