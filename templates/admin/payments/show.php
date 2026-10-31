<?php /** Review screen. @var array $p @var array $dups @var array $timeline @var ?array $receipt @var ?int $prev @var ?int $next @var ?string $separation @var ?int $reversal_of */
$waiting = in_array($p['status'], ['pending', 'needs_info'], true) && $p['entry_type'] === 'payment';
$reversed = $p['reversal_id'] !== null;
?>
<div class="pay-admin" data-pay-review>
<div class="page-head">
  <div>
    <p class="crumbs"><a href="/admin/payments">Payments</a> /</p>
    <h1><?= $p['entry_type'] === 'reversal' ? 'Reversal' : 'Payment' ?> #<?= (int) $p['id'] ?> <?= view('payments/_status', ['p' => $p]) ?></h1>
    <p><?= $p['member_id'] !== null ? '<a href="/admin/payments/member/' . (int) $p['member_id'] . '">' . e(payment_member_label($p)) . '</a>' : e(payment_member_label($p)) ?> <span class="muted tabular"><?= e((string) ($p['member_number'] ?? '')) ?></span></p>
  </div>
  <div class="actions">
    <?php if ($prev !== null): ?><a class="btn ghost" href="/admin/payments/<?= $prev ?>" data-pay-prev rel="prev"><?= icon('chevron-left') ?> Previous</a><?php endif ?>
    <?php if ($next !== null): ?><a class="btn ghost" href="/admin/payments/<?= $next ?>" data-pay-next rel="next">Next <?= icon('chevron-right') ?></a><?php endif ?>
  </div>
</div>
<?php if ($waiting): ?><p class="pay-kbd">Keyboard: <kbd>n</kbd> next, <kbd>p</kbd> previous, <kbd>a</kbd> approve button, <kbd>r</kbd> reason, <kbd>i</kbd> question.</p><?php endif ?>
<div class="pay-review-page">
  <section class="panel" aria-labelledby="pd-title">
    <div class="panel-head"><h2 id="pd-title">Details</h2></div>
    <div class="panel-body">
      <dl class="pay-facts">
        <div><dt>For</dt><dd><?= e(payment_what($p)) ?></dd></div>
        <div><dt>Amount</dt><dd class="tabular"><?= e(money((int) $p['amount_minor'], (string) $p['currency'])) ?></dd></div>
        <?php if ($p['charge_id'] !== null): ?><div><dt>Charge</dt><dd><?= view('admin/payments/_balance', ['p' => $p]) ?></dd></div><?php endif ?>
        <div><dt>Date paid</dt><dd><?= e((new DateTimeImmutable((string) $p['paid_on']))->format('j F Y')) ?></dd></div>
        <div><dt>Method</dt><dd><?= e(PAYMENT_METHODS[$p['method']] ?? $p['method']) ?></dd></div>
        <div><dt>Reference</dt><dd class="tabular"><?= $p['reference'] !== '' ? e($p['reference']) : '<span class="muted">none</span>' ?></dd></div>
        <?php if ($p['payer_name'] !== ''): ?><div><dt>Paid by</dt><dd><?= e($p['payer_name']) ?></dd></div><?php endif ?>
        <div><dt><?= $p['recorded_by'] !== null ? 'Recorded by' : 'Reported' ?></dt><dd><?= $p['recorded_by'] !== null ? e((string) $p['recorder_name']) . ', ' : '' ?><?= e(fmt_datetime((string) $p['created_at'])) ?></dd></div>
        <?php if ($p['receipt_number'] !== null): ?><div><dt>Receipt</dt><dd class="tabular"><a href="/admin/payments/<?= (int) $p['id'] ?>/receipt"><?= e($p['receipt_number']) ?></a></dd></div><?php endif ?>
        <?php if ($p['reviewed_at'] !== null): ?><div><dt>Reviewed</dt><dd><?= e((string) ($p['reviewer_name'] ?? '')) ?>, <?= e(fmt_datetime((string) $p['reviewed_at'])) ?></dd></div><?php endif ?>
        <?php if ((string) $p['note'] !== ''): ?><div class="wide"><dt>Member’s note</dt><dd><?= nl2br(e((string) $p['note'])) ?></dd></div><?php endif ?>
        <?php if ($p['review_note'] !== ''): ?><div class="wide"><dt>Review note</dt><dd><?= nl2br(e($p['review_note'])) ?></dd></div><?php endif ?>
        <?php if ($reversal_of !== null): ?><div class="wide"><dt>Reverses</dt><dd><a href="/admin/payments/<?= $reversal_of ?>">Payment #<?= $reversal_of ?></a></dd></div><?php endif ?>
        <?php if ($reversed): ?><div class="wide"><dt>Reversed by</dt><dd><a href="/admin/payments/<?= (int) $p['reversal_id'] ?>">Entry #<?= (int) $p['reversal_id'] ?></a>: <?= e((string) payment_find((int) $p['id'])['reversal_reason']) ?></dd></div><?php endif ?>
      </dl>
      <?= view('admin/payments/_dups', ['dups' => $dups]) ?>
    </div>
  </section>
  <section class="panel pay-receipt-large" aria-labelledby="pr-title">
    <div class="panel-head"><h2 id="pr-title">Receipt or proof</h2></div>
    <div class="panel-body">
      <?php if ($receipt === null): ?><p class="muted">No file was uploaded.</p>
      <?php elseif ($receipt['kind'] === 'image'): ?><a href="<?= e(media_original_url($receipt)) ?>" target="_blank" rel="noopener"><img src="/media/<?= (int) $receipt['id'] ?>/w/<?= isset($receipt['variants'][800]) ? 800 : (int) array_key_last($receipt['variants']) ?>" alt="Receipt uploaded for payment #<?= (int) $p['id'] ?> (opens full size)" loading="lazy"></a>
      <?php else: ?><p><a class="btn ghost" href="<?= e(media_original_url($receipt)) ?>" target="_blank" rel="noopener"><?= icon('file') ?> Open <?= e((string) $receipt['original_name']) ?> (PDF, new tab)</a></p><?php endif ?>
    </div>
  </section>
</div>
<?php if ($waiting): ?>
<section class="panel" aria-labelledby="act-title">
  <div class="panel-head"><h2 id="act-title">Review</h2></div>
  <div class="panel-body pay-review">
    <?php if ($separation !== null): ?><p class="notice-box"><?= e($separation) ?></p><?php else: ?>
    <form method="post" action="/admin/payments/<?= (int) $p['id'] ?>/approve" class="pay-inline-form"><?= csrf_field() ?>
      <div class="field"><label for="ap-note">Note (optional, kept on the record)</label><input type="text" id="ap-note" name="note" maxlength="1000"></div>
      <button class="btn accent" type="submit" data-pay-approve><?= icon('check') ?> Approve and send receipt</button></form>
    <?php endif ?>
    <form method="post" action="/admin/payments/<?= (int) $p['id'] ?>/reject" class="pay-inline-form"><?= csrf_field() ?>
      <div class="field"><label for="rj-preset">Reject: reason</label><select id="rj-preset" name="preset" data-pay-preset><option value="">Choose a reason or type one…</option><?php foreach (PAYMENT_REJECT_PRESETS as $r): ?><option><?= e($r) ?></option><?php endforeach ?></select></div>
      <div class="field"><label for="rj-reason">Reason in your own words</label><input type="text" id="rj-reason" name="reason" maxlength="1000" data-pay-reason></div>
      <button class="btn danger-ghost" type="submit">Reject</button></form>
    <form method="post" action="/admin/payments/<?= (int) $p['id'] ?>/info" class="pay-inline-form"><?= csrf_field() ?>
      <div class="field"><label for="in-q">Ask the member for more information</label><input type="text" id="in-q" name="question" maxlength="1000" data-pay-question></div>
      <button class="btn ghost" type="submit">Ask for info</button></form>
  </div>
</section>
<?php elseif ($p['status'] === 'approved' && $p['entry_type'] === 'payment' && !$reversed): ?>
<section class="panel" aria-labelledby="rv-title">
  <div class="panel-head"><h2 id="rv-title">Reverse this payment</h2></div>
  <div class="panel-body">
    <p class="hint">Approved payments are never edited or deleted. A reversal adds a linked negative entry (the balance goes back up and the receipt becomes invalid). Record a new payment afterwards if needed.</p>
    <form method="post" action="/admin/payments/<?= (int) $p['id'] ?>/reverse" class="pay-inline-form" data-confirm="Reverse this payment? This cannot be undone."><?= csrf_field() ?>
      <div class="field"><label for="rv-preset">Reason</label><select id="rv-preset" name="preset" data-pay-preset><option value="">Choose…</option><option>Bounced transfer</option><option>Entered twice</option><option>Refunded</option><option>Wrong member or item</option></select></div>
      <div class="field"><label for="rv-reason">Or in your own words</label><input type="text" id="rv-reason" name="reason" maxlength="1000" data-pay-reason></div>
      <button class="btn danger-ghost" type="submit">Reverse</button></form>
  </div>
</section>
<?php endif ?>
<section class="panel" aria-labelledby="tl-title">
  <div class="panel-head"><h2 id="tl-title">Timeline</h2></div>
  <div class="panel-body">
    <ol class="pay-timeline">
      <?php foreach ($timeline as $t): ?><li><time datetime="<?= e(iso_date($t['when'])) ?>"><?= e(fmt_date($t['when'], 'j M Y, H:i')) ?></time> <strong><?= e($t['what']) ?></strong> <span class="muted">by <?= e($t['who']) ?></span><?php if ($t['note'] !== ''): ?><br><span class="pay-note"><?= nl2br(e($t['note'])) ?></span><?php endif ?></li><?php endforeach ?>
    </ol>
  </div>
</section>
</div>
