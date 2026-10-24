<?php /** @var array $c @var array $rows @var array $counts @var string $status @var int $total @var int $page @var int $per */
$pages = (int) ceil($total / $per);
$all = (int) $counts['queued'] + (int) $counts['sending'] + (int) $counts['sent'] + (int) $counts['failed'] + (int) $counts['suppressed'];
?>
<div class="page-head">
  <div><p class="crumbs"><a href="/admin/campaigns">Campaigns</a> / <a href="/admin/campaigns/<?= (int) $c['id'] ?>"><?= e($c['subject']) ?></a></p><h1>Delivery log</h1>
    <p><?= e(audience_label(audience_decode($c['audience']))) ?><?= $c['queued_at'] ? ' · queued ' . e(fmt_datetime($c['queued_at'])) : '' ?><?= $c['completed_at'] ? ' · finished ' . e(fmt_datetime($c['completed_at'])) : '' ?></p></div>
  <?php if ($counts['retryable'] > 0): ?>
    <form method="post" action="/admin/campaigns/<?= (int) $c['id'] ?>/retry"><?= csrf_field() ?><button class="btn" type="submit"><?= icon('mail') ?> Retry <?= (int) $counts['retryable'] ?> failed</button>
      <p class="hint muted small-text">Only recipients the mail server never accepted. Sends interrupted by a crash are not retried, so nobody gets the campaign twice.</p></form>
  <?php endif ?>
</div>
<?php if ($c['status'] === 'paused'): ?>
  <div class="flash error" role="status"><?= icon('alert') ?><span><strong>Sending is paused.</strong> <?= e($c['pause_reason']) ?> Recipients still waiting stay queued.</span>
    <form method="post" action="/admin/campaigns/<?= (int) $c['id'] ?>/resume"><?= csrf_field() ?><button class="btn small" type="submit">Resume sending</button></form></div>
<?php endif ?>
<?php if (($pause = mail_pause_state()) !== null && $pause['active']): ?><p class="flash error" role="status"><?= icon('alert') ?><span>Sending is paused until <?= e(fmt_date(gmdate('Y-m-d H:i:s', $pause['until']), 'H:i')) ?> because the mail server could not be reached: <?= e($pause['error']) ?></span></p><?php endif ?>
<div class="stat-grid">
  <div class="stat-card"><span class="label"><?= icon('users') ?>Recipients</span><span class="value"><?= (int) $all ?></span></div>
  <div class="stat-card"><span class="label"><?= icon('check') ?>Delivered</span><span class="value"><?= (int) $counts['delivered'] ?></span></div>
  <div class="stat-card"><span class="label"><?= icon('clock') ?>Waiting</span><span class="value"><?= (int) $counts['queued'] + (int) $counts['sending'] ?></span></div>
  <div class="stat-card<?= $counts['soft'] ? ' attention' : '' ?>"><span class="label"><?= icon('mail') ?>Soft bounces</span><span class="value"><?= (int) $counts['soft'] ?></span></div>
  <div class="stat-card<?= $counts['hard'] ? ' attention' : '' ?>"><span class="label"><?= icon('alert') ?>Hard bounces</span><span class="value"><?= (int) $counts['hard'] ?></span></div>
  <div class="stat-card<?= $counts['complaint'] ? ' attention' : '' ?>"><span class="label"><?= icon('flag') ?>Complaints</span><span class="value"><?= (int) $counts['complaint'] ?></span></div>
  <div class="stat-card"><span class="label"><?= icon('eye-off') ?>Suppressed</span><span class="value"><?= (int) $counts['suppressed'] ?></span></div>
  <div class="stat-card<?= $counts['failed'] ? ' attention' : '' ?>"><span class="label"><?= icon('alert') ?>Failed</span><span class="value"><?= (int) $counts['failed'] ?></span></div>
</div>
<?php if ($all > 0): ?><div class="progress-line" role="img" aria-label="<?= (int) round($counts['delivered'] / $all * 100) ?>% delivered"><progress max="<?= (int) $all ?>" value="<?= (int) $counts['delivered'] ?>"></progress></div><?php endif ?>
<div class="panel">
  <?= tabs_html('/admin/campaigns/' . (int) $c['id'] . '/log', 'status', $status, ['' => ['All', $all], 'sent' => ['Sent', $counts['sent']], 'queued' => ['Waiting', $counts['queued']], 'soft' => ['Soft bounce', $counts['soft']], 'hard' => ['Hard bounce', $counts['hard']], 'block' => ['Blocked', $counts['block']], 'complaint' => ['Complaint', $counts['complaint']], 'failed' => ['Failed', $counts['failed']], 'suppressed' => ['Not sent (suppressed)', $counts['suppressed']]]) ?>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th scope="col">Recipient</th><th scope="col">Status</th><th scope="col">Bounce / complaint</th><th scope="col">Attempts</th><th scope="col">Sent</th><th scope="col">Error</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="6" class="empty-row">No recipients here.</td></tr><?php endif ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['name'] !== '' ? $r['name'] : $r['email']) ?><br><span class="muted small-text"><?= e($r['email']) ?></span></td>
          <td><span class="badge <?= e(['sent' => 'ok', 'failed' => 'bad', 'queued' => 'scheduled', 'sending' => 'members', 'suppressed' => ''][$r['status']] ?? '') ?>"><?= e($r['status'] === 'suppressed' ? 'not sent' : $r['status']) ?></span></td>
          <td><?php if ($r['bounce'] !== null): ?><span class="badge <?= e(['hard' => 'bad', 'complaint' => 'bad', 'block' => 'warn', 'soft' => 'scheduled'][$r['bounce']] ?? '') ?>"><?= e(['hard' => 'hard bounce', 'soft' => 'soft bounce', 'block' => 'blocked', 'complaint' => 'complaint'][$r['bounce']] ?? $r['bounce']) ?></span><?php else: ?>—<?php endif ?></td>
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
