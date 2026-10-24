<?php /** @var array $rows @var string $status @var array $counts */ ?>
<div class="page-head">
  <div><h1>Campaigns</h1><p>Email newsletters and announcements to members and other audiences.</p></div>
  <form class="actions" method="post" action="/admin/campaigns"><?= csrf_field() ?><button class="btn accent" type="submit"><?= icon('plus') ?> New campaign</button></form>
</div>
<div class="panel">
  <?= tabs_html('/admin/campaigns', 'status', $status, ['' => ['All', $counts[''] ?? 0], 'draft' => ['Drafts', $counts['draft'] ?? 0], 'scheduled' => ['Scheduled', $counts['scheduled'] ?? 0], 'sending' => ['Sending', $counts['sending'] ?? 0], 'paused' => ['Paused', $counts['paused'] ?? 0], 'sent' => ['Sent', $counts['sent'] ?? 0]]) ?>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th scope="col">Subject</th><th scope="col">Audience</th><th scope="col">Status</th><th scope="col">Delivered</th><th scope="col">Updated</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="5" class="empty-row">No campaigns yet. Start one with “New campaign”.</td></tr><?php endif ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="title-cell"><a href="/admin/campaigns/<?= (int) $r['id'] ?>"><?= e($r['subject']) ?></a><?php if ($r['author']): ?><br><span class="muted small-text">by <?= e($r['author']) ?></span><?php endif ?></td>
          <td><?= e(audience_label(audience_decode($r['audience']))) ?></td>
          <td><span class="badge <?= e(['sent' => 'ok', 'sending' => 'members', 'paused' => 'bad', 'scheduled' => 'scheduled', 'draft' => 'draft'][$r['status']] ?? '') ?>"><?= e($r['status']) ?></span><?php if ($r['status'] === 'scheduled' && $r['send_at']): ?><br><span class="muted small-text tabular"><?= e(fmt_datetime($r['send_at'])) ?></span><?php endif ?></td>
          <td class="tabular"><?php if ((int) $r['recipient_count'] > 0): ?><a href="/admin/campaigns/<?= (int) $r['id'] ?>/log"><?= (int) $r['sent_count'] ?> / <?= (int) $r['recipient_count'] ?></a><?= (int) $r['failed_count'] > 0 ? ' <span class="badge bad">' . (int) $r['failed_count'] . ' failed</span>' : '' ?><?php else: ?>—<?php endif ?></td>
          <td class="tabular"><?= e(fmt_date($r['updated_at'], 'j M Y, H:i')) ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
</div>
