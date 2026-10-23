<?php $parents = array_values(array_filter($items, static fn ($i) => $i['parent_id'] === null)); ?>
<div class="page-head">
  <div><p class="crumbs"><a href="/admin/menus">Menus</a> /</p><h1><?= e($menu['name']) ?></h1><p><?= $menu['location'] ? 'Shown in: ' . e(menu_locations()[$menu['location']] ?? $menu['location']) : 'Not shown on the site yet.' ?></p></div>
  <div class="actions"><a class="btn ghost" href="/" target="_blank" rel="noopener"><?= icon('eye') ?> Preview site</a></div>
</div>
<div class="form-layout">
  <div>
    <div class="panel">
      <div class="panel-head"><h2>Items</h2><span class="muted">Two levels: top-level items and their sub-items</span></div>
      <?php if ($items): ?>
        <ol class="menu-tree">
          <?php foreach ($items as $it): ?>
            <li id="item-<?= (int) $it['id'] ?>" class="<?= $it['depth'] ? 'child' : '' ?><?= (int) $it['is_hidden'] === 1 ? ' is-hidden' : '' ?>">
              <span><span class="label"><?= e($it['label']) ?></span><br><span class="target"><?= e(menu_item_target_label($it)) ?></span></span>
              <?php if ((int) $it['is_broken'] === 1): ?><span class="badge broken"><?= icon('alert') ?> Target deleted: edit or remove</span><?php endif ?>
              <?php if ((int) $it['is_hidden'] === 1): ?><span class="badge hidden">Hidden</span><?php endif ?>
              <span class="item-actions">
                <form class="inline-form" method="post" action="/admin/menus/<?= (int) $menu['id'] ?>/items/<?= (int) $it['id'] ?>/move"><?= csrf_field() ?><input type="hidden" name="dir" value="up"><button class="btn tiny ghost" type="submit"><?= icon('arrow-up') ?><span class="sr-only">Move <?= e($it['label']) ?> up</span></button></form>
                <form class="inline-form" method="post" action="/admin/menus/<?= (int) $menu['id'] ?>/items/<?= (int) $it['id'] ?>/move"><?= csrf_field() ?><input type="hidden" name="dir" value="down"><button class="btn tiny ghost" type="submit"><?= icon('arrow-down') ?><span class="sr-only">Move <?= e($it['label']) ?> down</span></button></form>
                <form class="inline-form" method="post" action="/admin/menus/<?= (int) $menu['id'] ?>/items/<?= (int) $it['id'] ?>/toggle"><?= csrf_field() ?><button class="btn tiny ghost" type="submit"><?= icon((int) $it['is_hidden'] === 1 ? 'eye' : 'eye-off') ?><span class="sr-only"><?= (int) $it['is_hidden'] === 1 ? 'Show' : 'Hide' ?> <?= e($it['label']) ?></span></button></form>
                <a class="btn tiny ghost" href="/admin/menus/<?= (int) $menu['id'] ?>/items/<?= (int) $it['id'] ?>">Edit<span class="sr-only"> <?= e($it['label']) ?></span></a>
                <form class="inline-form" method="post" action="/admin/menus/<?= (int) $menu['id'] ?>/items/<?= (int) $it['id'] ?>/delete" data-confirm="Delete this item<?= $it['depth'] ? '' : ' and its sub-items' ?>?"><?= csrf_field() ?><button class="btn tiny danger-ghost" type="submit"><?= icon('trash') ?><span class="sr-only">Delete <?= e($it['label']) ?></span></button></form>
              </span>
            </li>
          <?php endforeach ?>
        </ol>
      <?php else: ?><p class="empty-row">No items yet. Add the first one.</p><?php endif ?>
    </div>
    <form class="panel panel-body fields" method="post" action="/admin/menus/<?= (int) $menu['id'] ?>/items" id="add-item">
      <?= csrf_field() ?>
      <h2>Add an item</h2>
      <?= view('admin/_menu_item_fields', ['values' => $old, 'errors' => $errors, 'pages' => $pages, 'choices' => $choices, 'parents' => $parents, 'prefix' => 'new-']) ?>
      <div class="form-actions"><button class="btn accent" type="submit"><?= icon('plus') ?> Add item</button></div>
    </form>
  </div>
  <div class="side">
    <form class="panel panel-body fields" method="post" action="/admin/menus/<?= (int) $menu['id'] ?>">
      <?= csrf_field() ?>
      <h2>Menu settings</h2>
      <div class="field"><label for="m-name">Name</label><input type="text" id="m-name" name="name" value="<?= e($menu['name']) ?>" required maxlength="80"><p class="hint">Footer menus use this as the column heading.</p></div>
      <div class="field"><label for="m-loc">Location</label><select id="m-loc" name="location"><option value="">Not shown</option><?php foreach (menu_locations() as $k => $l): ?><option value="<?= e($k) ?>"<?= $menu['location'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select></div>
      <button class="btn" type="submit">Save menu</button>
    </form>
    <form class="panel panel-body" method="post" action="/admin/menus/<?= (int) $menu['id'] ?>/delete" data-confirm="Delete this whole menu and all its items?">
      <?= csrf_field() ?><button class="btn danger-ghost" type="submit"><?= icon('trash') ?> Delete menu</button>
    </form>
  </div>
</div>
