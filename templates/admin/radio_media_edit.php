<?php /** @var array $r @var array $values @var array $errors @var array $usage @var array $categories @var string $url @var ?array $cover */
$err = static fn (string $k): ?string => $errors[$k] ?? null;
$v = static fn (string $k, $d) => (string) ($values[$k] ?? $d);
$catOpts = ['0' => 'No category'];
foreach ($categories as $c) { $catOpts[(string) $c['id']] = $c['name']; }
?>
<div class="page-head"><div><p class="crumbs"><a href="/admin/radio">Radio library</a></p><h1><?= e($r['title']) ?></h1>
  <p><span class="badge"><?= e($r['kind']) ?></span> <?= e($r['original_name']) ?> · <?= e(number_format($r['size'] / 1048576, 1)) ?> MB · length <strong class="tabular"><?= e(radio_fmt_duration($r['duration'] !== null ? (float) $r['duration'] : null)) ?></strong></p></div>
  <div class="actions"><form method="post" action="/admin/radio/media/<?= (int) $r['id'] ?>/delete" data-confirm="Delete “<?= e($r['title']) ?>” and its file?"><?= csrf_field() ?><button class="btn danger-ghost" type="submit"<?= $usage ? ' disabled title="In use"' : '' ?>><?= icon('trash') ?> Delete</button></form></div></div>
<?= form_errors_html($errors) ?>
<?php if ($r['duration'] === null): ?><div class="notice-box">The length of this programme is unknown, so it cannot be scheduled. Press “Measure duration” below: your browser reads it from the file.</div><?php endif ?>
<form method="post" action="/admin/radio/media/<?= (int) $r['id'] ?>" enctype="multipart/form-data" novalidate>
  <?= csrf_field() ?>
  <div class="form-layout">
    <section class="panel"><div class="panel-body fields">
      <div class="field-row">
        <?= field_html('title', 'Title', 'text', $v('title', $r['title']), $err('title'), ['max' => 200, 'required' => true]) ?>
        <?= field_html('artist', 'Artist or presenter', 'text', $v('artist', $r['artist']), $err('artist'), ['max' => 160]) ?>
      </div>
      <?= field_html('description', 'Description', 'textarea', $v('description', (string) $r['description']), $err('description'), ['rows' => 4, 'max' => 5000]) ?>
      <div class="field-row">
        <?= field_html('category_id', 'Category', 'select', $v('category_id', (string) ($r['category_id'] ?? 0)), $err('category_id'), ['options' => $catOpts]) ?>
        <?= field_html('publish_at', 'Publish date', 'datetime-local', $v('publish_at', utc_to_input($r['publish_at'])), $err('publish_at')) ?>
      </div>
      <?= field_html('on_demand', 'List it on the public radio page', 'checkbox', $v('on_demand', (string) $r['on_demand'])) ?>
      <div class="form-actions"><button class="btn accent" type="submit"><?= icon('check') ?> Save</button></div>
    </div></section>
    <div class="side">
      <section class="panel"><div class="panel-head"><h2>Preview and length</h2></div><div class="panel-body fields">
        <?php if ($r['kind'] === 'video'): ?><video controls preload="metadata" src="<?= e($url) ?>" class="admin-media-preview" data-measure-src></video>
        <?php else: ?><audio controls preload="metadata" src="<?= e($url) ?>" class="admin-media-preview" data-measure-src></audio><?php endif ?>
        <input type="hidden" name="duration" value="" data-duration-input>
        <button type="button" class="btn ghost" data-measure-button hidden><?= icon('clock') ?> Measure duration</button>
        <p class="hint" data-duration-out aria-live="polite"><?= $usage && $r['duration'] !== null ? 'In use: the length is locked while scheduled.' : 'The length is read by your browser from the file.' ?></p>
        <?php if ($err('duration')): ?><p class="error"><?= e($err('duration')) ?></p><?php endif ?>
      </div></section>
      <section class="panel"><div class="panel-head"><h2>Cover</h2></div><div class="panel-body fields">
        <div class="media-preview square"><?= $cover ? img_html($cover, '12rem', '', '', true, 400) : '<span>No cover</span>' ?></div>
        <label for="rm-cover" class="hint">Upload a new cover</label><input type="file" id="rm-cover" name="cover_upload" accept="<?= e(media_accept('image')) ?>">
        <?php if ($cover): ?><label class="check"><input type="checkbox" name="cover_remove" value="1"> Remove cover</label><?php endif ?>
        <?php if ($err('cover')): ?><p class="error"><?= e($err('cover')) ?></p><?php endif ?>
      </div></section>
      <section class="panel"><div class="panel-head"><h2>Used in</h2></div><div class="panel-body">
        <?php if ($usage): ?><ul><?php foreach ($usage as $u): ?><li><?= e($u) ?></li><?php endforeach ?></ul><p class="hint muted">It cannot be deleted while it is used.</p><?php else: ?><p class="muted">Not in any playlist or schedule.</p><?php endif ?>
      </div></section>
    </div>
  </div>
</form>
<script type="module" src="<?= e(asset('assets/js/radio-admin.js')) ?>"></script>
