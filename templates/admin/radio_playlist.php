<?php /** @var array $pl @var array $items @var ?float $duration @var array $library @var int $slots @var bool $is_loop */
$opts = [];
foreach ($library as $l) { $opts[(string) $l['id']] = $l['title'] . ($l['artist'] !== '' ? ' — ' . $l['artist'] : '') . ' (' . radio_fmt_duration($l['duration'] !== null ? (float) $l['duration'] : null) . ')'; }
$last = count($items) - 1;
?>
<div class="page-head"><div><p class="crumbs"><a href="/admin/radio/playlists">Playlists</a></p><h1><?= e($pl['name']) ?></h1>
  <p><?= e(pluralise(count($items), 'item')) ?> · total <strong class="tabular"><?= e(radio_fmt_duration($duration)) ?></strong><?= $is_loop ? ' · <span class="badge ok">fallback loop</span>' : '' ?><?= $slots ? ' · <span class="badge members">' . e(pluralise($slots, 'schedule slot')) . '</span>' : '' ?></p></div>
  <div class="actions"><form method="post" action="/admin/radio/playlists/<?= (int) $pl['id'] ?>/delete" data-confirm="Delete this playlist?"><?= csrf_field() ?><button class="btn danger-ghost" type="submit"<?= $slots || $is_loop ? ' disabled title="Scheduled or used as the loop"' : '' ?>><?= icon('trash') ?> Delete</button></form></div></div>
<?php if ($slots): ?><p class="notice-box">This playlist is scheduled. If a change makes it longer, the slot must still fit before the next show, or the change is refused.</p><?php endif ?>
<form method="post" action="/admin/radio/playlists/<?= (int) $pl['id'] ?>">
  <?= csrf_field() ?>
  <button type="submit" name="op" value="save" class="sr-only" tabindex="-1" aria-hidden="true">Save</button>
  <div class="form-layout">
    <section class="panel">
      <div class="panel-head"><h2>Running order</h2></div>
      <?php if (!$items): ?><p class="panel-body muted">Empty. Add programmes from the library.</p><?php endif ?>
      <ol class="run-order">
        <?php foreach ($items as $i => $it): ?>
          <li>
            <span class="tabular run-num"><?= $i + 1 ?></span>
            <span class="run-title"><strong><?= e($it['title']) ?></strong><?= $it['artist'] !== '' ? ' <span class="muted">' . e($it['artist']) . '</span>' : '' ?> <span class="badge"><?= e($it['kind']) ?></span></span>
            <span class="tabular run-len"><?= $it['duration'] !== null ? e(radio_fmt_duration((float) $it['duration'])) : '<span class="badge warn">unknown</span>' ?></span>
            <span class="row-actions">
              <button class="icon-btn small" type="submit" name="op" value="up:<?= $i ?>"<?= $i === 0 ? ' disabled' : '' ?>><?= icon('arrow-up') ?><span class="sr-only">Move <?= e($it['title']) ?> up</span></button>
              <button class="icon-btn small" type="submit" name="op" value="down:<?= $i ?>"<?= $i === $last ? ' disabled' : '' ?>><?= icon('arrow-down') ?><span class="sr-only">Move <?= e($it['title']) ?> down</span></button>
              <button class="icon-btn small" type="submit" name="op" value="remove:<?= $i ?>"><?= icon('trash') ?><span class="sr-only">Remove <?= e($it['title']) ?></span></button>
            </span>
          </li>
        <?php endforeach ?>
      </ol>
      <div class="panel-body add-block">
        <label for="pl-add">Add a programme</label>
        <select id="pl-add" name="radio_media_id"><?php foreach ($opts as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach ?></select>
        <button class="btn ghost" type="submit" name="op" value="add"<?= $opts ? '' : ' disabled' ?>><?= icon('plus') ?> Add</button>
      </div>
    </section>
    <section class="panel side"><div class="panel-head"><h2>Details</h2></div><div class="panel-body fields">
      <?= field_html('name', 'Name', 'text', $pl['name'], null, ['max' => 120, 'required' => true]) ?>
      <?= field_html('description', 'Description', 'text', $pl['description'], null, ['max' => 500]) ?>
      <button class="btn accent" type="submit" name="op" value="save"><?= icon('check') ?> Save</button>
    </div></section>
  </div>
</form>
