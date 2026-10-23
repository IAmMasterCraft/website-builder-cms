<?= view('install/_steps', ['step' => $step]) ?>
<h1>Create the super admin</h1>
<p class="muted">This account can change everything, including other admins’ permissions. Tables were created successfully.</p>
<form class="panel panel-body fields" method="post" action="/install/admin" novalidate>
  <?= csrf_field() ?>
  <?= view('install/_field', ['name' => 'site_name', 'label' => 'Organisation name', 'old' => $old, 'errors' => $errors]) ?>
  <?= view('install/_field', ['name' => 'name', 'label' => 'Your name', 'old' => $old, 'errors' => $errors, 'auto' => 'name']) ?>
  <?= view('install/_field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'old' => $old, 'errors' => $errors, 'auto' => 'username']) ?>
  <?= view('install/_field', ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'old' => [], 'errors' => $errors, 'auto' => 'new-password', 'hint' => 'At least 10 characters.']) ?>
  <div class="form-actions"><button class="btn accent" type="submit">Create account</button></div>
</form>
