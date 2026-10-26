<?php /** @var array $rows @var int $total @var array $filters @var int $page @var int $per @var array $people @var array $actions @var array $items */
$query = array_filter($filters, static fn ($v) => $v !== '');
$pages = (int) ceil($total / $per);
?>
<div class="page-head"><div><h1>Audit log</h1><p>Every change made in the admin area: who, what and when. Times are in the site time zone.</p></div>
  <div class="actions"><a class="btn ghost" href="<?= e(url_with('/admin/audit.csv', $query, [])) ?>"><?= icon('download') ?> Export CSV</a></div></div>
<div class="panel">
  <form class="toolbar" method="get" action="/admin/audit" role="search">
    <label for="aq" class="sr-only">Search the summaries</label>
    <input type="search" id="aq" name="q" value="<?= e($filters['q']) ?>" placeholder="Search summaries" maxlength="100">
    <label for="au" class="sr-only">Who</label>
    <select id="au" name="user"><option value="">Everyone</option><option value="system"<?= $filters['user'] === 'system' ? ' selected' : '' ?>>System / visitors</option><?php foreach ($people as $pp): ?><option value="<?= (int) $pp['id'] ?>"<?= $filters['user'] === (string) $pp['id'] ? ' selected' : '' ?>><?= e($pp['display_name']) ?></option><?php endforeach ?></select>
    <label for="aa" class="sr-only">Action</label>
    <select id="aa" name="action"><option value="">All actions</option><?php foreach ($actions as $a): ?><option<?= $filters['action'] === $a ? ' selected' : '' ?>><?= e($a) ?></option><?php endforeach ?></select>
    <label for="ai" class="sr-only">Item type</label>
    <select id="ai" name="item"><option value="">All items</option><?php foreach ($items as $i): ?><option<?= $filters['item'] === $i ? ' selected' : '' ?>><?= e($i) ?></option><?php endforeach ?></select>
    <span class="date-range"><label for="af">From</label><input type="date" id="af" name="from" value="<?= e($filters['from']) ?>">
    <label for="at">to</label><input type="date" id="at" name="to" value="<?= e($filters['to']) ?>"></span>
    <button class="btn small ghost" type="submit">Filter</button>
    <?php if ($query): ?><a class="btn small ghost" href="/admin/audit">Clear</a><?php endif ?>
  </form>
  <div class="table-wrap"><table class="data">
    <thead><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">Action</th><th scope="col">Item</th><th scope="col">Details</th><th scope="col">IP</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty-row">Nothing recorded<?= $query ? ' for these filters' : ' yet' ?>.</td></tr><?php endif ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="tabular nowrap"><?= e(fmt_datetime((string) $r['created_at'])) ?></td>
        <td><?php if ($r['user_id'] !== null): ?><a href="<?= e(url_with('/admin/audit', $query, ['user' => (string) $r['user_id'], 'page' => null])) ?>"><?= e($r['display_name'] ?? ('#' . $r['user_id'])) ?></a><?php else: ?><span class="muted">System</span><?php endif ?><?php if ($r['on_behalf_of'] !== null): ?><br><span class="badge warn">as <?= e($r['on_behalf_name'] ?? ('#' . $r['on_behalf_of'])) ?></span><?php endif ?></td>
        <td><span class="badge"><?= e($r['action']) ?></span></td>
        <td><?= e($r['item_type']) ?><?= $r['item_id'] !== null ? ' <span class="muted tabular">#' . (int) $r['item_id'] . '</span>' : '' ?></td>
        <td class="small-text"><?= e($r['summary']) ?></td>
        <td class="tabular small-text muted"><?= e($r['ip']) ?></td>
      </tr>
    <?php endforeach ?>
    </tbody></table></div>
  <div class="pager"><span class="tabular"><?= e(pluralise($total, 'entry', 'entries')) ?><?= $pages > 1 ? ' · page ' . $page . ' of ' . $pages : '' ?></span><span class="btn-row">
    <?php if ($page > 1): ?><a class="btn small ghost" href="<?= e(url_with('/admin/audit', $query, ['page' => $page - 1])) ?>">Previous</a><?php endif ?>
    <?php if ($page < $pages): ?><a class="btn small ghost" href="<?= e(url_with('/admin/audit', $query, ['page' => $page + 1])) ?>">Next</a><?php endif ?>
  </span></div>
</div>
