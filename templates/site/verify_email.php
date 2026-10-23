<?php /** @var ?string $token */ ?>
<section class="section">
  <div class="auth-card">
    <?php if ($token !== null): ?>
      <h1>Confirm your email</h1>
      <p>One click to activate your account. You will be logged in straight away.</p>
      <form method="post" action="/join/verify/<?= e($token) ?>"><?= csrf_field() ?><button class="btn accent" type="submit"><?= icon('check') ?> Verify my email</button></form>
    <?php else: ?>
      <h1>This link has expired</h1>
      <p>The verification link is not valid any more: it may already have been used, or it is more than <?= VERIFY_DAYS ?> days old.</p>
      <p><a class="btn" href="/login">Log in</a> to send yourself a new link, or <a href="/join">sign up again</a> if your account was removed.</p>
    <?php endif ?>
  </div>
</section>
