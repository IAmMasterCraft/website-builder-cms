<?php /** @var array $counts @var ?array $birthdays (null without the members permission) @var array $activity @var bool $activity_all @var array $user */
$cards = [
    ['Active members', $counts['members_active'], 'users', null, false],
    ['Awaiting email verification', $counts['members_unverified'], 'mail', null, false],
    ['New members this week', $counts['members_new_week'], 'user', null, false],
    ['Upcoming events', $counts['events_upcoming'], 'calendar', user_can($user, 'events') ? '/admin/events?when=upcoming' : null, false],
    ['Registrations this week', $counts['registrations_week'], 'ticket', user_can($user, 'events') ? '/admin/events' : null, false],
    ['Published posts', $counts['posts_published'], 'pen', user_can($user, 'content') ? '/admin/posts' : null, false],
];
$attention = [
    ['Unread messages', $counts['messages_unread'], 'inbox', user_can($user, 'communications') ? '/admin/inbox?show=unread' : null],
    ['Reported comments', $counts['comments_pending'], 'help', user_can($user, 'content') ? '/admin/comments' : null],
    ['Jobs awaiting approval', $counts['jobs_pending'], 'flag', user_can($user, 'jobs') ? '/admin/jobs' : null],
    ['Team inbox (unread)', $counts['team_unread'], 'message', user_can($user, 'messages') ? '/admin/messages/team?show=unread' : null],
    ['Reported messages', $counts['message_reports'], 'flag', user_can($user, 'messages') ? '/admin/messages/reports' : null],
    ['Scheduled items', $counts['scheduled'], 'clock', null],
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
        <?php if (user_can($user, 'communications')): ?><a href="/admin/campaigns"><?= icon('mail') ?> New campaign</a><a href="/admin/notices/new"><?= icon('bell') ?> New notice</a><?php endif ?>
        <?php if (user_can($user, 'radio')): ?><a href="/admin/radio#upload"><?= icon('radio') ?> Upload to radio</a><?php endif ?>
      </div>
    </section>
    <section class="panel" aria-labelledby="act-title">
      <div class="panel-head"><h2 id="act-title"><?= $activity_all ? 'Recent activity' : 'Your recent activity' ?></h2></div>
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
        <?php foreach ($attention as [$label, $value, $ic, $href]): ?>
          <<?= $href ? 'a href="' . e($href) . '"' : 'div' ?> class="stat-card<?= $value > 0 ? ' attention' : '' ?>"><span class="label"><?= icon($ic) ?><?= e($label) ?></span><span class="value"><?= (int) $value ?></span></<?= $href ? 'a' : 'div' ?>>
        <?php endforeach ?>
      </div>
    </section>
    <?php if ($birthdays !== null): ?>
    <section class="panel" aria-labelledby="bday-title">
      <div class="panel-head"><h2 id="bday-title">Birthdays</h2><span class="muted small-text">Emails at <?= e(birthday_time()) ?></span></div>
      <div class="panel-body">
        <?php $todayYmd = (new DateTimeImmutable('now', site_tz()))->format('Y-m-d'); $todayList = array_filter($birthdays, static fn (array $b): bool => $b['celebrated_on'] === $todayYmd); $weekList = array_filter($birthdays, static fn (array $b): bool => $b['celebrated_on'] !== $todayYmd); ?>
        <h3 class="h-small">Today</h3>
        <?php if ($todayList): ?>
          <ul class="bday-list"><?php foreach ($todayList as $b): ?><li><span><?= e($b['first_name'] . ' ' . $b['last_name']) ?> <span class="muted tabular"><?= e($b['member_number'] ?? '') ?></span></span><?= member_pref($b, 'birthday_greeting') ? '<span class="badge ok">greeting on</span>' : '' ?></li><?php endforeach ?></ul>
        <?php else: ?><p class="muted">No member birthdays today.</p><?php endif ?>
        <h3 class="h-small">Next 7 days</h3>
        <?php if ($weekList): ?>
          <ul class="bday-list"><?php foreach ($weekList as $b): ?><li><span><?= e($b['first_name'] . ' ' . $b['last_name']) ?></span><span class="muted tabular"><?= e(DateTimeImmutable::createFromFormat('!Y-m-d', $b['celebrated_on'], site_tz())->format('D j M')) ?></span></li><?php endforeach ?></ul>
        <?php else: ?><p class="muted">None in the coming week.</p><?php endif ?>
      </div>
    </section>
    <?php endif ?>
    <section class="panel" aria-labelledby="sys-title">
      <div class="panel-head"><h2 id="sys-title">System</h2></div>
      <div class="panel-body">
        <p>Cron last ran: <strong><?= $cron_last ? e(fmt_datetime($cron_last)) : 'never' ?></strong></p>
        <?php if (!$cron_last || strtotime($cron_last . ' UTC') < time() - 600): ?><p class="notice-box">Cron does not seem to be running. Add <code><?= e($cron_line) ?></code> to the crontab of the user PHP runs as (System health shows the details). Scheduled items still publish when someone visits the site.</p><?php endif ?>
        <p class="muted">PHP <?= e(PHP_VERSION) ?></p>
      </div>
    </section>
  </div>
</div>
