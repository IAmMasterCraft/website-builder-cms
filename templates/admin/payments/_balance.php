<?php /** Amount vs balance. @var array $p */
if ($p['charge_id'] !== null):
    $bal = (int) $p['charge_waived'] === 1 ? 0 : max(0, (int) $p['charge_amount'] - (int) $p['charge_paid']); ?>
  · <span class="muted">balance <?= e(money($bal)) ?> of <?= e(money((int) $p['charge_amount'])) ?></span><?php if ($p['status'] === 'pending' && (int) $p['amount_minor'] > $bal): ?> <span class="pay-row-warn"><?= icon('warning') ?> more than the balance</span><?php endif ?>
<?php endif;
