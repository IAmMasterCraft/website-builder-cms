<?php $err = $errors['form'] ?? null; $side = site_feature_image(); ?>
<div class="auth-wrap">
  <div class="auth-side" aria-hidden="true">
    <?= $side ? img_html($side, '50vw', '', '', true, 1600) : '' ?>
    <div class="auth-side-copy"><span class="eyebrow"><?= e(setting('site.name')) ?></span><p><?= e(setting('site.tagline') ?: 'Welcome back.') ?></p></div>
  </div>
  <div class="auth-main">
    <div class="auth-card">
      <span class="eyebrow">Welcome back</span>
      <h1>Log in</h1>
      <p class="muted">Members and administrators use the same login.</p>
      <?php if ($err): ?><p class="flash error" role="alert" id="login-error"><?= icon('alert') ?><?= e($err) ?></p><?php endif ?>
      <form class="form" method="post" action="/login">
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <div class="field"><label for="email">Email</label><input type="email" id="email" name="email" required autocomplete="username" value="<?= e($old['email'] ?? '') ?>"<?= $err ? ' aria-describedby="login-error"' : '' ?> autofocus></div>
        <div class="field"><label for="password">Password</label><input type="password" id="password" name="password" required autocomplete="current-password"></div>
        <button class="btn accent large block" type="submit">Log in <?= icon('arrow-right') ?></button>
        <p class="auth-links"><a href="/forgot-password">Forgotten your password?</a><span>New here? <a href="/join">Become a member</a></span></p>
      </form>
    </div>
  </div>
</div>
