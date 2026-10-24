<?php /** @var array $user @var ?array $member @var ?array $group */ ?>
<?php if ($user['status'] !== 'active'): ?>
  <div class="verify-banner">
    <span class="verify-icon" aria-hidden="true"><?= icon('mail') ?></span>
    <div>
      <h2>Check your email</h2>
      <p>We sent a link to <strong><?= e($user['email']) ?></strong>. Open it to verify your address<?= $group ? ': your group then gets its ID and appears in the directory.' : ' and get your member number and card.' ?></p>
      <p class="muted">Nothing arrived? Check your spam folder, or send a new link. Accounts that are not verified within <?= VERIFY_DAYS ?> days are removed.</p>
      <form method="post" action="/account/verify/resend"><?= csrf_field() ?><button class="btn small" type="submit">Send a new link</button></form>
    </div>
  </div>
  <p>While you wait, you can <a href="/account/profile">check your profile</a>.</p>
<?php else: ?>
  <?php if (!empty($is_birthday)): ?><div class="birthday-banner" role="status"><?= icon('heart') ?> Happy birthday, <?= e($member['first_name']) ?>! Warm wishes from everyone at <?= e(setting('site.name')) ?>.</div><?php endif ?>
  <p class="lede">Welcome back, <?= e($member ? $member['first_name'] : $user['display_name']) ?>.</p>
  <?php if (!empty($optin_prompt)): ?>
    <section class="dash-card optin-card" aria-labelledby="optin-title">
      <span class="optin-icon" aria-hidden="true"><?= icon('mail') ?></span>
      <div class="optin-text">
        <h2 id="optin-title">Get news and event updates by email</h2>
        <p class="muted">Hear about new events, news and opportunities from <?= e(setting('site.name')) ?>. Occasional emails; switch them off at any time in <a href="/account/preferences">Email preferences</a>.</p>
      </div>
      <div class="optin-actions">
        <form method="post" action="/account/newsletter/opt-in"><?= csrf_field() ?><button class="btn accent" type="submit"><?= icon('check') ?> Yes, send me updates</button></form>
        <form method="post" action="/account/newsletter/dismiss"><?= csrf_field() ?><button class="btn ghost" type="submit">No thanks</button></form>
      </div>
    </section>
  <?php endif ?>
  <div class="dash-grid">
    <?php if ($member && !empty($card)): ?>
      <section class="dash-card">
        <div class="dash-card-head"><h2>Your member card</h2><a class="link-arrow" href="/account/card">Open <?= icon('arrow-right') ?></a></div>
        <a class="card-link" href="/account/card" aria-label="Open your member card"><?= view('partials/id_card', ['card' => $card, 'side' => 'front']) ?></a>
        <?php if ($card['expired']): ?><p class="notice warn">Your card expired. Please contact us to renew your membership.</p><?php endif ?>
      </section>
    <?php elseif ($group): ?>
      <section class="dash-card">
        <h2>Your group</h2>
        <p class="group-id"><span class="muted">Group ID</span><strong class="tabular"><?= e($group['group_number'] ?? 'Pending') ?></strong></p>
        <p><span class="badge"><?= e($group['status'] === 'active' ? 'Listed in the directory' : ucfirst((string) $group['status'])) ?></span></p>
        <a class="link-arrow" href="/groups#<?= e($group['slug']) ?>">See your listing <?= icon('arrow-right') ?></a>
      </section>
    <?php endif ?>
    <section class="dash-card">
      <div class="dash-card-head"><h2>Coming up</h2><a class="link-arrow" href="/account/events">My events <?= icon('arrow-right') ?></a></div>
      <?php if ($my_events): ?>
        <p class="muted">You are registered for:</p>
        <ul class="event-list compact"><?php foreach ($my_events as $ev): ?><?= view('partials/event_row', ['event' => $ev]) ?><?php endforeach ?></ul>
      <?php elseif ($events): ?>
        <ul class="event-list compact"><?php foreach ($events as $ev): ?><?= view('partials/event_row', ['event' => $ev]) ?><?php endforeach ?></ul>
      <?php else: ?><p class="muted">No upcoming events right now.</p><?php endif ?>
    </section>
    <section class="dash-card">
      <div class="dash-card-head"><h2>Latest news</h2><a class="link-arrow" href="/news">All news <?= icon('arrow-right') ?></a></div>
      <?php if ($posts): ?><ul class="post-list"><?php foreach ($posts as $post): ?><li><time class="muted tabular" datetime="<?= e(iso_date($post['publish_at'])) ?>"><?= e(fmt_date($post['publish_at'])) ?></time><h3 class="h3"><a href="<?= e(post_url($post)) ?>"><?= e($post['title']) ?></a></h3></li><?php endforeach ?></ul>
      <?php else: ?><p class="muted">No news yet.</p><?php endif ?>
    </section>
    <section class="dash-card">
      <h2>Birthdays</h2>
      <?php if ($celebrants): ?><ul class="celebrants"><?php foreach ($celebrants as $c): ?><li><span class="celebrant-initial" aria-hidden="true"><?= e($c['initial']) ?></span><strong><?= e($c['name']) ?></strong></li><?php endforeach ?></ul>
      <?php else: ?><p class="muted">No birthdays to celebrate today.</p><?php endif ?>
      <?php $soon = array_values(array_filter($upcoming_birthdays ?? [], static fn (array $c): bool => !in_array($c, $celebrants, true))); if ($soon): ?>
        <h3 class="h4 muted">Coming up this week</h3>
        <ul class="celebrants small"><?php foreach ($soon as $c): ?><li><span class="celebrant-initial" aria-hidden="true"><?= e($c['initial']) ?></span><span><strong><?= e($c['name']) ?></strong> <span class="muted tabular"><?= e(DateTimeImmutable::createFromFormat('!n-j', $c['month'] . '-' . $c['day'])->format('j M')) ?></span></span></li><?php endforeach ?></ul>
      <?php endif ?>
      <p class="hint muted">Only members who chose to appear are listed. <a href="/account/preferences">Your choice</a>.</p>
    </section>
    <section class="dash-card">
      <div class="dash-card-head"><h2>Notices<?php if ($notices): ?> <span class="count-pill tabular"><?= count($notices) ?><span class="sr-only"> unread</span></span><?php endif ?></h2><a class="link-arrow" href="/account/notices">All notices <?= icon('arrow-right') ?></a></div>
      <?php if ($notices): ?>
        <ul class="notice-mini"><?php foreach (array_slice($notices, 0, 3) as $n): ?><li><a href="/account/notices#notice-<?= (int) $n['id'] ?>"><strong><?= e($n['title']) ?></strong></a><span class="muted small-text"><?= e(fmt_date($n['published_at'])) ?> · <?= e(excerpt($n['body'], 90)) ?></span></li><?php endforeach ?></ul>
      <?php else: ?><p class="muted">No unread notices.</p><?php endif ?>
    </section>
  </div>
<?php endif ?>
