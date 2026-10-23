<?php /** @var array $card @var string $side front|back|both */
$side ??= 'both'; ?>
<?php if ($side !== 'back'): ?>
<div class="id-card id-front<?= $card['background'] ? ' has-bg' : '' ?><?= $card['expired'] ? ' is-expired' : '' ?>">
  <?php if ($card['background']): ?><img class="card-bg" src="<?= e($card['background']) ?>" alt=""><?php endif ?>
  <div class="card-top">
    <?php if ($card['logo']): ?><img class="card-logo" src="<?= e($card['logo']) ?>" alt=""><?php else: ?><span class="card-mark" aria-hidden="true"><?= e($card['initial']) ?></span><?php endif ?>
    <span class="card-org"><?= e($card['org']) ?></span>
    <span class="card-kind">Member</span>
  </div>
  <div class="card-main">
    <div class="card-photo"><?php if ($card['photo']): ?><img src="<?= e($card['photo']) ?>" alt="Photo of <?= e($card['name']) ?>"><?php else: ?><span aria-hidden="true"><?= e(mb_substr($card['name'], 0, 1)) ?></span><?php endif ?></div>
    <div class="card-info">
      <p class="card-name"><?= e($card['name']) ?></p>
      <dl>
        <div><dt>Member no.</dt><dd class="card-number"><?= e($card['number'] !== '' ? $card['number'] : 'Pending') ?></dd></div>
        <div><dt>Type</dt><dd><?= e($card['type']) ?></dd></div>
        <div><dt>Valid until</dt><dd><?= e($card['valid_until']) ?><?= $card['expired'] ? ' (expired)' : '' ?></dd></div>
        <?php foreach (array_slice($card['extra'], 0, 2) as $x): ?><div><dt><?= e($x['label']) ?></dt><dd><?= e($x['value']) ?></dd></div><?php endforeach ?>
      </dl>
    </div>
  </div>
</div>
<?php endif ?>
<?php if ($side !== 'front'): ?>
<div class="id-card id-back">
  <div class="back-main">
    <div class="back-text">
      <p class="card-org"><?= e($card['org']) ?></p>
      <ul><?php foreach ($card['contact'] as $line): ?><li><?= e($line) ?></li><?php endforeach ?></ul>
      <?php if ($card['charity'] !== ''): ?><p class="back-small">Registered charity <?= e($card['charity']) ?></p><?php endif ?>
      <p class="back-small">This card belongs to <?= e($card['org']) ?>. If found, please return it to us.</p>
    </div>
    <div class="back-qr">
      <?php if ($card['verify_url'] !== ''): ?>
        <div class="qr" data-qr="<?= e($card['verify_url']) ?>" role="img" aria-label="QR code to check this card"></div>
        <span class="back-small">Scan to check</span>
      <?php endif ?>
    </div>
  </div>
  <div class="back-sign">
    <?php if ($card['signature']): ?><img class="card-signature" src="<?= e($card['signature']) ?>" alt="Signature"><?php else: ?><span class="sig-line" aria-hidden="true"></span><?php endif ?>
    <span>Authorised signature · <?= e($card['signatory']) ?></span>
  </div>
</div>
<?php endif ?>
