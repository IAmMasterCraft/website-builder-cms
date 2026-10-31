<?php /** @var array $user @var string $active @var bool $is_group @var string $inner @var string $title */
$verified = $user['status'] === 'active';
$links = [
    ['home', '/account', 'Overview', 'home', true],
    ['profile', '/account/profile', $is_group ? 'Group profile' : 'Profile and photo', 'user', true],
    ['card', '/account/card', 'Member card', 'id-card', !$is_group && $verified],
    ['events', '/account/events', 'My events', 'calendar', $verified],
    ['jobs', '/account/jobs', 'Jobs', 'briefcase', $verified],
    ['downloads', '/account/downloads', 'Downloads', 'file-down', $verified],
    ['messages', '/account/messages', 'Messages', 'message', $verified],
    ['payments', '/account/payments', 'Payments', 'landmark', !$is_group && $verified],
    ['notices', '/account/notices', 'Notices', 'bell', $verified],
    ['preferences', '/account/preferences', 'Email preferences', 'mail', $verified],
    ['password', '/account/password', 'Email and password', 'lock', true],
    ['privacy', '/account/privacy', 'Your data', 'shield', true],
    ['help', '/help', 'Help', 'help', true],
];
?>
<div class="account-head">
  <div class="container">
    <p class="eyebrow">My account<?= !empty($user['display_name']) ? ' · ' . e($user['display_name']) : '' ?></p>
    <h1><?= e($title) ?></h1>
  </div>
</div>
<section class="section account-section">
  <div class="container account-layout">
    <nav class="account-nav" aria-label="Account">
      <ul>
        <?php foreach ($links as [$key, $href, $label, $ic, $show]): if (!$show) { continue; } ?>
          <li><a href="<?= e($href) ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><?= icon($ic) ?> <?= e($label) ?><?php if ($key === 'notices' && !empty($unread_notices)): ?> <span class="count-pill tabular"><?= (int) $unread_notices ?><span class="sr-only"> unread</span></span><?php endif ?><?php if ($key === 'messages'): ?> <span class="count-pill tabular" data-dm-badge data-dm-src="/account/messages/unread"<?= empty($unread_messages) ? ' hidden' : '' ?>><?= (int) ($unread_messages ?? 0) ?></span><span class="sr-only" data-dm-badge-label><?= empty($unread_messages) ? '' : ', ' . e(pluralise((int) $unread_messages, 'unread message')) ?></span><?php endif ?></a></li>
        <?php endforeach ?>
      </ul>
      <form method="post" action="/logout"><?= csrf_field() ?><button class="btn ghost small" type="submit"><?= icon('logout') ?> Log out</button></form>
    </nav>
    <div class="account-main">
      <?php if (!empty($bounce_suppression)): ?>
        <div class="notice warn bounce-banner" role="status"><p><?= icon('alert') ?> <strong>Our emails to <?= e($user['email']) ?> are not getting through.</strong>
          <?= $bounce_suppression['reason'] === 'hard_bounce' ? 'The mail server said the address does not exist or cannot receive mail.' : 'They bounced several times (for example because the mailbox is full).' ?>
          We have stopped emailing it. Please <a href="/account/password">update your email address</a>, or contact us once the mailbox works again.</p></div>
      <?php endif ?>
      <?= $inner ?>
    </div>
  </div>
</section>
