<div class="container">
  <div class="auth-card">
    <h1>Reset your password</h1>
    <p class="muted">Enter your email and we will send you a link to choose a new password.</p>
    <form class="form" method="post" action="/forgot-password" novalidate>
      <?= csrf_field() ?>
      <div class="field"><label for="email">Email</label><input type="email" id="email" name="email" required autocomplete="email" value="<?= e($old['email'] ?? '') ?>"<?= isset($errors['email']) ? ' aria-invalid="true" aria-describedby="err-email"' : '' ?>>
        <?php if (isset($errors['email'])): ?><p class="error" id="err-email"><?= e($errors['email']) ?></p><?php endif ?></div>
      <button class="btn" type="submit">Send reset link</button>
      <p><a href="/login">Back to log in</a></p>
    </form>
  </div>
</div>
