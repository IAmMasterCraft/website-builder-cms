<?php /** @var array $rows @var int $total @var array $counts @var string $reason @var string $q @var int $page @var int $per @var array $events @var array $review @var int $review_total @var array $stats7 @var array $stats30 */
$pages = (int) ceil($total / $per);
$labels = ['unsubscribe' => 'Unsubscribed', 'complaint' => 'Complaint', 'hard_bounce' => 'Hard bounce', 'soft_bounce' => 'Repeated soft bounces'];
$badge = ['unsubscribe' => '', 'complaint' => 'bad', 'hard_bounce' => 'bad', 'soft_bounce' => 'warn'];
$tabs = ['' => ['All', $counts[''] ?? 0]];
foreach ($labels as $k => $l) { $tabs[$k] = [$l, $counts[$k] ?? 0]; }
$pct = static fn (float $r): string => number_format($r * 100, 2) . '%';
?>
<div class="page-head"><div><h1>Suppression list</h1><p>Addresses that receive no bulk email: they unsubscribed, reported an email as spam, or bounced. Bounced addresses get no automatic emails either.</p></div>
  <div class="actions"><a class="btn ghost" href="/admin/email-log"><?= icon('clock') ?> Delivery log</a></div></div>
<div class="stat-grid">
  <div class="stat-card<?= $stats7['bounce_rate'] > BOUNCE_RATE_WARN ? ' attention' : '' ?>"><span class="label"><?= icon('alert') ?>Bounce rate (7 days)</span><span class="value"><?= e($pct($stats7['bounce_rate'])) ?></span></div>
  <div class="stat-card<?= $stats30['bounce_rate'] > BOUNCE_RATE_WARN ? ' attention' : '' ?>"><span class="label"><?= icon('alert') ?>Bounce rate (30 days)</span><span class="value"><?= e($pct($stats30['bounce_rate'])) ?></span></div>
  <div class="stat-card<?= $stats7['complaint_rate'] > COMPLAINT_RATE_WARN ? ' attention' : '' ?>"><span class="label"><?= icon('flag') ?>Complaints (7 days)</span><span class="value"><?= e($pct($stats7['complaint_rate'])) ?></span></div>
  <div class="stat-card<?= $stats30['complaint_rate'] > COMPLAINT_RATE_WARN ? ' attention' : '' ?>"><span class="label"><?= icon('flag') ?>Complaints (30 days)</span><span class="value"><?= e($pct($stats30['complaint_rate'])) ?></span></div>
</div>
<div class="panel">
  <?= tabs_html('/admin/email-suppressions', 'reason', $reason, $tabs, ['q' => $q]) ?>
  <form class="toolbar" method="get" action="/admin/email-suppressions" role="search">
    <?php if ($reason !== ''): ?><input type="hidden" name="reason" value="<?= e($reason) ?>"><?php endif ?>
    <label for="sup-q" class="sr-only">Search addresses</label><input type="search" id="sup-q" name="q" value="<?= e($q) ?>" placeholder="Search addresses">
    <button class="btn ghost small" type="submit"><?= icon('search') ?> Search</button>
  </form>
  <div class="table-wrap" tabindex="0" role="region" aria-label="Suppressed addresses"><table class="data">
    <thead><tr><th scope="col">Address</th><th scope="col">Reason</th><th scope="col">Detail</th><th scope="col">Since</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="5" class="empty-row">No suppressed addresses.</td></tr><?php endif ?>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= e($r['email']) ?><?php if ($r['user_id']): ?><br><span class="muted small-text"><?= e((string) $r['display_name']) ?><?= $r['role'] === 'member' ? ' · member' : '' ?></span><?php endif ?></td>
        <td><span class="badge <?= e($badge[$r['reason']] ?? '') ?>"><?= e($labels[$r['reason']] ?? $r['reason']) ?></span><?= $r['source'] !== '' ? '<br><span class="muted small-text">via ' . e($r['source']) . '</span>' : '' ?></td>
        <td class="small-text"><?= e($r['detail']) ?><?= (int) $r['events'] > 1 ? '<br><span class="muted">' . (int) $r['events'] . ' events</span>' : '' ?></td>
        <td class="tabular"><?= e(fmt_date($r['created_at'], 'j M Y')) ?></td>
        <td><?php if (\Admin\Suppressions::clearable($r)): ?>
          <form method="post" action="/admin/email-suppressions/clear" data-confirm="<?= $r['reason'] === 'complaint' ? 'Clear the complaint for ' . e($r['email']) . '? It came from an emailed report, which can be forged. Clear it only if you are sure the person did not complain.' : 'Clear ' . e($r['email']) . '? Do this only after the address was corrected or the mailbox works again.' ?>"><?= csrf_field() ?><input type="hidden" name="email" value="<?= e($r['email']) ?>"><input type="hidden" name="reason" value="<?= e($reason) ?>"><button class="btn small ghost" type="submit">Clear</button></form>
        <?php else: ?><span class="muted small-text">Person’s choice</span><?php endif ?></td></tr>
    <?php endforeach ?>
    </tbody></table></div>
  <?php if ($pages > 1): ?><div class="pager"><span class="tabular">Page <?= (int) $page ?> of <?= $pages ?></span><span class="btn-row">
    <?php if ($page > 1): ?><a class="btn small ghost" href="<?= e(url_with('/admin/email-suppressions', ['reason' => $reason, 'q' => $q], ['page' => $page - 1])) ?>">Previous</a><?php endif ?>
    <?php if ($page < $pages): ?><a class="btn small ghost" href="<?= e(url_with('/admin/email-suppressions', ['reason' => $reason, 'q' => $q], ['page' => $page + 1])) ?>">Next</a><?php endif ?>
  </span></div><?php endif ?>
</div>
<?php if ($review): ?>
<section class="panel" id="review"><div class="panel-head"><h2>Reports to review <span class="badge warn tabular"><?= (int) $review_total ?></span></h2></div>
  <p class="hint">These reports reached the bounce mailbox without a valid tag from one of our emails (anyone can send email to that address), or a provider reported an address we have not emailed recently. Nothing was changed. <strong>Apply</strong> handles the report as genuine (a bounce or complaint suppresses the address; a block is only recorded); <strong>Dismiss</strong> ignores it.</p>
  <div class="table-wrap" tabindex="0" role="region" aria-label="Reports to review"><table class="data">
    <thead><tr><th scope="col">When</th><th scope="col">Address</th><th scope="col">Type</th><th scope="col">Detail</th><th scope="col">Source</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody><?php foreach ($review as $ev): ?>
      <tr><td class="tabular"><?= e(fmt_date($ev['created_at'], 'j M, H:i')) ?></td><td><?= e($ev['email']) ?></td>
        <td><span class="badge <?= e(['hard' => 'bad', 'complaint' => 'bad', 'block' => 'warn', 'soft' => 'scheduled'][$ev['type']] ?? '') ?>"><?= e($ev['type']) ?></span></td>
        <td class="small-text"><?= e(trim($ev['status_code'] . ' ' . $ev['detail'])) ?></td><td class="small-text"><?= e($ev['source']) ?></td>
        <td><div class="btn-row">
          <form method="post" action="/admin/email-suppressions/review/<?= (int) $ev['id'] ?>" data-confirm="Handle this report for <?= e($ev['email']) ?> as genuine?"><?= csrf_field() ?><input type="hidden" name="decision" value="apply"><button class="btn small ghost" type="submit">Apply</button></form>
          <form method="post" action="/admin/email-suppressions/review/<?= (int) $ev['id'] ?>"><?= csrf_field() ?><input type="hidden" name="decision" value="dismiss"><button class="btn small ghost" type="submit">Dismiss</button></form>
        </div></td></tr>
    <?php endforeach ?></tbody></table></div>
</section>
<?php endif ?>
<?php if ($events): ?>
<section class="panel"><div class="panel-head"><h2>Latest bounces and complaints</h2></div>
  <div class="table-wrap" tabindex="0" role="region" aria-label="Latest bounces and complaints"><table class="data">
    <thead><tr><th scope="col">When</th><th scope="col">Address</th><th scope="col">Type</th><th scope="col">Detail</th><th scope="col">Source</th></tr></thead>
    <tbody><?php foreach ($events as $ev): ?>
      <tr><td class="tabular"><?= e(fmt_date($ev['created_at'], 'j M, H:i')) ?></td><td><?= e($ev['email']) ?></td>
        <td><span class="badge <?= e(['hard' => 'bad', 'complaint' => 'bad', 'block' => 'warn', 'soft' => 'scheduled'][$ev['type']] ?? '') ?>"><?= e($ev['type']) ?></span></td>
        <td class="small-text"><?= e(trim($ev['status_code'] . ' ' . $ev['detail'])) ?></td><td class="small-text"><?= e($ev['source']) ?><?= $ev['review'] !== '' ? ' · ' . e(['pending' => 'to review', 'applied' => 'applied by an admin', 'dismissed' => 'dismissed'][$ev['review']] ?? $ev['review']) : '' ?></td></tr>
    <?php endforeach ?></tbody></table></div>
</section>
<?php endif ?>
