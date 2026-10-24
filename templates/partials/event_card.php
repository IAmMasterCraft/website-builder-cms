<?php /** @var array $event @var ?string $headingTag */
$h = $headingTag ?? 'h3';
$start = utc_to_local($event['start_at']);
$isPast = event_end_at($event) < utc_now();
$cover = media_find($event['cover_media_id'] ? (int) $event['cover_media_id'] : null); ?>
<article class="card event-card<?= $isPast ? ' past' : '' ?>" data-reveal>
  <div class="card-media<?= $cover ? '' : ' no-image' ?>">
    <?= $cover ? img_html($cover, '(min-width: 72rem) 24rem, (min-width: 40rem) 45vw, 100vw', '', '', true, 800) : icon('calendar') ?>
    <span class="date-badge" aria-hidden="true"><span class="m"><?= e($start?->format('M')) ?></span><span class="d"><?= e($start?->format('j')) ?></span></span>
    <?php if ($isPast): ?><span class="badge glass album-count">Past event</span><?php elseif ((int) $event['registration_open'] === 1): ?><span class="badge glass album-count"><?= icon('ticket') ?> Registration open</span><?php endif ?>
  </div>
  <div class="card-body">
    <div class="card-meta">
      <span><?= icon('clock') ?><time datetime="<?= e(iso_date($event['start_at'])) ?>"><?= e(fmt_datetime($event['start_at'])) ?></time></span>
      <?php if ($event['visibility'] === 'members'): ?><span class="lock"><?= icon('lock') ?> <?= e(t('members.only')) ?></span><?php endif ?>
    </div>
    <<?= $h ?>><a href="<?= e(event_url($event)) ?>"><?= e($event['title']) ?></a></<?= $h ?>>
    <div class="card-foot">
      <span class="card-meta"><?php if (!empty($event['venue_name'])): ?><span><?= icon('pin') ?><?= e($event['venue_name']) ?></span><?php elseif (!empty($event['online_url'])): ?><span><?= icon('globe') ?>Online</span><?php endif ?></span>
      <span class="card-go" aria-hidden="true"><?= icon('arrow-right') ?></span>
    </div>
  </div>
</article>
