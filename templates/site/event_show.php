<?php
/** @var array $event @var bool $locked @var ?string $block @var array $old @var array $errors @var bool $is_past */
$start = utc_to_local($event['start_at']);
$end = utc_to_local($event['end_at']);
$sameDay = $end !== null && $start->format('Y-m-d') === $end->format('Y-m-d');
$left = $event['capacity'] !== null ? max(0, (int) $event['capacity'] - (int) $event['registered_count']) : null;
$err = static fn (string $k): string => isset($errors[$k]) ? '<p class="error" id="err-' . e($k) . '">' . e($errors[$k]) . '</p>' : '';
$inv = static fn (string $k): string => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="err-' . e($k) . '"' : '';
?>
<article>
  <div class="page-hero on-dark<?= $cover ? ' has-image' : '' ?>">
    <?php if ($cover): preload_image($cover, '100vw'); ?><?= img_html($cover, '100vw', '', 'page-hero-img', false, 1600) ?><?php endif ?>
    <div class="container">
      <nav class="breadcrumbs" aria-label="Breadcrumb"><ol><li><a href="/">Home</a></li><li><a href="/events">Events</a></li><li><span aria-current="page"><?= e($event['title']) ?></span></li></ol></nav>
      <?php if ($event['status'] !== 'published' && !($event['status'] === 'scheduled' && $event['publish_at'] <= utc_now())): ?><p class="notice warn">Preview: this event is not published yet.</p><?php endif ?>
      <span class="eyebrow"><?= e($start->format('l j F Y')) ?><?= $event['venue_name'] ? ' · ' . e($event['venue_name']) : '' ?></span>
      <h1><?= e($event['title']) ?></h1>
      <?php if ($event['summary']): ?><p><?= e($event['summary']) ?></p><?php endif ?>
      <?php if (!$locked && (int) $event['registration_open'] === 1 && !$is_past): ?><p class="hero-actions"><a class="btn highlight large" href="#register">Register now <?= icon('arrow-right') ?></a><a class="btn on-dark large" href="/events/<?= e($event['slug']) ?>.ics"><?= icon('calendar') ?> Add to calendar</a></p><?php endif ?>
    </div>
  </div>
  <div class="section">
    <div class="container layout-with-aside">
      <div>
        <?php if ($locked): ?>
          <div class="notice"><p><strong><?= e(t('members.only')) ?>.</strong> <?= e(t('members.login_to_view')) ?></p><a class="btn" href="/login?next=<?= e(rawurlencode(event_url($event))) ?>">Log in</a></div>
        <?php else: ?>
          <div class="prose"><?= rich($event['body']) ?></div>
          <?= view('site/share', ['url' => $share_url, 'text' => $event['title']]) ?>
        <?php endif ?>
      </div>
      <div class="side-col">
        <div class="aside-box">
          <?php if (!$is_past): ?>
            <div class="countdown" data-countdown="<?= e(iso_date($event['start_at'])) ?>" aria-label="Time until the event starts">
              <?php $diff = max(0, strtotime($event['start_at'] . ' UTC') - time()); ?>
              <div><strong data-unit="d"><?= intdiv($diff, 86400) ?></strong><span>days</span></div>
              <div><strong data-unit="h"><?= intdiv($diff % 86400, 3600) ?></strong><span>hrs</span></div>
              <div><strong data-unit="m"><?= intdiv($diff % 3600, 60) ?></strong><span>min</span></div>
              <div><strong data-unit="s"><?= $diff % 60 ?></strong><span>sec</span></div>
            </div>
          <?php else: ?>
            <p class="badge muted">This event has finished</p>
          <?php endif ?>
          <ul class="event-facts">
            <li><?= icon('calendar') ?><div><strong>Date</strong><time datetime="<?= e(iso_date($event['start_at'])) ?>"><?= e($start->format('l j F Y')) ?></time><?php if ($end && !$sameDay): ?> – <?= e($end->format('l j F Y')) ?><?php endif ?></div></li>
            <li><?= icon('clock') ?><div><strong>Time</strong><?= e($start->format('H:i')) ?><?= $end ? ' – ' . e($end->format('H:i')) : '' ?> <span class="muted">(<?= e(site_tz()->getName()) ?>)</span></div></li>
            <?php if ($event['venue_name'] || $event['venue_address']): ?>
              <li><?= icon('pin') ?><div><strong>Venue</strong><?= e($event['venue_name']) ?><?php if ($event['venue_address']): ?><br><span class="muted"><?= e($event['venue_address']) ?></span><?php endif ?>
                <?php if ($event['map_url']): ?><br><a href="<?= e($event['map_url']) ?>" target="_blank" rel="noopener">Open map<span class="sr-only"> (opens in a new tab)</span></a><?php endif ?></div></li>
            <?php endif ?>
            <?php if ($event['online_url'] && !$locked): ?><li><?= icon('globe') ?><div><strong>Online</strong><a href="<?= e($event['online_url']) ?>" target="_blank" rel="noopener">Join online<span class="sr-only"> (opens in a new tab)</span></a></div></li><?php endif ?>
            <?php if ($event['ticket_rows']): ?><li><?= icon('ticket') ?><div><strong>Tickets</strong><?php foreach ($event['ticket_rows'] as $tk): ?><?= e($tk['name']) ?><?= $tk['price_label'] !== '' ? ' · ' . e($tk['price_label']) : '' ?><br><?php endforeach ?></div></li><?php endif ?>
          </ul>
          <p><a class="btn ghost small" href="/events/<?= e($event['slug']) ?>.ics"><?= icon('calendar') ?> Add to calendar</a></p>
        </div>

        <?php if (!$locked && (int) $event['registration_open'] === 1): ?>
        <div class="aside-box" id="register">
          <h2>Register</h2>
          <?php if ($left !== null): ?>
            <p class="muted tabular"><?= e(pluralise($left, 'place')) ?> left of <?= (int) $event['capacity'] ?></p>
            <meter class="capacity" min="0" max="<?= (int) $event['capacity'] ?>" value="<?= (int) $event['registered_count'] ?>" aria-label="Places taken"><?= (int) $event['registered_count'] ?> of <?= (int) $event['capacity'] ?> taken</meter>
          <?php endif ?>
          <?php if (isset($errors['form'])): ?><p class="flash error" role="alert"><?= e($errors['form']) ?></p><?php endif ?>
          <?php if ($block !== null): ?>
            <p class="notice"><?= e($block) ?></p>
            <?php if ($viewer === null && (int) $event['guest_registration'] !== 1 && !$is_past): ?><a class="btn" href="/login?next=<?= e(rawurlencode(event_url($event))) ?>">Log in to register</a><?php endif ?>
          <?php else: ?>
            <form class="form" method="post" action="/events/<?= e($event['slug']) ?>/register" novalidate>
              <?= csrf_field() ?>
              <div class="hp" aria-hidden="true"><label for="website">Leave this empty</label><input type="text" id="website" name="website" tabindex="-1" autocomplete="off"></div>
              <?php if ($viewer === null): ?>
                <div class="field"><label for="reg-name">Full name</label><input type="text" id="reg-name" name="name" required maxlength="120" autocomplete="name" value="<?= e($old['name'] ?? '') ?>"<?= $inv('name') ?>><?= $err('name') ?></div>
                <div class="field"><label for="reg-email">Email</label><input type="email" id="reg-email" name="email" required maxlength="190" autocomplete="email" value="<?= e($old['email'] ?? '') ?>"<?= $inv('email') ?>><?= $err('email') ?></div>
              <?php else: ?>
                <p class="muted">Registering as <strong><?= e($viewer['display_name']) ?></strong> (<?= e($viewer['email']) ?>).</p>
              <?php endif ?>
              <div class="field"><label for="reg-phone">Phone <span class="hint">(optional)</span></label><input type="tel" id="reg-phone" name="phone" maxlength="40" autocomplete="tel" value="<?= e($old['phone'] ?? '') ?>"<?= $inv('phone') ?>><?= $err('phone') ?></div>
              <?php if ($event['ticket_rows']): ?>
                <div class="field"><label for="reg-ticket">Ticket</label>
                  <select id="reg-ticket" name="ticket_id" required<?= $inv('ticket_id') ?>>
                    <?php foreach ($event['ticket_rows'] as $tk): ?><option value="<?= (int) $tk['id'] ?>"<?= (string) ($old['ticket_id'] ?? '') === (string) $tk['id'] ? ' selected' : '' ?>><?= e($tk['name']) ?><?= $tk['price_label'] !== '' ? ' (' . e($tk['price_label']) . ')' : '' ?></option><?php endforeach ?>
                  </select><?= $err('ticket_id') ?></div>
              <?php endif ?>
              <div class="field"><label for="reg-qty">Number of places</label>
                <select id="reg-qty" name="quantity"<?= $inv('quantity') ?>><?php for ($i = 1; $i <= min(10, $left ?? 10); $i++): ?><option value="<?= $i ?>"<?= (string) ($old['quantity'] ?? '1') === (string) $i ? ' selected' : '' ?>><?= $i ?></option><?php endfor ?></select><?= $err('quantity') ?></div>
              <?php if ($event['registration_deadline']): ?><p class="hint">Registration closes <?= e(fmt_datetime($event['registration_deadline'])) ?>.</p><?php endif ?>
              <button class="btn accent" type="submit">Register now</button>
              <p class="hint">No payment is taken online. We will email you a confirmation.</p>
            </form>
          <?php endif ?>
        </div>
        <?php endif ?>
      </div>
    </div>
  </div>
</article>
