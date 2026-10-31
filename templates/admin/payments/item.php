<?php /** @var array $item @var string $tab @var array $tabs @var array $rows @var ?int $next @var array $counts @var array $progress @var array $remind @var string $q @var array $matches @var int $payments */
$pct = (int) $item['expected_minor'] > 0 ? (int) min(100, floor((int) $item['collected_minor'] * 100 / (int) $item['expected_minor'])) : null;
$tabList = [];
foreach ($tabs as $k => $l) { $tabList[$k] = [$l, $counts[$k] ?? 0]; }
$unpaid = (int) $counts['unpaid'] + (int) $counts['part'];
?>
<div class="pay-admin">
<div class="page-head">
  <div><p class="crumbs"><a href="/admin/payments/items">Dues and items</a> /</p><h1><?= e($item['name']) ?> <span class="badge"><?= e($item['status']) ?></span></h1>
    <p><?= e(PAYMENT_ITEM_KINDS[$item['item_kind']] ?? '') ?> · <?= e(money($item['amount_minor'] !== null ? (int) $item['amount_minor'] : null)) ?> · <?= e(payment_item_applies_label($item)) ?><?= $item['due_date'] !== null ? ' · due ' . e((new DateTimeImmutable((string) $item['due_date']))->format('j M Y')) : '' ?><?= (int) $item['counts_as_dues'] ? ' · counts as membership dues' : '' ?></p></div>
  <div class="actions">
    <?php if ($item['status'] !== 'open'): ?><form method="post" action="/admin/payments/items/<?= (int) $item['id'] ?>/status"><?= csrf_field() ?><input type="hidden" name="status" value="open"><button class="btn accent" type="submit"><?= icon('check') ?> <?= $item['status'] === 'draft' ? 'Open for payments' : 'Re-open' ?></button></form>
    <?php else: ?><form method="post" action="/admin/payments/items/<?= (int) $item['id'] ?>/status" data-confirm="Close this item? Members can no longer report payments for it."><?= csrf_field() ?><input type="hidden" name="status" value="closed"><button class="btn ghost" type="submit">Close</button></form><?php endif ?>
    <a class="btn ghost" href="/admin/payments/items/<?= (int) $item['id'] ?>/edit"><?= icon('pen') ?> Edit</a>
    <form method="post" action="/admin/payments/items/<?= (int) $item['id'] ?>/duplicate"><?= csrf_field() ?><button class="btn ghost" type="submit"><?= icon('layers') ?> Duplicate for next period</button></form>
    <?php if ($item['status'] === 'draft' && $payments === 0): ?><form method="post" action="/admin/payments/items/<?= (int) $item['id'] ?>/delete" data-confirm="Delete this draft item?"><?= csrf_field() ?><button class="btn ghost" type="submit"><?= icon('trash') ?><span class="sr-only">Delete draft item</span></button></form><?php endif ?>
  </div>
</div>
<?php if ($progress['pending']): ?><p class="notice-box" role="status">Creating charges in batches of 500: <?= (int) $progress['charges'] ?> of <?= (int) $progress['audience'] ?> members so far. Reload to see progress (cron adds a batch every minute).</p><?php endif ?>
<dl class="pay-stats">
  <div><dt>Expected</dt><dd class="tabular"><?= e(money((int) $item['expected_minor'])) ?></dd></div>
  <div><dt>Collected</dt><dd class="tabular"><?= e(money((int) $item['collected_minor'])) ?></dd></div>
  <div><dt>Outstanding</dt><dd class="tabular"><?= e(money((int) $item['outstanding_minor'])) ?></dd></div>
  <div><dt>Paid</dt><dd><?php if ($pct !== null): ?><progress class="pay-bar" max="100" value="<?= $pct ?>" aria-label="<?= $pct ?>% collected"><?= $pct ?>%</progress> <span class="tabular"><?= $pct ?>%</span><?php else: ?>—<?php endif ?></dd></div>
</dl>
<?php if ($item['description'] !== null && $item['description'] !== ''): ?><p class="muted"><?= nl2br(e((string) $item['description'])) ?></p><?php endif ?>
<div class="dash-grid">
  <div class="panel">
    <?= tabs_html('/admin/payments/items/' . (int) $item['id'], 'tab', $tab, ['unpaid' => $tabList['unpaid'], 'part' => $tabList['part'], 'paid' => $tabList['paid'], 'waived' => $tabList['waived']]) ?>
    <div class="table-wrap"><table class="data">
      <caption class="sr-only"><?= e($tabs[$tab]) ?> members</caption>
      <thead><tr><th scope="col">Member</th><th scope="col">Number</th><th scope="col">Amount</th><th scope="col">Paid</th><th scope="col">Balance</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="5" class="empty-row">Nobody here.</td></tr><?php endif ?>
      <?php foreach ($rows as $r): ?>
        <tr><td><?= $r['member_id'] !== null ? '<a href="/admin/payments/member/' . (int) $r['member_id'] . '">' . e(trim($r['first_name'] . ' ' . $r['last_name'])) . '</a>' : e((string) $r['former_label']) ?><?= $r['waive_reason'] !== '' ? '<br><span class="muted small-text">' . e($r['waive_reason']) . '</span>' : '' ?></td>
          <td class="tabular"><?= e((string) ($r['member_number'] ?? '')) ?></td><td class="tabular"><?= e(money((int) $r['amount_minor'])) ?></td><td class="tabular"><?= e(money((int) $r['paid_minor'])) ?></td><td class="tabular"><?= e(money(payment_charge_balance($r))) ?></td></tr>
      <?php endforeach ?>
      </tbody>
    </table></div>
    <div class="pager"><a class="btn small ghost" href="/admin/payments/items/<?= (int) $item['id'] ?>.csv?tab=<?= e($tab) ?>"><?= icon('download') ?> Export <?= e(strtolower($tabs[$tab])) ?> (CSV)</a>
      <span class="btn-row"><?php if ((int) query_param('after') > 0): ?><a class="btn small ghost" href="/admin/payments/items/<?= (int) $item['id'] ?>?tab=<?= e($tab) ?>">First</a><?php endif ?><?php if ($next !== null): ?><a class="btn small ghost" href="/admin/payments/items/<?= (int) $item['id'] ?>?tab=<?= e($tab) ?>&amp;after=<?= $next ?>">Next</a><?php endif ?></span></div>
  </div>
  <div>
    <?php if ($unpaid > 0): ?>
    <form class="panel" method="post" action="/admin/payments/items/<?= (int) $item['id'] ?>/remind" data-confirm="Email <?= (int) $remind['send'] ?> members who have not paid in full?">
      <?= csrf_field() ?>
      <div class="panel-head"><h2>Remind unpaid</h2></div>
      <div class="panel-body"><p><?= e(pluralise($remind['send'], 'member')) ?> will get the “Payment reminder (sent by an admin)” email<?= $remind['skipped'] ? '; ' . (int) $remind['skipped'] . ' cannot be emailed (suppressed address)' : '' ?>. Each member gets at most one a day.</p>
        <button class="btn ghost" type="submit"<?= $remind['send'] === 0 ? ' disabled' : '' ?>><?= icon('send') ?> Send reminders</button></div>
    </form>
    <form class="panel" method="post" action="/admin/payments/items/<?= (int) $item['id'] ?>/message">
      <?= csrf_field() ?>
      <div class="panel-head"><h2>Message unpaid</h2></div>
      <div class="panel-body fields">
        <p class="hint">An announcement in Messages to the <?= e(pluralise($unpaid, 'member')) ?> who have not paid in full (they can read but not reply).</p>
        <?= field_html('title', 'Title', 'text', $item['name'], null, ['max' => 80, 'prefix' => 'mu']) ?>
        <?= field_html('body', 'Message', 'textarea', '', null, ['rows' => 4, 'prefix' => 'mu', 'required' => true]) ?>
        <button class="btn ghost" type="submit"><?= icon('message') ?> Send message</button>
      </div>
    </form>
    <?php endif ?>
    <?php if ($item['applies_to'] === 'members' && $item['status'] !== 'closed'): ?>
    <section class="panel"><div class="panel-head"><h2>Add members</h2></div><div class="panel-body">
      <form class="toolbar" method="get" action="/admin/payments/items/<?= (int) $item['id'] ?>"><label for="am-q">Find members</label><input type="search" id="am-q" name="q" value="<?= e($q) ?>" maxlength="100" placeholder="Name or number"><button class="btn small ghost" type="submit">Search</button></form>
      <?php if ($matches): ?><form method="post" action="/admin/payments/items/<?= (int) $item['id'] ?>/members"><?= csrf_field() ?>
        <fieldset class="field"><legend>Choose who to add</legend><div class="pay-member-pick"><?php foreach ($matches as $m): ?><label class="check-line"><input type="checkbox" name="member_ids[]" value="<?= (int) $m['id'] ?>"> <span><?= e(member_full_name($m)) ?> <span class="muted tabular"><?= e((string) $m['member_number']) ?></span></span></label><?php endforeach ?></div></fieldset>
        <button class="btn small ghost" type="submit"><?= icon('plus') ?> Add chosen members</button></form>
      <?php elseif ($q !== ''): ?><p class="muted">Nobody found (or they already have this item).</p><?php endif ?>
    </div></section>
    <?php endif ?>
  </div>
</div>
</div>
