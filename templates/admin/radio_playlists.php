<?php /** @var array $rows @var ?int $loop_id */ ?>
<div class="page-head"><div><h1>Playlists</h1><p>Ordered lists of programmes. Schedule them as one slot, or use one as the fallback loop.</p></div></div>
<div class="form-layout">
  <div class="panel"><div class="table-wrap"><table class="data">
    <thead><tr><th scope="col">Playlist</th><th scope="col">Items</th><th scope="col">Length</th><th scope="col">Used</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="4" class="empty-row">No playlists yet.</td></tr><?php endif ?>
    <?php foreach ($rows as $r): ?>
      <tr><td class="title-cell"><a href="/admin/radio/playlists/<?= (int) $r['id'] ?>"><?= e($r['name']) ?></a><?= $r['description'] !== '' ? '<br><span class="muted small-text">' . e($r['description']) . '</span>' : '' ?></td>
        <td class="tabular"><?= (int) $r['n'] ?></td>
        <td class="tabular"><?= $r['duration'] !== null ? e(radio_fmt_duration($r['duration'])) : ((int) $r['n'] ? '<span class="badge warn">unknown items</span>' : '—') ?></td>
        <td><?= $loop_id === (int) $r['id'] ? '<span class="badge ok">fallback loop</span> ' : '' ?><?= (int) $r['slots'] ? '<span class="badge members">' . e(pluralise((int) $r['slots'], 'slot')) . '</span>' : '' ?></td></tr>
    <?php endforeach ?>
    </tbody></table></div></div>
  <form class="panel side" method="post" action="/admin/radio/playlists">
    <?= csrf_field() ?>
    <div class="panel-head"><h2>New playlist</h2></div>
    <div class="panel-body fields">
      <?= field_html('name', 'Name', 'text', '', null, ['max' => 120, 'required' => true, 'prefix' => 'np']) ?>
      <?= field_html('description', 'Description', 'text', '', null, ['max' => 500, 'prefix' => 'np']) ?>
      <button class="btn accent" type="submit"><?= icon('plus') ?> Create</button>
    </div>
  </form>
</div>
