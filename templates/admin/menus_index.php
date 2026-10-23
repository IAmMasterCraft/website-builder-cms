<div class="page-head"><div><h1>Menus</h1><p>Build menus and choose where they appear. Each location shows one menu.</p></div></div>
<div class="form-layout">
  <div class="panel">
    <div class="table-wrap"><table class="data">
      <thead><tr><th scope="col">Menu</th><th scope="col">Location</th><th scope="col">Items</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>
      <?php if (!$menus): ?><tr><td colspan="4" class="empty-row">No menus yet. The site shows a default main menu until you assign one.</td></tr><?php endif ?>
      <?php foreach ($menus as $m): ?>
        <tr>
          <td class="title-cell"><a href="/admin/menus/<?= (int) $m['id'] ?>"><?= e($m['name']) ?></a></td>
          <td><?= $m['location'] ? e(menu_locations()[$m['location']] ?? $m['location']) : '<span class="muted">Not shown</span>' ?></td>
          <td class="tabular"><?= (int) $m['n'] ?><?php if ((int) $m['broken'] > 0): ?> <span class="badge broken"><?= icon('alert') ?> <?= (int) $m['broken'] ?> broken</span><?php endif ?></td>
          <td class="actions-cell"><a class="btn tiny ghost" href="/admin/menus/<?= (int) $m['id'] ?>">Edit<span class="sr-only"> <?= e($m['name']) ?></span></a></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table></div>
  </div>
  <form class="panel panel-body fields side" method="post" action="/admin/menus">
    <?= csrf_field() ?>
    <h2>New menu</h2>
    <div class="field"><label for="mn">Name</label><input type="text" id="mn" name="name" required maxlength="80"></div>
    <div class="field"><label for="ml">Location</label><select id="ml" name="location"><option value="">Not shown yet</option><?php foreach (menu_locations() as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach ?></select></div>
    <button class="btn accent" type="submit"><?= icon('plus') ?> Create menu</button>
  </form>
</div>
