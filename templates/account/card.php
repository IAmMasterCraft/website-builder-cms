<?php /** @var array $card @var array $member */ ?>
<p class="lede">Show this card at events and venues. Anyone can scan the QR code on the back to check that it is valid.</p>
<?php if ($card['expired']): ?><p class="notice warn">This card expired on <?= e((new DateTimeImmutable($card['valid_until_iso']))->format('j F Y')) ?>. Please contact us to renew.</p><?php endif ?>
<div class="card-pair side-by-side">
  <figure class="card-figure"><?= view('partials/id_card', ['card' => $card, 'side' => 'front']) ?><figcaption class="muted">Front</figcaption></figure>
  <figure class="card-figure"><?= view('partials/id_card', ['card' => $card, 'side' => 'back']) ?><figcaption class="muted">Back</figcaption></figure>
</div>
<div class="card-actions">
  <button type="button" class="btn accent" data-card-png="<?= e(json_attr($card)) ?>" hidden><?= icon('download') ?> Download as image (PNG)</button>
  <a class="btn" href="/account/card/print" target="_blank" rel="noopener"><?= icon('file') ?> Print or save as PDF</a>
  <?php if ($card['verify_url'] !== ''): ?><a class="btn ghost" href="<?= e(parse_url($card['verify_url'], PHP_URL_PATH) ?: '/') ?>" target="_blank" rel="noopener"><?= icon('check') ?> See what the QR code shows</a><?php endif ?>
</div>
<p class="hint muted">The print page is sized for a standard ID card (85.6 × 54 mm), front and back on separate pages.</p>
<script type="module" src="<?= e(asset('assets/js/card.js')) ?>"></script>
