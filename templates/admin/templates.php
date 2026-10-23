<?php /** @var array $rows @var array $starters */ ?>
<div class="page-head"><div><h1>Templates</h1><p>Reusable sections and page layouts. Save new ones from the page builder (“Save as template”); start a new page from one under <a href="/admin/pages/new">New page</a>.</p></div></div>
<section class="panel">
  <div class="panel-head"><h2>Saved templates</h2></div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th scope="col">Name</th><th scope="col">Kind</th><th scope="col">Sections</th><th scope="col">Saved by</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="5" class="empty-row">No saved templates yet.</td></tr><?php endif ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="title-cell"><?= e($r['name']) ?></td>
          <td><span class="badge"><?= $r['kind'] === 'page' ? 'Page' : 'Section' ?></span></td>
          <td><?= e((string) $r['count']) ?> · <span class="muted"><?= e($r['types']) ?></span></td>
          <td><?= e($r['author'] ?? '—') ?><br><span class="muted tabular"><?= e(fmt_date((string) $r['created_at'])) ?></span></td>
          <td class="actions-cell">
            <form method="post" action="/admin/templates/<?= (int) $r['id'] ?>/delete" data-confirm="Delete the template “<?= e($r['name']) ?>”? Pages already built with it are not changed.">
              <?= csrf_field() ?><button class="btn tiny danger-ghost" type="submit">Delete<span class="sr-only"> <?= e($r['name']) ?></span></button>
            </form>
          </td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
</section>
<section class="panel">
  <div class="panel-head"><h2>Starter page templates</h2></div>
  <div class="panel-body">
    <ul class="starter-list">
      <?php foreach ($starters as $k => $t): ?>
        <li><strong><?= e($t['name']) ?></strong><span class="muted"><?= e($t['description']) ?></span>
          <span class="starter-thumbs" aria-hidden="true"><?php foreach (array_slice($t['sections'], 0, 6) as $s): ?><svg viewBox="0 0 120 80" focusable="false"><use href="<?= e(asset('assets/img/sections.svg')) ?>#sec-<?= e($s['type']) ?>"></use></svg><?php endforeach ?></span></li>
      <?php endforeach ?>
    </ul>
  </div>
</section>
