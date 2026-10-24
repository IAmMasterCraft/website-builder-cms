<?php /** @var array $slot @var array $values @var array $errors @var array $sources */ ?>
<div class="page-head"><div><p class="crumbs"><a href="/admin/radio/schedule">Schedule</a></p><h1><?= e(radio_slot_label($slot)) ?></h1><p><?= e(radio_repeat_label($slot)) ?> · <?= e(radio_fmt_duration((float) $slot['duration'])) ?></p></div>
  <div class="actions"><form method="post" action="/admin/radio/schedule/<?= (int) $slot['id'] ?>/delete" data-confirm="Remove this slot<?= $slot['repeat_rule'] !== 'none' ? ' and all its repeats' : '' ?>?"><?= csrf_field() ?><button class="btn danger-ghost" type="submit"><?= icon('trash') ?> Remove</button></form></div></div>
<?= form_errors_html($errors) ?>
<form class="panel narrow-panel" method="post" action="/admin/radio/schedule/<?= (int) $slot['id'] ?>" novalidate>
  <?= csrf_field() ?>
  <div class="panel-body fields"><?= view('admin/_slot_fields', ['values' => $values, 'errors' => $errors, 'sources' => $sources, 'prefix' => 'es']) ?>
    <div class="form-actions"><button class="btn accent" type="submit"><?= icon('check') ?> Save slot</button><a class="btn ghost" href="/admin/radio/schedule">Cancel</a></div></div>
</form>
<script type="module" src="<?= e(asset('assets/js/radio-admin.js')) ?>"></script>
