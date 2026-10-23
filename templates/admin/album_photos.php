<div class="page-head">
  <div><p class="crumbs"><a href="/admin/albums">Gallery</a> / <a href="/admin/albums/<?= (int) $album['id'] ?>/edit"><?= e($album['title']) ?></a> /</p><h1>Photos</h1><p class="tabular"><?= e(pluralise(count($photos), 'photo')) ?></p></div>
  <div class="actions"><a class="btn ghost" href="/gallery/<?= e($album['slug']) ?>" target="_blank" rel="noopener"><?= icon('eye') ?> View album</a></div>
</div>
<div class="panel">
  <form class="upload-box" method="post" action="/admin/albums/<?= (int) $album['id'] ?>/photos" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="field"><label for="ph">Add photos</label><input type="file" id="ph" name="photos[]" multiple required accept="<?= e(media_accept('image')) ?>"><p class="hint">Up to 50 at a time. JPEG, PNG, WebP or GIF, 10 MB each.</p></div>
    <span></span><span></span>
    <button class="btn accent" type="submit"><?= icon('upload') ?> Upload</button>
  </form>
  <?php if ($photos): ?>
    <form id="captions" method="post" action="/admin/albums/<?= (int) $album['id'] ?>/photos/save"><?= csrf_field() ?></form>
    <ul class="photo-list">
      <?php foreach ($photos as $i => $ph): $m = media_find((int) $ph['media_id']); ?>
        <li class="photo-card" id="photo-<?= (int) $ph['id'] ?>">
          <?= $m ? img_html($m, '14rem', '', '', true, 400) : '' ?>
          <div class="fields">
            <div class="field"><label for="cap-<?= (int) $ph['id'] ?>">Caption</label><input form="captions" type="text" id="cap-<?= (int) $ph['id'] ?>" name="caption[<?= (int) $ph['id'] ?>]" value="<?= e($ph['caption']) ?>" maxlength="300"></div>
            <div class="field"><label for="alt-<?= (int) $ph['id'] ?>">Alt text</label><input form="captions" type="text" id="alt-<?= (int) $ph['id'] ?>" name="alt[<?= (int) $ph['id'] ?>]" value="<?= e($ph['alt']) ?>" maxlength="300"></div>
          </div>
          <div class="btn-row">
            <form class="inline-form" method="post" action="/admin/albums/<?= (int) $album['id'] ?>/photos/<?= (int) $ph['id'] ?>/move"><?= csrf_field() ?><input type="hidden" name="dir" value="up"><button class="btn tiny ghost" type="submit"<?= $i === 0 ? ' disabled' : '' ?>><?= icon('arrow-up') ?><span class="sr-only">Move photo <?= $i + 1 ?> earlier</span></button></form>
            <form class="inline-form" method="post" action="/admin/albums/<?= (int) $album['id'] ?>/photos/<?= (int) $ph['id'] ?>/move"><?= csrf_field() ?><input type="hidden" name="dir" value="down"><button class="btn tiny ghost" type="submit"<?= $i === count($photos) - 1 ? ' disabled' : '' ?>><?= icon('arrow-down') ?><span class="sr-only">Move photo <?= $i + 1 ?> later</span></button></form>
            <form class="inline-form" method="post" action="/admin/albums/<?= (int) $album['id'] ?>/photos/<?= (int) $ph['id'] ?>/delete" data-confirm="Remove this photo from the album?"><?= csrf_field() ?><input type="hidden" name="delete_file" value="1"><button class="btn tiny danger-ghost" type="submit"><?= icon('trash') ?><span class="sr-only">Remove photo <?= $i + 1 ?></span></button></form>
          </div>
        </li>
      <?php endforeach ?>
    </ul>
    <div class="panel-body"><button class="btn accent" type="submit" form="captions">Save captions and alt text</button></div>
  <?php else: ?><p class="empty-row">No photos yet. Upload some above.</p><?php endif ?>
</div>
