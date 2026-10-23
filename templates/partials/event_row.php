<?php
/** @var array $event */
$start = utc_to_local($event['start_at']);
$isPast = event_end_at($event) < utc_now();
?>
<li class="event-row<?= $isPast ? ' past' : '' ?>">
  <div class="date-tile" aria-hidden="true"><span class="m"><?= e($start?->format('M')) ?></span><span class="d"><?= e($start?->format('j')) ?></span></div>
  <div>
    <h3><a href="<?= e(event_url($event)) ?>"><?= e($event['title']) ?></a></h3>
    <div class="card-meta">
      <span><?= icon('clock') ?><time datetime="<?= e(iso_date($event['start_at'])) ?>"><?= e(fmt_datetime($event['start_at'])) ?></time></span>
      <?php if (!empty($event['venue_name'])): ?><span><?= icon('pin') ?><?= e($event['venue_name']) ?></span><?php elseif (!empty($event['online_url'])): ?><span><?= icon('globe') ?>Online</span><?php endif ?>
      <?php if ($isPast): ?><span class="badge muted">Past event</span><?php elseif ((int) $event['registration_open'] === 1): ?><span class="badge">Registration open</span><?php endif ?>
      <?php if ($event['visibility'] === 'members'): ?><span class="lock"><?= icon('lock') ?> <?= e(t('members.only')) ?></span><?php endif ?>
    </div>
  </div>
</li>
