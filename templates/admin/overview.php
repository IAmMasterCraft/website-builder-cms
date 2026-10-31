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
    <?php if (($payments ?? null) !== null): ?>
    <section class="panel" aria-labelledby="pay-title">
      <div class="panel-head"><h2 id="pay-title">Payments</h2><a class="small-text" href="/admin/payments/reports">Reports</a></div>
      <div class="panel-body stat-grid flush">
        <a href="/admin/payments" class="stat-card<?= $payments['pending'] > 0 ? ' attention' : '' ?>"><span class="label"><?= icon('inbox') ?>Waiting for review</span><span class="value"><?= (int) $payments['pending'] ?></span></a>
        <a href="/admin/payments/reports" class="stat-card"><span class="label"><?= icon('landmark') ?>Collected this month</span><span class="value tabular"><?= e(money($payments['month'])) ?></span></a>
      </div>
      <?php if ($payments['outstanding']): ?><div class="panel-body"><h3 class="h-small">Most outstanding</h3><ul class="bday-list"><?php foreach ($payments['outstanding'] as $o): ?><li><a href="/admin/payments/items/<?= (int) $o['id'] ?>"><?= e($o['name']) ?></a><span class="tabular"><?= e(money((int) $o['outstanding_minor'])) ?></span></li><?php endforeach ?></ul></div><?php endif ?>
    </section>
    <?php endif ?>
    <?php if ($birthdays !== null): $bt = $birthdays['tab']; ?>
    <section class="panel" aria-labelledby="bday-title" id="birthdays">
      <div class="panel-head"><h2 id="bday-title">Birthdays</h2><span class="muted small-text">Emails at <?= e(birthday_time()) ?></span></div>
      <?= tabs_html('/admin', 'bday', $bt, ['today' => ['Today', $birthdays['counts']['today']], 'week' => ['This week', $birthdays['counts']['week']], 'month' => ['This month', $birthdays['counts']['month']]]) ?>
      <div class="panel-body">
        <?php if ($birthdays['rows']): ?>
          <ul class="bday-list"><?php foreach (array_slice($birthdays['rows'], 0, 40) as $b): ?><li><span><?= e(member_full_name($b)) ?> <span class="muted tabular"><?= e($b['member_number'] ?? '') ?></span><?= $bt !== 'today' && $b['celebrated_on'] === $birthdays['today'] ? ' <span class="badge ok">today</span>' : '' ?></span><span class="muted tabular"><?= $bt === 'today' ? (member_pref($b, 'birthday_greeting') ? '<span class="badge ok">greeting on</span>' : '') : e(birthday_day_label($b)) ?></span></li><?php endforeach ?></ul>
          <?php if (count($birthdays['rows']) > 40): ?><p class="muted small-text">and <?= count($birthdays['rows']) - 40 ?> more.</p><?php endif ?>
        <?php else: ?><p class="muted"><?= e(['today' => 'No member birthdays today.', 'week' => 'No birthdays this week.', 'month' => 'No birthdays this month.'][$bt]) ?></p><?php endif ?>
        <?php if (user_can($user, 'members')): ?><p><a href="/admin/members/birthdays">All birthdays by month</a></p><?php endif ?>
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
