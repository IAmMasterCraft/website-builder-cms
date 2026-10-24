<?php /** @var ?string $token @var ?string $pending */ ?>
<section class="section">
  <div class="auth-card">
    <?php if ($token !== null): ?>
      <h1>Confirm your new email</h1>
      <p>Use <strong><?= e((string) $pending) ?></strong> to log in from now on?</p>
      <form method="post" action="/account/email/confirm/<?= e($token) ?>"><?= csrf_field() ?><button class="btn accent" type="submit"><?= icon('check') ?> Yes, confirm</button></form>
    <?php else: ?>
      <h1>This link has expired</h1>
      <p>The link is not valid any more: it may already have been used, the change was cancelled, or it is more than <?= (int) EMAIL_CHANGE_DAYS ?> days old.</p>
      <p><a class="btn" href="/account/password">Change your email again</a></p>
    <?php endif ?>
  </div>
</section>
