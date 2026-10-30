<?php /** @var string $tab @var array $rows @var int $open_count */ ?>
<div class="page-head"><div><h1>Reported messages</h1><p>Messages members reported. Open one to read it with the messages around it; each view is recorded in the audit log. Admins cannot read private conversations otherwise. Reported comments are under <a href="/admin/comments">Comments</a>.</p></div></div>
<div class="panel">
  <?= tabs_html('/admin/messages/reports', 'tab', $tab === 'open' ? '' : $tab, ['' => ['Open', $open_count], 'resolved' => ['Resolved', null]]) ?>
  <?php if ($rows === []): ?><p class="dm-empty muted"><?= $tab === 'open' ? 'No open reports.' : 'Nothing resolved yet.' ?></p><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th scope="col">Sent by</th><th scope="col">Where</th><th scope="col">Reports and reasons</th><th scope="col">First reported</th><?php if ($tab !== 'open'): ?><th scope="col">Outcome</th><?php endif ?><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e($r['sender_name']) ?></td>
        <td><?= e(['direct' => '1-to-1', 'group' => 'Group', 'announcement' => 'Announcement', 'team' => 'Team inbox'][$r['kind']] ?? $r['kind']) ?></td>
        <td><?= (int) $r['reports'] ?><ul class="dm-reasons"><?php foreach ($r['reasons'] as $x): ?><li><?= e($x['reporter']) ?>: <?= $x['reason'] !== '' ? '“' . e($x['reason']) . '”' : '<span class="muted">no reason given</span>' ?></li><?php endforeach ?></ul></td>
        <td class="tabular"><?= e(fmt_datetime($r['first_at'])) ?></td>
        <?php if ($tab !== 'open'): ?><td><span class="badge <?= $r['outcome'] === 'removed' ? 'bad' : 'ok' ?>"><?= e($r['outcome'] === 'removed' ? 'removed' : 'kept') ?></span></td><?php endif ?>
        <td><a class="btn small ghost" href="/admin/messages/reports/<?= (int) $r['message_id'] ?>">Review<span class="sr-only"> the message from <?= e($r['sender_name']) ?></span></a></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table></div>
  <?php endif ?>
</div>
