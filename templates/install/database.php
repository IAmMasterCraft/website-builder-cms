<?= view('install/_steps', ['step' => $step]) ?>
<h1>Connect your database</h1>
<p class="muted">Create an empty MySQL 8 or MariaDB 10.6+ database (utf8mb4) in your hosting panel, then enter its details. Gather writes them to <code>config.php</code>, which never goes into version control.</p>
<div class="panel"><div class="panel-head"><h2>Server check</h2></div><div class="panel-body"><ul>
  <?php foreach ($checks as [$label, $ok]): ?><li><span class="badge <?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? 'OK' : 'Missing' ?></span> <?= e($label) ?></li><?php endforeach ?>
</ul></div></div>
<?php if (isset($errors['form'])): ?><p class="flash error" role="alert"><?= e($errors['form']) ?></p><?php endif ?>
<form class="panel panel-body fields" method="post" action="/install/database" novalidate>
  <?= csrf_field() ?>
  <?= view('install/_field', ['name' => 'install_token', 'label' => 'Install token', 'type' => 'password', 'old' => [], 'errors' => $errors, 'auto' => 'off', 'hint' => 'The token you put in storage/install-token (or GATHER_INSTALL_TOKEN) on the server.']) ?>
  <?= view('install/_field', ['name' => 'base_url', 'label' => 'Site address', 'type' => 'url', 'old' => $old, 'errors' => $errors, 'hint' => 'The public address of the site, used in emails and links, e.g. https://www.example.org']) ?>
  <div class="field-row">
    <?= view('install/_field', ['name' => 'db_host', 'label' => 'Host', 'old' => $old, 'errors' => $errors, 'hint' => 'Usually localhost or 127.0.0.1']) ?>
    <?= view('install/_field', ['name' => 'db_port', 'label' => 'Port', 'type' => 'number', 'old' => $old, 'errors' => $errors]) ?>
  </div>
  <?= view('install/_field', ['name' => 'db_name', 'label' => 'Database name', 'old' => $old, 'errors' => $errors]) ?>
  <div class="field-row">
    <?= view('install/_field', ['name' => 'db_user', 'label' => 'User', 'old' => $old, 'errors' => $errors, 'auto' => 'off']) ?>
    <?= view('install/_field', ['name' => 'db_pass', 'label' => 'Password', 'type' => 'password', 'old' => [], 'errors' => $errors, 'auto' => 'off']) ?>
  </div>
  <div class="form-actions"><button class="btn accent" type="submit">Connect and create tables</button></div>
</form>
