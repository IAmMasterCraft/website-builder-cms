<?php /** @var array $type @var array $rows @var array $cells @var array $filters @var array $filter_options */
$key = $type['key'];
$query = array_filter($filters, static fn ($v) => $v !== '');
$pages = (int) ceil($total / $per);
?>
<div class="page-head">
  <div><h1><?= e($type['plural']) ?></h1><p class="tabular"><?= e(pluralise($total, strtolower($type['label']))) ?></p></div>
  <div class="actions">
    <?php if ($key === 'leaders'): ?><a class="btn ghost" href="/admin/leader-groups"><?= icon('layers') ?> Groups</a><?php endif ?>
    <?php if ($key === 'posts'): ?><a class="btn ghost" href="/admin/categories"><?= icon('tag') ?> Categories</a><?php endif ?>
    <a class="btn accent" href="/admin/<?= e($key) ?>/new"><?= icon('plus') ?> New <?= e(strtolower($type['label'])) ?></a>
  </div>
</div>
<div class="panel">
  <form class="toolbar" method="get" action="/admin/<?= e($key) ?>" role="search">
    <label for="lq" class="sr-only">Search <?= e(strtolower($type['plural'])) ?></label>
    <input type="search" id="lq" name="q" value="<?= e($filters['q']) ?>" placeholder="Search…" maxlength="100">
    <?php foreach ($filter_options as $fk => $opts): ?>
      <label for="f-<?= e($fk) ?>" class="sr-only">Filter by <?= e(str_replace('_id', '', $fk)) ?></label>
      <select id="f-<?= e($fk) ?>" name="<?= e($fk) ?>">
        <option value="">All <?= e(['status' => 'statuses', 'category_id' => 'categories', 'category' => 'categories', 'when' => 'dates', 'visibility' => 'visibilities', 'group_id' => 'groups', 'year' => 'years'][$fk] ?? $fk) ?></option>
        <?php foreach ($opts as $v => $l): ?><option value="<?= e((string) $v) ?>"<?= ($filters[$fk] ?? '') === (string) $v ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach ?>
      </select>
    <?php endforeach ?>
    <button class="btn small ghost" type="submit">Filter</button>
    <?php if ($query): ?><a class="btn small ghost" href="/admin/<?= e($key) ?>">Clear</a><?php endif ?>
  </form>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th scope="col"><?= e($type['title'] === 'question' ? 'Question' : ucfirst($type['title'])) ?></th><?php foreach ($type['columns'] as $label): ?><th scope="col"><?= e($label) ?></th><?php endforeach ?><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="<?= count($type['columns']) + 2 ?>" class="empty-row">Nothing here yet. <a href="/admin/<?= e($key) ?>/new">Create the first one</a>.</td></tr><?php endif ?>
      <?php foreach ($rows as $i => $r): ?>
        <tr>
          <td class="title-cell"><a href="/admin/<?= e($key) ?>/<?= (int) $r['id'] ?>/edit"><?= e(mb_strimwidth((string) $r[$type['title']], 0, 90, '…')) ?></a></td>
          <?php foreach ($cells[$i] as [$text, $kind, $href]): ?>
            <td<?= $kind === 'date' ? ' class="tabular"' : '' ?>><?php if ($kind === 'badge'): ?><span class="badge <?= e($text) ?>"><?= e($text) ?></span><?php elseif ($kind === 'link'): ?><a href="<?= e((string) $href) ?>" class="tabular"><?= e($text) ?></a><?php else: ?><?= e($text) ?><?php endif ?></td>
          <?php endforeach ?>
          <td class="actions-cell"><?php if ($key === 'pages'): ?><a class="btn tiny ghost" href="/admin/pages/<?= (int) $r['id'] ?>/builder">Build<span class="sr-only"> <?= e((string) $r['title']) ?></span></a> <?php endif ?><a class="btn tiny ghost" href="/admin/<?= e($key) ?>/<?= (int) $r['id'] ?>/edit">Edit<span class="sr-only"> <?= e((string) $r[$type['title']]) ?></span></a></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <div class="pager"><span class="tabular">Page <?= (int) $page ?> of <?= $pages ?></span><span class="btn-row">
      <?php if ($page > 1): ?><a class="btn small ghost" href="<?= e(url_with('/admin/' . $key, $query, ['page' => $page - 1])) ?>">Previous</a><?php endif ?>
      <?php if ($page < $pages): ?><a class="btn small ghost" href="<?= e(url_with('/admin/' . $key, $query, ['page' => $page + 1])) ?>">Next</a><?php endif ?>
    </span></div>
  <?php endif ?>
</div>
