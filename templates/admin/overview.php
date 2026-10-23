<?php /** @var array $counts @var array $birthdays @var array $activity @var array $user */
$cards = [
    ['Active members', $counts['members_active'], 'users', null, false],
    ['Awaiting email verification', $counts['members_unverified'], 'mail', null, false],
    ['New members this week', $counts['members_new_week'], 'user', null, false],
    ['Upcoming events', $counts['events_upcoming'], 'calendar', user_can($user, 'events') ? '/admin/events?when=upcoming' : null, false],
    ['Registrations this week', $counts['registrations_week'], 'ticket', user_can($user, 'events') ? '/admin/events' : null, false],
    ['Published posts', $counts['posts_published'], 'pen', user_can($user, 'content') ? '/admin/posts' : null, false],
];
$attention = [
    ['Unread messages', $counts['messages_unread'], 'inbox'],
    ['Comments to moderate', $counts['comments_pending'], 'help'],
    ['Jobs awaiting approval', $counts['jobs_pending'], 'flag'],
    ['Scheduled items', $counts['scheduled'], 'clock'],
];
$hour = (int) (new DateTimeImmutable('now', site_tz()))->format('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
?>
<div class="page-head">
  <div><h1><?= e($greeting) ?>, <?= e(explode(' ', (string) $user['display_name'])[0]) ?></h1><p>Here is what is happening on <?= e(setting('site.name')) ?>.</p></div>
  <div class="actions"><a class="btn ghost" href="/" target="_blank" rel="noopener"><?= icon('external') ?> View site</a></div>
</div>

<div class="stat-grid">
  <?php foreach ($cards as [$label, $value, $ic, $href]): ?>
    <<?= $href ? 'a href="' . e($href) . '"' : 'div' ?> class="stat-card"><span class="label"><?= icon($ic) ?><?= e($label) ?></span><span class="value"><?= (int) $value ?></span></<?= $href ? 'a' : 'div' ?>>
  <?php endforeach ?>
</div>

<div class="dash-grid">
  <div>
    <section class="panel" aria-labelledby="qa-title">
      <div class="panel-head"><h2 id="qa-title">Quick actions</h2></div>
      <div class="panel-body quick-actions">
        <?php if (user_can($user, 'content')): ?><a href="/admin/posts/new"><?= icon('pen') ?> New post</a><?php endif ?>
        <?php if (user_can($user, 'events')): ?><a href="/admin/events/new"><?= icon('calendar') ?> New event</a><?php endif ?>
        <?php if (user_can($user, 'pages_menus')): ?><a href="/admin/pages/new"><?= icon('file') ?> New page</a><?php endif ?>
        <?php if (user_can($user, 'gallery_media')): ?><a href="/admin/albums/new"><?= icon('image') ?> New album</a><a href="/admin/media"><?= icon('upload') ?> Upload media</a><?php endif ?>
        <?php if (user_can($user, 'pages_menus')): ?><a href="/admin/menus"><?= icon('menu') ?> Edit menus</a><?php endif ?>
      </div>
    </section>
    <section class="panel" aria-labelledby="act-title">
      <div class="panel-head"><h2 id="act-title">Recent activity</h2></div>
      <?php if ($activity): ?>
        <ul class="activity">
          <?php foreach ($activity as $a): ?>
            <li><span><strong><?= e($a['display_name'] ?? 'System') ?></strong> <?= e($a['action']) ?> <?= e(str_replace('_', ' ', $a['item_type'])) ?><?= $a['summary'] !== '' ? ': ' . e($a['summary']) : '' ?></span><time datetime="<?= e(iso_date($a['created_at'])) ?>"><?= e(fmt_date($a['created_at'], 'j M, H:i')) ?></time></li>
          <?php endforeach ?>
        </ul>
      <?php else: ?><div class="panel-body muted">No activity yet.</div><?php endif ?>
    </section>
  </div>
  <div>
    <section class="panel" aria-labelledby="needs-attention">
      <div class="panel-head"><h2 id="needs-attention">Needs attention</h2></div>
      <div class="panel-body stat-grid flush">
        <?php foreach ($attention as [$label, $value, $ic]): ?>
          <div class="stat-card<?= $value > 0 ? ' attention' : '' ?>"><span class="label"><?= icon($ic) ?><?= e($label) ?></span><span class="value"><?= (int) $value ?></span></div>
        <?php endforeach ?>
      </div>
    </section>
    <section class="panel" aria-labelledby="bday-title">
      <div class="panel-head"><h2 id="bday-title">Birthdays today</h2></div>
      <div class="panel-body">
        <?php if ($birthdays): ?>
          <ul><?php foreach ($birthdays as $b): ?><li><?= e($b['first_name'] . ' ' . $b['last_name']) ?> <span class="muted tabular"><?= e($b['member_number'] ?? '') ?></span></li><?php endforeach ?></ul>
        <?php else: ?><p class="muted">No member birthdays today.</p><?php endif ?>
      </div>
    </section>
    <section class="panel" aria-labelledby="sys-title">
      <div class="panel-head"><h2 id="sys-title">System</h2></div>
      <div class="panel-body">
        <p>Cron last ran: <strong><?= $cron_last ? e(fmt_datetime($cron_last)) : 'never' ?></strong></p>
        <?php if (!$cron_last || strtotime($cron_last . ' UTC') < time() - 600): ?><p class="notice-box">Cron does not seem to be running. Add <code>* * * * * php <?= e(BASE) ?>/bin/cron</code> to the server’s crontab. Scheduled items still publish when someone visits the site.</p><?php endif ?>
        <p class="muted">PHP <?= e(PHP_VERSION) ?></p>
      </div>
    </section>
  </div>
</div>
