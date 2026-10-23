<?php /** @var string $state @var ?array $member @var ?string $photo_url */
$labels = ['valid' => ['Valid member card', 'This card is genuine and the membership is current.', 'check'], 'expired' => ['Card expired', 'This card is genuine, but the membership has expired.', 'clock'], 'invalid' => ['Not a valid card', 'We could not match this card. It may have been replaced, cancelled, or the code is wrong.', 'alert']];
[$heading, $text, $ic] = $labels[$state]; ?>
<section class="section">
  <div class="verify-card state-<?= e($state) ?>">
    <div class="verify-status" role="status"><span class="verify-badge" aria-hidden="true"><?= icon($ic) ?></span><div><h1><?= e($heading) ?></h1><p><?= e($text) ?></p></div></div>
    <?php if ($member !== null): ?>
      <div class="verify-holder">
        <?php if ($photo_url): ?><img src="<?= e($photo_url) ?>" alt="Photo of the card holder" width="160" height="160"><?php endif ?>
        <div>
          <p class="muted">Card holder</p>
          <p class="verify-name"><?= e(member_full_name($member)) ?></p>
          <p class="tabular muted"><?= e($member['member_number']) ?><?php if ($member['card_valid_until']): ?> · <?= $state === 'expired' ? 'expired' : 'valid until' ?> <?= e((new DateTimeImmutable((string) $member['card_valid_until']))->format('j F Y')) ?><?php endif ?></p>
        </div>
      </div>
    <?php endif ?>
    <p class="verify-org">Checked by <?= e(setting('site.name')) ?> on <?= e((new DateTimeImmutable('now', site_tz()))->format('j F Y, H:i')) ?></p>
  </div>
</section>
