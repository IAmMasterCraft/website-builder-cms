<div class="page-head"><div><h1>Search</h1><p><?= $q !== '' ? e(pluralise(count($results), 'result')) . ' for “' . e($q) . '”' : 'Search titles across all content you can edit.' ?></p></div></div>
<div class="panel">
  <form class="toolbar" method="get" action="/admin/search" role="search"><label for="sq" class="sr-only">Search</label><input type="search" id="sq" name="q" value="<?= e($q) ?>" maxlength="100"><button class="btn small" type="submit">Search</button></form>
  <?php if ($results): ?>
    <div class="table-wrap"><table class="data"><thead><tr><th scope="col">Title</th><th scope="col">Type</th></tr></thead><tbody>
      <?php foreach ($results as $r): ?><tr><td class="title-cell"><a href="<?= e($r['url']) ?>"><?= e($r['title']) ?></a></td><td><span class="badge"><?= e($r['type']) ?></span></td></tr><?php endforeach ?>
    </tbody></table></div>
  <?php elseif ($q !== ''): ?><p class="empty-row">Nothing found.</p><?php endif ?>
</div>
