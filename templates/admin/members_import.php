<?php /** @var ?array $report @var array $columns @var array $fields */ ?>
<div class="page-head"><div><p class="crumbs"><a href="/admin/members">Members</a> /</p><h1>Import members</h1><p>Add or update members from a CSV file (UTF-8, comma separated, first row = column names).</p></div></div>
<?php if ($report): ?>
  <div class="flash <?= $report['errors'] ? 'info' : 'success' ?>" role="status"><?= icon('check') ?><span>Created <?= (int) $report['created'] ?>, updated <?= (int) $report['updated'] ?>, skipped <?= (int) $report['skipped'] ?>.</span></div>
  <?php if ($report['errors']): ?><section class="panel"><div class="panel-head"><h2>Rows that were not imported</h2></div><ul class="panel-body error-list"><?php foreach ($report['errors'] as $err): ?><li><?= e($err) ?></li><?php endforeach ?></ul></section><?php endif ?>
<?php endif ?>
<div class="form-layout">
  <form class="panel" method="post" action="/admin/members/import" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="panel-body fields">
      <div class="field"><label for="csv">CSV file</label><input type="file" id="csv" name="csv" accept=".csv,text/csv" required></div>
      <label class="check"><input type="checkbox" name="update" value="1"> Update members whose email already exists (otherwise they are skipped)</label>
      <label class="check"><input type="checkbox" name="notify" value="1"> Email new members their number and how to set a password</label>
      <div class="form-actions"><button class="btn accent" type="submit"><?= icon('upload') ?> Import</button></div>
    </div>
  </form>
  <aside class="panel"><div class="panel-head"><h2>Columns</h2></div><div class="panel-body">
    <p>Required: <code>email</code>, <code>first_name</code>, <code>last_name</code>. Optional:</p>
    <p class="mono small-text"><?= e(implode(', ', array_diff($columns, ['email', 'first_name', 'last_name', 'status', 'joined']))) ?><?= $fields ? ', ' . e(implode(', ', array_map(static fn ($f): string => 'cf_' . $f['field_key'], $fields))) : '' ?></p>
    <p class="hint">New members are active straight away with a member number (or the one in <code>member_number</code>, if that number was never issued before). Dates as YYYY-MM-DD. The easiest start is to export the current list and use it as a template.</p>
  </div></aside>
</div>
