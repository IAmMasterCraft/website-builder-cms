<?php /** @var ?array $member @var array $errors */ ?>
<div class="account-panels">
  <section class="dash-card">
    <h2>Download your data</h2>
    <p>A copy of everything we hold about you (profile, preferences, event registrations, comments) as a JSON file.</p>
    <a class="btn" href="/account/privacy/export"><?= icon('download') ?> Download my data</a>
  </section>
  <section class="dash-card">
    <h2>Delete my account</h2>
    <?php if ($member && $member['deletion_requested_at']): ?>
      <p class="notice warn">You asked for your account to be deleted on <?= e(fmt_date((string) $member['deletion_requested_at'])) ?>. An administrator will confirm it soon.</p>
    <?php else: ?>
      <p>We will remove your profile and photo. Your member number is retired, event registrations are kept without your name, and comments are shown as “former member”. An administrator confirms deletion requests.</p>
      <form class="form" method="post" action="/account/privacy/delete" data-confirm="Send a request to delete your account?">
        <?= csrf_field() ?>
        <?= view('partials/ffield', ['name' => 'password', 'label' => 'Your password', 'type' => 'password', 'required' => true, 'auto' => 'current-password', 'values' => [], 'errors' => $errors]) ?>
        <p><button class="btn danger" type="submit"><?= icon('trash') ?> Request deletion</button></p>
      </form>
    <?php endif ?>
  </section>
</div>
