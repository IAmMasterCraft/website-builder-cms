<?php /** @var array $errors */ $fv = ['values' => [], 'errors' => $errors]; ?>
<section class="dash-card narrow-card">
  <form class="form" method="post" action="/account/password" novalidate>
    <?= csrf_field() ?>
    <?= view('partials/ffield', ['name' => 'current', 'label' => 'Current password', 'type' => 'password', 'required' => true, 'auto' => 'current-password'] + $fv) ?>
    <?= view('partials/ffield', ['name' => 'password', 'label' => 'New password', 'type' => 'password', 'required' => true, 'auto' => 'new-password', 'hint' => 'At least 10 characters.', 'extra' => ' minlength="10" maxlength="200"'] + $fv) ?>
    <?= view('partials/ffield', ['name' => 'password_confirm', 'label' => 'Repeat new password', 'type' => 'password', 'required' => true, 'auto' => 'new-password'] + $fv) ?>
    <p><button class="btn accent" type="submit"><?= icon('check') ?> Change password</button></p>
  </form>
</section>
