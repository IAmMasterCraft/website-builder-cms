<?php /** @var array $rows @var int $total @var string $status @var array $counts @var int $page @var int $per @var array $campaigns @var int $per_minute @var mixed $cron */
$pages = (int) ceil($total / $per);
$all = array_sum($counts) - (int) ($counts['bounced'] ?? 0);
?>
<div class="page-head"><div><h1>Delivery log</h1><p>Emails are sent by cron, up to <?= (int) $per_minute ?> a minute. Cron last ran: <strong><?= is_string($cron) ? e(fmt_datetime($cron)) : 'never' ?></strong>.</p></div>
  <?php if (user_can(current_user(), 'super_admin')): ?><div class="actions"><a class="btn ghost" href="/admin/settings/email"><?= icon('settings') ?> Email settings</a></div><?php endif ?></div>
<?php if (($pause = mail_pause_state()) !== null && $pause['active']): ?><p class="flash error" role="status"><?= icon('alert') ?><span>Sending is paused until <?= e(fmt_date(gmdate('Y-m-d H:i:s', $pause['until']), 'H:i')) ?> because the mail server could not be reached: <?= e($pause['error']) ?>. Nothing is lost: waiting emails go out once it answers again.</span></p><?php endif ?>
<?php if ($campaigns): ?>
<section class="panel"><div class="panel-head"><h2>Recent campaigns</h2></div>
  <div class="table-wrap" tabindex="0" role="region" aria-label="Recent campaigns"><table class="data">
    <thead><tr><th scope="col">Campaign</th><th scope="col">Delivered</th><th scope="col">Soft</th><th scope="col">Hard</th><th scope="col">Complaints</th><th scope="col">Suppressed</th><th scope="col">Status</th></tr></thead>
    <tbody>
    <?php foreach ($campaigns as $c): ?>
      <tr><td><a class="row-title" href="/admin/campaigns/<?= (int) $c['id'] ?>/log"><?= e($c['subject']) ?></a><br><span class="muted small-text"><?= e(fmt_datetime($c['queued_at'])) ?></span></td>
        <td class="tabular"><?= (int) $c['delivered_count'] ?> / <?= (int) $c['recipient_count'] ?><?= (int) $c['failed_count'] ? ' <span class="badge bad">' . (int) $c['failed_count'] . ' failed</span>' : '' ?></td>
        <td class="tabular"><?= (int) $c['soft_count'] ?></td><td class="tabular"><?= (int) $c['hard_count'] ?></td><td class="tabular"><?= (int) $c['complaint_count'] ?></td><td class="tabular"><?= (int) $c['suppressed_count'] ?></td>
        <td><span class="badge <?= e(['sent' => 'ok', 'paused' => 'bad'][$c['status']] ?? 'members') ?>"><?= e($c['status']) ?></span></td></tr>
    <?php endforeach ?>
    </tbody></table></div></section>
<?php endif ?>
<div class="panel">
  <div class="panel-head"><h2>Other emails</h2><span class="muted small-text">Transactional emails, notices, birthday emails and digests</span></div>
  <?= tabs_html('/admin/email-log', 'status', $status, ['' => ['All', $all], 'queued' => ['Waiting', $counts['queued']], 'sent' => ['Sent', $counts['sent']], 'failed' => ['Failed', $counts['failed']], 'suppressed' => ['Not sent (suppressed)', $counts['suppressed']], 'bounced' => ['Bounced', $counts['bounced']]]) ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th scope="col">To</th><th scope="col">Subject</th><th scope="col">Type</th><th scope="col">Status</th><th scope="col">Bounce / complaint</th><th scope="col">Sent</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty-row">Nothing in the queue.</td></tr><?php endif ?>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= e($r['to_name'] !== '' ? $r['to_name'] : $r['to_email']) ?><br><span class="muted small-text"><?= e($r['to_email']) ?></span></td>
        <td><?= e($r['subject']) ?><?php if ($r['last_error'] !== ''): ?><br><span class="small-text error-text"><?= e($r['last_error']) ?></span><?php endif ?></td>
        <td><?= e($r['kind']) ?></td>
        <td><span class="badge <?= e(['sent' => 'ok', 'failed' => 'bad', 'queued' => 'scheduled', 'sending' => 'members'][$r['status']] ?? '') ?>"><?= e($r['status']) ?></span><?= (int) $r['attempts'] > 1 ? ' <span class="muted small-text">' . (int) $r['attempts'] . ' tries</span>' : '' ?></td>
        <td><?php if ($r['bounce'] !== null): ?><span class="badge <?= e(['hard' => 'bad', 'complaint' => 'bad', 'block' => 'warn', 'soft' => 'scheduled'][$r['bounce']] ?? '') ?>"><?= e(['hard' => 'hard bounce', 'soft' => 'soft bounce', 'block' => 'blocked', 'complaint' => 'complaint'][$r['bounce']] ?? $r['bounce']) ?></span><?php else: ?>—<?php endif ?></td>
        <td class="tabular"><?= $r['sent_at'] ? e(fmt_date($r['sent_at'], 'j M, H:i')) : '—' ?></td></tr>
    <?php endforeach ?>
    </tbody></table></div>
  <?php if ($pages > 1): ?><div class="pager"><span class="tabular">Page <?= (int) $page ?> of <?= $pages ?></span><span class="btn-row">
    <?php if ($page > 1): ?><a class="btn small ghost" href="<?= e(url_with('/admin/email-log', ['status' => $status], ['page' => $page - 1])) ?>">Previous</a><?php endif ?>
    <?php if ($page < $pages): ?><a class="btn small ghost" href="<?= e(url_with('/admin/email-log', ['status' => $status], ['page' => $page + 1])) ?>">Next</a><?php endif ?>
  </span></div><?php endif ?>
</div>
