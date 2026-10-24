<?php /** @var array $errors @var array $values @var array $user @var ?string $pending_email */
$fv = ['values' => [], 'errors' => $errors];
$ev = ['values' => $values ?? [], 'errors' => $errors];
?>
<section class="dash-card narrow-card" id="email" aria-labelledby="email-h">
  <h2 id="email-h">Email address</h2>
  <p>You log in with <strong><?= e($user['email']) ?></strong>.</p>
  <?php if ($pending_email !== null): ?>
    <div class="notice warn pending-email" role="status"><p>Waiting for confirmation: we sent a link to <strong><?= e($pending_email) ?></strong>. Your login stays the same until you open it.</p>
      <form method="post" action="/account/email/cancel"><?= csrf_field() ?><button class="btn ghost small" type="submit">Cancel the change</button></form></div>
  <?php endif ?>
  <form class="form" method="post" action="/account/email" novalidate>
    <?= csrf_field() ?>
    <?= view('partials/ffield', ['name' => 'new_email', 'label' => 'New email address', 'type' => 'email', 'required' => true, 'auto' => 'email', 'hint' => $user['status'] === 'active' ? 'We send a confirmation link to the new address and tell the old one.' : 'We send a new verification link to this address.', 'extra' => ' maxlength="190"'] + $ev) ?>
    <?= view('partials/ffield', ['name' => 'email_password', 'label' => 'Current password', 'type' => 'password', 'required' => true, 'auto' => 'current-password'] + $ev) ?>
    <p><button class="btn" type="submit"><?= icon('mail') ?> Change email</button></p>
  </form>
</section>
<section class="dash-card narrow-card" aria-labelledby="password-h">
  <h2 id="password-h">Password</h2>
  <form class="form" method="post" action="/account/password" novalidate>
    <?= csrf_field() ?>
    <?= view('partials/ffield', ['name' => 'current', 'label' => 'Current password', 'type' => 'password', 'required' => true, 'auto' => 'current-password'] + $fv) ?>
    <?= view('partials/ffield', ['name' => 'password', 'label' => 'New password', 'type' => 'password', 'required' => true, 'auto' => 'new-password', 'hint' => 'At least 10 characters.', 'extra' => ' minlength="10" maxlength="200"'] + $fv) ?>
    <?= view('partials/ffield', ['name' => 'password_confirm', 'label' => 'Repeat new password', 'type' => 'password', 'required' => true, 'auto' => 'new-password'] + $fv) ?>
    <p><button class="btn accent" type="submit"><?= icon('check') ?> Change password</button></p>
  </form>
</section>
