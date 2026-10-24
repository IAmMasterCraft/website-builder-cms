<?php /** @var array $c @var array $rows @var array $counts @var string $status @var int $total @var int $page @var int $per */
$pages = (int) ceil($total / $per);
$all = array_sum($counts);
?>
<div class="page-head">
  <div><p class="crumbs"><a href="/admin/campaigns">Campaigns</a> / <a href="/admin/campaigns/<?= (int) $c['id'] ?>"><?= e($c['subject']) ?></a></p><h1>Delivery log</h1>
    <p><?= e(audience_label(audience_decode($c['audience']))) ?><?= $c['queued_at'] ? ' · queued ' . e(fmt_datetime($c['queued_at'])) : '' ?><?= $c['completed_at'] ? ' · finished ' . e(fmt_datetime($c['completed_at'])) : '' ?></p></div>
</div>
<div class="stat-grid">
  <div class="stat-card"><span class="label"><?= icon('users') ?>Recipients</span><span class="value"><?= (int) $all ?></span></div>
  <div class="stat-card"><span class="label"><?= icon('check') ?>Delivered</span><span class="value"><?= (int) $counts['sent'] ?></span></div>
  <div class="stat-card"><span class="label"><?= icon('clock') ?>Waiting</span><span class="value"><?= (int) $counts['queued'] + (int) $counts['sending'] ?></span></div>
  <div class="stat-card<?= $counts['failed'] ? ' attention' : '' ?>"><span class="label"><?= icon('alert') ?>Failed</span><span class="value"><?= (int) $counts['failed'] ?></span></div>
</div>
<?php if ($all > 0): ?><div class="progress-line" role="img" aria-label="<?= (int) round($counts['sent'] / $all * 100) ?>% delivered"><progress max="<?= (int) $all ?>" value="<?= (int) $counts['sent'] ?>"></progress></div><?php endif ?>
<div class="panel">
  <?= tabs_html('/admin/campaigns/' . (int) $c['id'] . '/log', 'status', $status, ['' => ['All', $all], 'sent' => ['Delivered', $counts['sent']], 'queued' => ['Waiting', $counts['queued']], 'failed' => ['Failed', $counts['failed']]]) ?>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th scope="col">Recipient</th><th scope="col">Status</th><th scope="col">Attempts</th><th scope="col">Sent</th><th scope="col">Error</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="5" class="empty-row">No recipients here.</td></tr><?php endif ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['name'] !== '' ? $r['name'] : $r['email']) ?><br><span class="muted small-text"><?= e($r['email']) ?></span></td>
          <td><span class="badge <?= e(['sent' => 'ok', 'failed' => 'bad', 'queued' => 'scheduled', 'sending' => 'members'][$r['status']] ?? '') ?>"><?= e($r['status'] === 'sent' ? 'delivered' : $r['status']) ?></span></td>
          <td class="tabular"><?= (int) $r['attempts'] ?></td>
          <td class="tabular"><?= $r['sent_at'] ? e(fmt_date($r['sent_at'], 'j M, H:i:s')) : '—' ?></td>
          <td class="small-text"><?= e($r['error']) ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?><div class="pager"><span class="tabular">Page <?= (int) $page ?> of <?= $pages ?></span><span class="btn-row">
    <?php if ($page > 1): ?><a class="btn small ghost" href="<?= e(url_with('/admin/campaigns/' . (int) $c['id'] . '/log', ['status' => $status], ['page' => $page - 1])) ?>">Previous</a><?php endif ?>
    <?php if ($page < $pages): ?><a class="btn small ghost" href="<?= e(url_with('/admin/campaigns/' . (int) $c['id'] . '/log', ['status' => $status], ['page' => $page + 1])) ?>">Next</a><?php endif ?>
  </span></div><?php endif ?>
</div>
