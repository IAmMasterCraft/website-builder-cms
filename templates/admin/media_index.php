<?php $query = array_filter(['q' => $q, 'kind' => $kind, 'folder' => $folder]); $pages = (int) ceil($total / $per); ?>
<div class="page-head"><div><h1>Media library</h1><p class="tabular"><?= e(pluralise($total, 'file')) ?> · images are resized to 400/800/1600 px WebP automatically</p></div></div>
<div class="panel">
  <form class="upload-box" method="post" action="/admin/media/upload" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="field"><label for="up-files">Upload files</label><input type="file" id="up-files" name="files[]" multiple required accept="<?= e(media_accept()) ?>" aria-describedby="up-hint"><p class="hint" id="up-hint">Images up to 10 MB, documents 20 MB, audio 100 MB, video 300 MB.</p></div>
    <div class="field"><label for="up-folder">Folder</label><input type="text" id="up-folder" name="folder" list="folders" maxlength="80"><datalist id="folders"><?php foreach ($folders as $fo): ?><option value="<?= e($fo) ?>"></option><?php endforeach ?></datalist></div>
    <div class="field"><label for="up-vis">Who can open the file</label><select id="up-vis" name="visibility"><option value="public">Everyone</option><option value="members">Members only</option></select></div>
    <button class="btn accent" type="submit"><?= icon('upload') ?> Upload</button>
  </form>
  <form class="toolbar" method="get" action="/admin/media" role="search">
    <label for="mq" class="sr-only">Search media</label><input type="search" id="mq" name="q" value="<?= e($q) ?>" placeholder="Search files…" maxlength="100">
    <label for="mk" class="sr-only">Type</label><select id="mk" name="kind"><option value="">All types</option><?php foreach (\Admin\Media::KINDS as $k => $l): ?><option value="<?= e($k) ?>"<?= $kind === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select>
    <label for="mf" class="sr-only">Folder</label><select id="mf" name="folder"><option value="">All folders</option><?php foreach ($folders as $fo): ?><option value="<?= e($fo) ?>"<?= $folder === $fo ? ' selected' : '' ?>><?= e($fo) ?></option><?php endforeach ?></select>
    <button class="btn small ghost" type="submit">Filter</button>
  </form>
  <?php if ($rows): ?>
    <ul class="media-grid">
      <?php foreach ($rows as $m): ?>
        <li class="media-item">
          <div class="thumb"><?= $m['kind'] === 'image' ? img_html($m, '10rem', '', '', true, 400) : e(pathinfo((string) $m['path'], PATHINFO_EXTENSION)) ?></div>
          <div class="meta"><a href="/admin/media/<?= (int) $m['id'] ?>"><?= e($m['title'] ?: $m['original_name']) ?></a><span class="tabular"><?= e(number_format($m['size'] / 1024, 0)) ?> KB<?= $m['width'] ? ' · ' . (int) $m['width'] . '×' . (int) $m['height'] : '' ?><?= $m['visibility'] !== 'public' ? ' · ' . e($m['visibility']) : '' ?></span></div>
        </li>
      <?php endforeach ?>
    </ul>
  <?php else: ?><p class="empty-row">No files found.</p><?php endif ?>
  <?php if ($pages > 1): ?>
    <div class="pager"><span>Page <?= (int) $page ?> of <?= $pages ?></span><span class="btn-row">
      <?php if ($page > 1): ?><a class="btn small ghost" href="<?= e(url_with('/admin/media', $query, ['page' => $page - 1])) ?>">Previous</a><?php endif ?>
      <?php if ($page < $pages): ?><a class="btn small ghost" href="<?= e(url_with('/admin/media', $query, ['page' => $page + 1])) ?>">Next</a><?php endif ?>
    </span></div>
  <?php endif ?>
</div>
