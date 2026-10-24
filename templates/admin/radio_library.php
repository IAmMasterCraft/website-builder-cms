<?php /** @var array $rows @var string $q @var array $values @var array $errors @var array $categories @var array $playlists @var bool $enabled @var ?int $loop_id @var array $now @var string $upload_limit */
$err = static fn (string $k): ?string => $errors[$k] ?? null;
$v = static fn (string $k, string $d = ''): string => (string) ($values[$k] ?? $d);
$catOpts = ['0' => 'No category'];
foreach ($categories as $c) { $catOpts[(string) $c['id']] = $c['name']; }
$plOpts = ['0' => 'None (off air between shows)'];
foreach ($playlists as $pl) { $plOpts[(string) $pl['id']] = $pl['name']; }
?>
<div class="page-head"><div><h1>Radio library</h1><p>Audio and video programmes for the live station and the on-demand page.</p></div>
  <div class="actions"><a class="btn ghost" href="/radio" target="_blank" rel="noopener"><?= icon('external') ?> Radio page</a><a class="btn ghost" href="/admin/radio/schedule"><?= icon('calendar') ?> Schedule</a></div></div>
<?= form_errors_html($errors) ?>
<div class="form-layout">
  <div>
    <form class="panel" id="upload" method="post" action="/admin/radio" enctype="multipart/form-data" data-radio-upload novalidate>
      <?= csrf_field() ?>
      <div class="panel-head"><h2>Upload a programme</h2></div>
      <div class="panel-body fields">
        <div class="field media-field">
          <label for="r-file">Audio or video file <span class="req" aria-hidden="true">*</span></label>
          <input type="file" id="r-file" name="file" accept="<?= e(media_accept('audio') . ',' . media_accept('video')) ?>" data-measure-file<?= $err('file') ? ' aria-invalid="true" aria-describedby="r-file-err"' : '' ?>>
          <p class="hint">MP3, M4A or OGG audio; MP4 or WebM video. The server accepts uploads up to <?= e($upload_limit) ?>.</p>
          <p class="hint duration-out" data-duration-out aria-live="polite">Your browser measures the length when you choose the file.</p>
          <?php if ($err('file')): ?><p class="error" id="r-file-err"><?= e($err('file')) ?></p><?php endif ?>
          <input type="hidden" name="duration" value="" data-duration-input>
        </div>
        <div class="field-row">
          <?= field_html('title', 'Title', 'text', $v('title'), $err('title'), ['max' => 200, 'required' => true, 'prefix' => 'r']) ?>
          <?= field_html('artist', 'Artist or presenter', 'text', $v('artist'), $err('artist'), ['max' => 160, 'prefix' => 'r']) ?>
        </div>
        <?= field_html('description', 'Description', 'textarea', $v('description'), $err('description'), ['rows' => 3, 'max' => 5000, 'prefix' => 'r']) ?>
        <div class="field-row">
          <?= field_html('category_id', 'Category', 'select', $v('category_id', '0'), $err('category_id'), ['options' => $catOpts, 'prefix' => 'r']) ?>
          <?= field_html('publish_at', 'Publish date', 'datetime-local', $v('publish_at'), $err('publish_at'), ['prefix' => 'r', 'hint' => 'Empty = now. Used for the on-demand list.']) ?>
        </div>
        <div class="field"><label for="r-cover">Cover image (optional)</label><input type="file" id="r-cover" name="cover_upload" accept="<?= e(media_accept('image')) ?>"><?php if ($err('cover')): ?><p class="error"><?= e($err('cover')) ?></p><?php endif ?></div>
        <?= field_html('on_demand', 'List it on the public radio page', 'checkbox', $v('on_demand', '1'), null, ['prefix' => 'r']) ?>
        <div class="form-actions"><button class="btn accent" type="submit"><?= icon('upload') ?> Upload</button></div>
      </div>
    </form>
    <section class="panel">
      <div class="panel-head"><h2>Programmes</h2>
        <form class="inline-search" method="get" action="/admin/radio" role="search"><label for="rlq" class="sr-only">Search programmes</label><input type="search" id="rlq" name="q" value="<?= e($q) ?>" placeholder="Search…" maxlength="100"></form></div>
      <div class="table-wrap"><table class="data">
        <thead><tr><th scope="col">Programme</th><th scope="col">Type</th><th scope="col">Length</th><th scope="col">Category</th><th scope="col">Public</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="5" class="empty-row">No programmes yet. Upload the first one above.</td></tr><?php endif ?>
        <?php foreach ($rows as $r): $cover = media_find($r['cover_media_id'] ? (int) $r['cover_media_id'] : null); ?>
          <tr>
            <td class="title-cell"><div class="member-cell"><?= $cover ? '<img class="thumb" src="' . e(media_url($cover, 400)) . '" alt="" loading="lazy">' : '<span class="thumb initial" aria-hidden="true">' . icon('radio') . '</span>' ?><span><a href="/admin/radio/media/<?= (int) $r['id'] ?>"><?= e($r['title']) ?></a><?= $r['artist'] !== '' ? '<br><span class="muted small-text">' . e($r['artist']) . '</span>' : '' ?></span></div></td>
            <td><span class="badge"><?= e($r['kind']) ?></span></td>
            <td class="tabular"><?= $r['duration'] !== null ? e(radio_fmt_duration((float) $r['duration'])) : '<span class="badge warn">unknown</span>' ?></td>
            <td><?= e($r['category_name'] ?? '—') ?></td>
            <td><?= (int) $r['on_demand'] ? 'Yes' : '<span class="muted">No</span>' ?></td>
          </tr>
        <?php endforeach ?>
        </tbody></table></div>
    </section>
  </div>
  <div class="side">
    <form class="panel" method="post" action="/admin/radio/station">
      <?= csrf_field() ?>
      <div class="panel-head"><h2>Station</h2><span class="badge <?= $enabled ? ($now['status'] === 'offline' ? 'warn' : 'ok') : '' ?>"><?= $enabled ? ($now['status'] === 'offline' ? 'off air' : 'on air') : 'switched off' ?></span></div>
      <div class="panel-body fields">
        <?php if ($enabled && $now['media']): ?><p class="small-text">Playing <strong><?= e($now['media']['title']) ?></strong> (<?= e($now['status'] === 'loop' ? 'fallback loop' : 'scheduled') ?>)<?= $now['next'] ? ', then ' . e($now['next']['title']) : '' ?>.</p><?php endif ?>
        <?= field_html('enabled', 'Station switched on', 'checkbox', $enabled ? '1' : '0', null, ['hint' => 'When on, the live player and the “Listen live” header button appear while something is on air.']) ?>
        <?= field_html('loop_playlist_id', 'Fallback loop', 'select', (string) ($loop_id ?? 0), null, ['options' => $plOpts, 'hint' => 'Plays non-stop whenever no scheduled show is on. Without it, the station is off air between shows.']) ?>
        <button class="btn ghost" type="submit"><?= icon('check') ?> Save station</button>
      </div>
    </form>
    <section class="panel"><div class="panel-head"><h2>Categories</h2></div><div class="panel-body">
      <ul class="tag-rows">
        <?php foreach ($categories as $c): ?>
          <li><span><?= e($c['name']) ?> <span class="muted small-text tabular">(<?= (int) $c['n'] ?>)</span></span>
            <form method="post" action="/admin/radio/categories/<?= (int) $c['id'] ?>/delete" data-confirm="Remove the category “<?= e($c['name']) ?>”?"><?= csrf_field() ?><button class="icon-btn small" type="submit"><?= icon('trash') ?><span class="sr-only">Remove <?= e($c['name']) ?></span></button></form></li>
        <?php endforeach ?>
      </ul>
      <form class="add-inline" method="post" action="/admin/radio/categories"><?= csrf_field() ?><label for="rc-name" class="sr-only">New category</label><input type="text" id="rc-name" name="name" maxlength="80" placeholder="New category"><button class="btn small ghost" type="submit"><?= icon('plus') ?> Add</button></form>
    </div></section>
  </div>
</div>
<script type="module" src="<?= e(asset('assets/js/radio-admin.js')) ?>"></script>
