<?php /** @var ?string $email @var string $token @var bool $done */ ?>
<section class="section">
  <div class="container narrow">
    <div class="auth-card">
      <?php if ($email === null): ?>
        <h1>This link does not work</h1>
        <p>The unsubscribe link is incomplete or has been changed. Copy the whole link from the email, or change your choices in <a href="/account/preferences">your email preferences</a>.</p>
      <?php elseif ($done): ?>
        <span class="verify-icon" aria-hidden="true"><?= icon('check') ?></span>
        <h1>You are unsubscribed</h1>
        <p><strong><?= e($email) ?></strong> will no longer receive newsletters, campaigns or notice emails from <?= e(setting('site.name')) ?>. Important account emails (like password resets) still arrive.</p>
        <p class="muted">Changed your mind? Log in and switch “News and event updates” back on in <a href="/account/preferences">your email preferences</a>.</p>
      <?php else: ?>
        <span class="verify-icon" aria-hidden="true"><?= icon('mail') ?></span>
        <h1>Unsubscribe?</h1>
        <p>Stop newsletters, campaigns and notice emails from <?= e(setting('site.name')) ?> to <strong><?= e($email) ?></strong>.</p>
        <form method="post" action="/unsubscribe/<?= e($token) ?>"><?= csrf_field() ?><button class="btn accent" type="submit">Yes, unsubscribe me</button></form>
      <?php endif ?>
    </div>
  </div>
</section>
