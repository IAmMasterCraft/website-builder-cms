<?php /** @var array $me @var array $errors @var array $values @var ?string $pending_email */
$v = static fn (string $k): string => is_scalar($values[$k] ?? null) ? (string) $values[$k] : '';
?>
<div class="page-head"><div><h1>My profile</h1><p class="muted">Your own name, login email and password. Your role and permissions are changed by a super admin in Users and roles.</p></div></div>
<?= form_errors_html($errors) ?>
<div class="form-layout">
  <div class="stack">
    <form class="panel" method="post" action="/admin/profile" novalidate aria-labelledby="name-h">
      <?= csrf_field() ?>
      <div class="panel-body fields">
        <h2 id="name-h">Name</h2>
        <?= field_html('display_name', 'Name (shown in the admin area and the audit log)', 'text', $v('display_name'), $errors['display_name'] ?? null, ['required' => true, 'max' => 120, 'prefix' => 'p', 'autocomplete' => 'name']) ?>
        <div class="form-actions"><button class="btn accent" type="submit"><?= icon('check') ?> Save name</button></div>
      </div>
    </form>
    <section class="panel" id="email" aria-labelledby="email-h">
      <div class="panel-body fields">
        <h2 id="email-h">Login email</h2>
        <p>You log in with <strong><?= e($me['email']) ?></strong>.</p>
        <?php if ($pending_email !== null): ?>
          <div class="notice warn" role="status"><p>Waiting for confirmation: we sent a link to <strong><?= e($pending_email) ?></strong>. Your login stays the same until you open it.</p>
            <form method="post" action="/admin/profile/email/cancel"><?= csrf_field() ?><button class="btn ghost small" type="submit">Cancel the change</button></form></div>
        <?php endif ?>
        <form class="fields" method="post" action="/admin/profile/email" novalidate>
          <?= csrf_field() ?>
          <?= field_html('new_email', 'New email address', 'email', $v('new_email'), $errors['new_email'] ?? null, ['required' => true, 'max' => 190, 'prefix' => 'p', 'autocomplete' => 'email', 'hint' => 'We send a confirmation link to the new address and tell the old one. Confirming logs you out everywhere else.']) ?>
          <?= field_html('email_password', 'Current password', 'password', '', $errors['email_password'] ?? null, ['required' => true, 'prefix' => 'p', 'autocomplete' => 'current-password']) ?>
          <div class="form-actions"><button class="btn" type="submit"><?= icon('mail') ?> Change email</button></div>
        </form>
      </div>
    </section>
  </div>
  <div class="side">
    <form class="panel" id="password" method="post" action="/admin/profile/password" novalidate aria-labelledby="password-h">
      <?= csrf_field() ?>
      <div class="panel-body fields">
        <h2 id="password-h">Password</h2>
        <?= field_html('current', 'Current password', 'password', '', $errors['current'] ?? null, ['required' => true, 'prefix' => 'p', 'autocomplete' => 'current-password']) ?>
        <?= field_html('password', 'New password', 'password', '', $errors['password'] ?? null, ['required' => true, 'max' => 200, 'prefix' => 'p', 'autocomplete' => 'new-password', 'hint' => 'At least 10 characters. Other browsers where you are logged in are logged out.']) ?>
        <?= field_html('password_confirm', 'Repeat new password', 'password', '', $errors['password_confirm'] ?? null, ['required' => true, 'max' => 200, 'prefix' => 'p', 'autocomplete' => 'new-password']) ?>
        <div class="form-actions"><button class="btn accent" type="submit"><?= icon('check') ?> Change password</button></div>
      </div>
    </form>
  </div>
</div>
