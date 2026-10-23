<div class="container">
  <div class="auth-card">
    <h1>Choose a new password</h1>
    <?php if (!$valid): ?>
      <p class="flash error" role="alert">This link has expired or was already used.</p>
      <p><a class="btn" href="/forgot-password">Request a new link</a></p>
    <?php else: ?>
      <form class="form" method="post" action="/reset/<?= e($token) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="field"><label for="password">New password</label><input type="password" id="password" name="password" required minlength="10" autocomplete="new-password" aria-describedby="pw-hint<?= isset($errors['password']) ? ' err-password' : '' ?>"<?= isset($errors['password']) ? ' aria-invalid="true"' : '' ?>>
          <p class="hint" id="pw-hint">At least 10 characters. A short sentence is easy to remember.</p>
          <?php if (isset($errors['password'])): ?><p class="error" id="err-password"><?= e($errors['password']) ?></p><?php endif ?></div>
        <div class="field"><label for="password_confirm">Repeat the password</label><input type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password"<?= isset($errors['password_confirm']) ? ' aria-invalid="true" aria-describedby="err-confirm"' : '' ?>>
          <?php if (isset($errors['password_confirm'])): ?><p class="error" id="err-confirm"><?= e($errors['password_confirm']) ?></p><?php endif ?></div>
        <button class="btn" type="submit">Save new password</button>
      </form>
    <?php endif ?>
  </div>
</div>
