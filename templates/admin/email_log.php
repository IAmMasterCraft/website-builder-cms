<?php /** @var array $rows @var int $total @var string $status @var array $counts @var int $page @var int $per @var array $campaigns @var int $per_minute @var mixed $cron */
$pages = (int) ceil($total / $per);
$all = array_sum($counts);
?>
<div class="page-head"><div><h1>Delivery log</h1><p>Emails are sent by cron, up to <?= (int) $per_minute ?> a minute. Cron last ran: <strong><?= is_string($cron) ? e(fmt_datetime($cron)) : 'never' ?></strong>.</p></div>
  <?php if (user_can(current_user(), 'super_admin')): ?><div class="actions"><a class="btn ghost" href="/admin/settings/email"><?= icon('settings') ?> Email settings</a></div><?php endif ?></div>
<?php if ($campaigns): ?>
<section class="panel"><div class="panel-head"><h2>Recent campaigns</h2></div>
  <ul class="row-list">
    <?php foreach ($campaigns as $c): ?>
      <li><div><a class="row-title" href="/admin/campaigns/<?= (int) $c['id'] ?>/log"><?= e($c['subject']) ?></a><p class="muted small-text"><?= e(fmt_datetime($c['queued_at'])) ?></p></div>
        <div class="row-meta tabular"><?= (int) $c['sent_count'] ?> / <?= (int) $c['recipient_count'] ?> delivered<?= (int) $c['failed_count'] ? ' <span class="badge bad">' . (int) $c['failed_count'] . ' failed</span>' : '' ?> <span class="badge <?= $c['status'] === 'sent' ? 'ok' : 'members' ?>"><?= e($c['status']) ?></span></div></li>
    <?php endforeach ?>
  </ul></section>
<?php endif ?>
<div class="panel">
  <div class="panel-head"><h2>Other emails</h2><span class="muted small-text">Notices, birthday emails, digests and transactional emails that needed a retry</span></div>
  <?= tabs_html('/admin/email-log', 'status', $status, ['' => ['All', $all], 'queued' => ['Waiting', $counts['queued']], 'sent' => ['Sent', $counts['sent']], 'failed' => ['Failed', $counts['failed']]]) ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th scope="col">To</th><th scope="col">Subject</th><th scope="col">Type</th><th scope="col">Status</th><th scope="col">Sent</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="5" class="empty-row">Nothing in the queue.</td></tr><?php endif ?>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= e($r['to_name'] !== '' ? $r['to_name'] : $r['to_email']) ?><br><span class="muted small-text"><?= e($r['to_email']) ?></span></td>
        <td><?= e($r['subject']) ?><?php if ($r['last_error'] !== ''): ?><br><span class="small-text error-text"><?= e($r['last_error']) ?></span><?php endif ?></td>
        <td><?= e($r['kind']) ?></td>
        <td><span class="badge <?= e(['sent' => 'ok', 'failed' => 'bad', 'queued' => 'scheduled', 'sending' => 'members'][$r['status']] ?? '') ?>"><?= e($r['status']) ?></span><?= (int) $r['attempts'] > 1 ? ' <span class="muted small-text">' . (int) $r['attempts'] . ' tries</span>' : '' ?></td>
        <td class="tabular"><?= $r['sent_at'] ? e(fmt_date($r['sent_at'], 'j M, H:i')) : '—' ?></td></tr>
    <?php endforeach ?>
    </tbody></table></div>
  <?php if ($pages > 1): ?><div class="pager"><span class="tabular">Page <?= (int) $page ?> of <?= $pages ?></span><span class="btn-row">
    <?php if ($page > 1): ?><a class="btn small ghost" href="<?= e(url_with('/admin/email-log', ['status' => $status], ['page' => $page - 1])) ?>">Previous</a><?php endif ?>
    <?php if ($page < $pages): ?><a class="btn small ghost" href="<?= e(url_with('/admin/email-log', ['status' => $status], ['page' => $page + 1])) ?>">Next</a><?php endif ?>
  </span></div><?php endif ?>
</div>
