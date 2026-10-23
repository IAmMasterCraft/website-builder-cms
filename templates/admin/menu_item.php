<div class="page-head"><div><p class="crumbs"><a href="/admin/menus">Menus</a> / <a href="/admin/menus/<?= (int) $menu['id'] ?>"><?= e($menu['name']) ?></a> /</p><h1>Edit “<?= e($item['label']) ?>”</h1></div></div>
<?php if ((int) $item['is_broken'] === 1): ?><p class="notice-box">The page or item this link pointed to was deleted. Choose a new target and save to fix it.</p><?php endif ?>
<form class="panel panel-body fields" method="post" action="/admin/menus/<?= (int) $menu['id'] ?>/items/<?= (int) $item['id'] ?>">
  <?= csrf_field() ?>
  <?= view('admin/_menu_item_fields', ['values' => $values, 'errors' => $errors, 'pages' => $pages, 'choices' => $choices, 'parents' => $parents, 'prefix' => 'it-']) ?>
  <div class="form-actions"><button class="btn accent" type="submit">Save item</button><a class="btn ghost" href="/admin/menus/<?= (int) $menu['id'] ?>">Cancel</a></div>
</form>
