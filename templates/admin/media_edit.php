<?php /** @var array $m @var array $usage */ ?>
<div class="page-head"><div><p class="crumbs"><a href="/admin/media">Media library</a> /</p><h1><?= e($m['title'] ?: $m['original_name']) ?></h1></div>
  <div class="actions"><a class="btn ghost" href="<?= e(media_original_url($m)) ?>" target="_blank" rel="noopener"><?= icon('external') ?> Open original</a></div></div>
<div class="form-layout">
  <div>
    <div class="panel"><div class="panel-body">
      <?php if ($m['kind'] === 'image'): ?><?= img_html($m, '(min-width: 64rem) 50rem, 100vw', $m['alt'], '', false, 1600) ?>
      <?php else: ?><p><?= icon('file') ?> <?= e($m['original_name']) ?></p><?php endif ?>
    </div></div>
    <form class="panel panel-body fields" method="post" action="/admin/media/<?= (int) $m['id'] ?>" novalidate>
      <?= csrf_field() ?>
      <div class="field"><label for="m-title">Title</label><input type="text" id="m-title" name="title" value="<?= e($m['title']) ?>" maxlength="200" required<?= isset($errors['title']) ? ' aria-invalid="true" aria-describedby="m-title-err"' : '' ?>><?php if (isset($errors['title'])): ?><p class="error" id="m-title-err"><?= e($errors['title']) ?></p><?php endif ?></div>
      <?php if ($m['kind'] === 'image'): ?>
      <div class="field"><label for="m-alt">Alt text</label><textarea id="m-alt" name="alt" rows="2" maxlength="300" aria-describedby="m-alt-hint"><?= e($m['alt']) ?></textarea><p class="hint" id="m-alt-hint">Describe the image for people who cannot see it. Leave empty only for decorative images.</p></div>
      <?php endif ?>
      <div class="field"><label for="m-folder">Folder</label><input type="text" id="m-folder" name="folder" value="<?= e($m['folder']) ?>" maxlength="80"></div>
      <div class="form-actions"><button class="btn accent" type="submit">Save details</button></div>
    </form>
  </div>
  <div class="side">
    <div class="panel"><div class="panel-head"><h2>Details</h2></div><div class="panel-body">
      <p class="tabular"><?= e($m['mime']) ?><br><?= e(number_format($m['size'] / 1024, 0)) ?> KB<?= $m['width'] ? '<br>' . (int) $m['width'] . ' × ' . (int) $m['height'] . ' px' : '' ?><br>Visible to: <span class="badge <?= e($m['visibility']) ?>"><?= e($m['visibility']) ?></span><br>Uploaded <?= e(fmt_datetime($m['created_at'])) ?></p>
      <?php if ($m['variants']): ?><p class="muted">Sizes: <?= e(implode(', ', array_map(static fn ($w) => $w . 'px', array_keys($m['variants'])))) ?></p><?php endif ?>
    </div></div>
    <div class="panel"><div class="panel-head"><h2>Used on</h2></div><div class="panel-body">
      <?php if ($usage): ?><ul><?php foreach ($usage as [$label, $title, $url]): ?><li><?= e($label) ?>: <a href="<?= e($url) ?>"><?= e($title) ?></a></li><?php endforeach ?></ul>
      <?php else: ?><p class="muted">Not used anywhere yet.</p><?php endif ?>
    </div></div>
    <form class="panel panel-body fields" method="post" action="/admin/media/<?= (int) $m['id'] ?>/replace" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="field"><label for="m-file">Replace file</label><input type="file" id="m-file" name="file" required accept="<?= e(media_accept((string) $m['kind'])) ?>"><p class="hint">Must be the same kind of file. Everything using it updates.</p></div>
      <button class="btn ghost" type="submit"><?= icon('upload') ?> Replace</button>
    </form>
    <form class="panel panel-body" method="post" action="/admin/media/<?= (int) $m['id'] ?>/delete" data-confirm="Delete this file permanently?">
      <?= csrf_field() ?>
      <button class="btn danger-ghost" type="submit"<?= $usage ? ' disabled aria-describedby="del-hint"' : '' ?>><?= icon('trash') ?> Delete file</button>
      <?php if ($usage): ?><p class="hint" id="del-hint">Files that are in use cannot be deleted.</p><?php endif ?>
    </form>
  </div>
</div>
