<?php /** @var array $type @var array $values @var array $errors @var ?array $row */
$key = $type['key'];
$isBuilderPage = $key === 'pages' && $row !== null && $row['sections'] !== null;
$main = array_filter($type['fields'], static fn ($f) => empty($f['side']) && empty($f['seo']) && !($isBuilderPage && $f['name'] === 'body'));
$side = array_filter($type['fields'], static fn ($f) => !empty($f['side']));
$seo = array_filter($type['fields'], static fn ($f) => !empty($f['seo']));
$fieldData = ['values' => $values, 'errors' => $errors, 'options' => $options, 'datalists' => $datalists];
?>
<div class="page-head">
  <div>
    <p class="crumbs"><a href="/admin/<?= e($key) ?>"><?= e($type['plural']) ?></a> /</p>
    <h1><?= $row ? e((string) $row[$type['title']]) : 'New ' . e(content_label_lower($type)) ?></h1>
  </div>
  <div class="actions">
    <?php if ($row && $key === 'events'): ?><a class="btn ghost" href="/admin/events/<?= (int) $row['id'] ?>/registrations"><?= icon('ticket') ?> Registrations</a><?php endif ?>
    <?php if ($row && $key === 'albums'): ?><a class="btn ghost" href="/admin/albums/<?= (int) $row['id'] ?>/photos"><?= icon('image') ?> Manage photos</a><?php endif ?>
    <?php if ($row && $key === 'leader-groups'): ?><a class="btn ghost" href="/admin/leaders/new?group=<?= (int) $row['id'] ?>"><?= icon('plus') ?> Add leader</a><?php endif ?>
    <?php if ($row && $key === 'pages'): ?><a class="btn accent" href="/admin/pages/<?= (int) $row['id'] ?>/builder"><?= icon('layers') ?> <?= $isBuilderPage ? 'Open page builder' : 'Build with sections' ?></a><?php endif ?>
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
      <?php if ($isBuilderPage): ?><div class="notice"><p><strong>This page is built from sections.</strong> Edit its content in the page builder; this form holds the title, address, visibility and search settings.</p><a class="btn small" href="/admin/pages/<?= (int) $row['id'] ?>/builder"><?= icon('layers') ?> Open page builder</a></div><?php endif ?>
      <?php if ($key === 'pages' && $row === null): ?>
        <div class="field"><label for="f-start_template">Start from</label>
          <select id="f-start_template" name="start_template" aria-describedby="f-start_template-hint"><?php foreach (builder_template_choices() as $tv => $tl): ?><option value="<?= e($tv) ?>"<?= (string) ($values['start_template'] ?? '') === $tv ? ' selected' : '' ?>><?= e($tl) ?></option><?php endforeach ?></select>
          <p class="hint" id="f-start_template-hint">A starter layout opens the new page in the page builder with ready-made sections. “Blank page” gives a simple text page (you can switch it to sections later).</p></div>
      <?php endif ?>
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
          <button class="btn accent" type="submit"><?= icon('check') ?> <?= $row ? 'Save changes' : 'Create ' . e(content_label_lower($type)) ?></button>
          <a class="btn ghost" href="/admin/<?= e($key) ?>">Cancel</a>
        </div>
        <?php if ($row): ?><p class="hint muted">Last saved <?= e(fmt_datetime($row['updated_at'])) ?></p><?php endif ?>
      </div></div>
    </div>
  </div>
</form>
<?php if ($row): ?>
  <form method="post" action="/admin/<?= e($key) ?>/<?= (int) $row['id'] ?>/delete" class="panel panel-body" data-confirm="Delete this <?= e(content_label_lower($type)) ?>? This cannot be undone.">
    <?= csrf_field() ?>
    <h2>Delete</h2>
    <p class="muted">Removes this <?= e(content_label_lower($type)) ?> permanently.</p>
    <button class="btn danger-ghost" type="submit"><?= icon('trash') ?> Delete <?= e(content_label_lower($type)) ?></button>
  </form>
<?php endif ?>
<?= view('admin/_picker_template') ?>
