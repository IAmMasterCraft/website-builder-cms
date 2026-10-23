<?php /** @var array $type @var array $values @var array $errors @var ?array $row */
$key = $type['key'];
$main = array_filter($type['fields'], static fn ($f) => empty($f['side']) && empty($f['seo']));
$side = array_filter($type['fields'], static fn ($f) => !empty($f['side']));
$seo = array_filter($type['fields'], static fn ($f) => !empty($f['seo']));
$fieldData = ['values' => $values, 'errors' => $errors, 'options' => $options, 'datalists' => $datalists];
?>
<div class="page-head">
  <div>
    <p class="crumbs"><a href="/admin/<?= e($key) ?>"><?= e($type['plural']) ?></a> /</p>
    <h1><?= $row ? e((string) $row[$type['title']]) : 'New ' . e(strtolower($type['label'])) ?></h1>
  </div>
  <div class="actions">
    <?php if ($row && $key === 'events'): ?><a class="btn ghost" href="/admin/events/<?= (int) $row['id'] ?>/registrations"><?= icon('ticket') ?> Registrations</a><?php endif ?>
    <?php if ($row && $key === 'albums'): ?><a class="btn ghost" href="/admin/albums/<?= (int) $row['id'] ?>/photos"><?= icon('image') ?> Manage photos</a><?php endif ?>
    <?php if ($row && $key === 'leader-groups'): ?><a class="btn ghost" href="/admin/leaders/new?group=<?= (int) $row['id'] ?>"><?= icon('plus') ?> Add leader</a><?php endif ?>
    <?php if ($public_url): ?><a class="btn ghost" href="<?= e($public_url) ?>" target="_blank" rel="noopener"><?= icon('eye') ?> View</a><?php endif ?>
  </div>
</div>
<?php if ($errors): ?><div class="flash error" role="alert"><?= icon('alert') ?><span>Please fix the <?= e(pluralise(count($errors), 'problem')) ?> below.</span></div><?php endif ?>
<form method="post" action="/admin/<?= e($key) ?><?= $row ? '/' . (int) $row['id'] : '' ?>" enctype="multipart/form-data" novalidate>
  <?= csrf_field() ?>
  <?php if ($row && $key === 'pages'): ?><input type="hidden" name="revision" value="<?= (int) $row['revision'] ?>"><?php endif ?>
  <div class="form-layout">
    <div class="panel"><div class="panel-body fields">
      <?php foreach ($main as $f): ?><?= view('admin/_field', ['f' => $f] + $fieldData) ?><?php endforeach ?>
      <?php if ($seo): ?>
        <details class="seo"<?= isset($errors['seo_title']) || isset($errors['seo_description']) ? ' open' : '' ?>><summary>Search engine settings</summary><div class="fields">
          <?php foreach ($seo as $f): ?><?= view('admin/_field', ['f' => $f] + $fieldData) ?><?php endforeach ?>
        </div></details>
      <?php endif ?>
    </div></div>
    <div class="side">
      <div class="panel"><div class="panel-body fields">
        <?php foreach ($side as $f): ?><?= view('admin/_field', ['f' => $f] + $fieldData) ?><?php endforeach ?>
        <div class="form-actions">
          <button class="btn accent" type="submit"><?= icon('check') ?> <?= $row ? 'Save changes' : 'Create ' . e(strtolower($type['label'])) ?></button>
          <a class="btn ghost" href="/admin/<?= e($key) ?>">Cancel</a>
        </div>
        <?php if ($row): ?><p class="hint muted">Last saved <?= e(fmt_datetime($row['updated_at'])) ?></p><?php endif ?>
      </div></div>
    </div>
  </div>
</form>
<?php if ($row): ?>
  <form method="post" action="/admin/<?= e($key) ?>/<?= (int) $row['id'] ?>/delete" class="panel panel-body" data-confirm="Delete this <?= e(strtolower($type['label'])) ?>? This cannot be undone.">
    <?= csrf_field() ?>
    <h2>Delete</h2>
    <p class="muted">Removes this <?= e(strtolower($type['label'])) ?> permanently.</p>
    <button class="btn danger-ghost" type="submit"><?= icon('trash') ?> Delete <?= e(strtolower($type['label'])) ?></button>
  </form>
<?php endif ?>
<template id="media-picker-template">
  <dialog class="picker-dialog" aria-labelledby="picker-title">
    <div class="picker-head"><h2 id="picker-title">Choose an image</h2><label for="picker-q" class="sr-only">Search images</label><input type="search" id="picker-q" placeholder="Search…"><button type="button" class="btn small ghost" data-close>Close</button></div>
    <ul class="picker-grid"></ul>
    <p class="picker-status" role="status"></p>
  </dialog>
</template>
