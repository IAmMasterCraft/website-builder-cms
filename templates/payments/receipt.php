<?php /** Printable receipt (print layout). @var array $p @var string $site_name @var ?array $logo @var string $back */ ?>
<main class="receipt-page">
  <p class="receipt-tools no-print"><a class="btn ghost small" href="<?= e($back) ?>"><?= icon('chevron-left') ?> Back</a> <button type="button" class="btn accent small" data-print><?= icon('file') ?> Print or save as PDF</button></p>
  <article class="receipt<?= $reversed ? ' is-reversed' : '' ?>" aria-labelledby="rcp-title">
    <header class="receipt-head">
      <div class="receipt-org">
        <?php if ($logo !== null): ?><?= img_html($logo, '120px', $site_name . ' logo', 'receipt-logo', false, 400) ?><?php endif ?>
        <div><p class="receipt-org-name"><?= e($site_name) ?></p>
          <?php if ($address !== ''): ?><p class="receipt-small"><?= nl2br(e($address)) ?></p><?php endif ?>
          <?php if ($charity_number !== ''): ?><p class="receipt-small">Registered charity <?= e($charity_number) ?></p><?php endif ?>
          <?php if ($contact_email !== ''): ?><p class="receipt-small"><?= e($contact_email) ?></p><?php endif ?></div>
      </div>
      <div class="receipt-no"><h1 id="rcp-title">Receipt</h1><p class="tabular"><?= e((string) $p['receipt_number']) ?></p></div>
    </header>
    <?php if ($reversed): ?><p class="receipt-void" role="status">This payment was reversed on <?= e(fmt_date((string) $p['reversed_at'])) ?>. This receipt is no longer valid.</p><?php endif ?>
    <dl class="receipt-lines">
      <div><dt>Received from</dt><dd><?= e($member_name) ?><?= $member_number !== '' ? ' <span class="tabular">(' . e($member_number) . ')</span>' : '' ?><?= $p['payer_name'] !== '' ? '<br><span class="receipt-small">Paid by ' . e($p['payer_name']) . '</span>' : '' ?></dd></div>
      <div><dt>For</dt><dd><?= e($what) ?></dd></div>
      <div class="receipt-amount"><dt>Amount</dt><dd><strong class="tabular"><?= e($amount) ?></strong><br><span class="receipt-small"><?= e($amount_words) ?></span></dd></div>
      <div><dt>Date paid</dt><dd><?= e($paid_on) ?></dd></div>
      <div><dt>Method</dt><dd><?= e($method) ?></dd></div>
      <div><dt>Reference</dt><dd><?= $p['reference'] !== '' ? e($p['reference']) : '—' ?></dd></div>
      <div><dt>Confirmed on</dt><dd><?= e($confirmed) ?></dd></div>
    </dl>
    <footer class="receipt-foot">
      <div class="receipt-qr" data-qr="<?= e($verify_url) ?>"><span class="receipt-small"><?= e($verify_url) ?></span></div>
      <p class="receipt-small">Scan the code or open the link to check this receipt: <span class="receipt-url"><?= e($verify_url) ?></span>. Thank you for your support.</p>
    </footer>
  </article>
</main>
