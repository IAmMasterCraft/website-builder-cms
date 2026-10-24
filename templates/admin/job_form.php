<?php /** @var array $values @var array $errors */ ?>
<div class="page-head"><div><p class="crumbs"><a href="/admin/jobs">Jobs</a></p><h1>Post a job</h1><p>Posts by admins go live straight away.</p></div></div>
<?= form_errors_html($errors) ?>
<form class="panel" method="post" action="/admin/jobs" novalidate><?= csrf_field() ?>
  <div class="panel-body fields"><?= view('partials/job_fields', ['values' => $values, 'errors' => $errors]) ?>
    <div class="form-actions"><button class="btn accent" type="submit"><?= icon('check') ?> Publish job</button></div></div>
</form>
