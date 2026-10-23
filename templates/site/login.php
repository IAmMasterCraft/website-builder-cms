<?php $err = $errors['form'] ?? null; ?>
<div class="container">
  <div class="auth-card">
    <h1>Log in</h1>
    <p class="muted">Members and administrators use the same login.</p>
    <?php if ($err): ?><p class="flash error" role="alert" id="login-error"><?= icon('alert') ?><?= e($err) ?></p><?php endif ?>
    <form class="form" method="post" action="/login">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <div class="field"><label for="email">Email</label><input type="email" id="email" name="email" required autocomplete="username" value="<?= e($old['email'] ?? '') ?>"<?= $err ? ' aria-describedby="login-error"' : '' ?> autofocus></div>
      <div class="field"><label for="password">Password</label><input type="password" id="password" name="password" required autocomplete="current-password"></div>
      <button class="btn" type="submit">Log in</button>
      <p><a href="/forgot-password">Forgotten your password?</a></p>
    </form>
  </div>
</div>
