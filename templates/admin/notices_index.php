<?php /** @var array $rows */ ?>
<div class="page-head"><div><h1>Notices</h1><p>Announcements shown on members’ dashboards, with an optional email copy.</p></div>
  <div class="actions"><a class="btn accent" href="/admin/notices/new"><?= icon('plus') ?> New notice</a></div></div>
<div class="panel"><div class="table-wrap"><table class="data">
  <thead><tr><th scope="col">Notice</th><th scope="col">Audience</th><th scope="col">Status</th><th scope="col">Read by</th><th scope="col">Published</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="5" class="empty-row">No notices yet.</td></tr><?php endif ?>
  <?php foreach ($rows as $n): ?>
    <tr><td class="title-cell"><a href="/admin/notices/<?= (int) $n['id'] ?>"><?= e($n['title']) ?></a><?= (int) $n['email_copy'] ? ' <span class="badge members">' . icon('mail') . ' email copy</span>' : '' ?></td>
      <td><?= e(audience_label(audience_decode($n['audience']))) ?></td>
      <td><span class="badge <?= $n['status'] === 'published' ? 'published' : 'draft' ?>"><?= e($n['status']) ?></span></td>
      <td class="tabular"><?= (int) $n['read_count'] ?></td>
      <td class="tabular"><?= $n['published_at'] ? e(fmt_date($n['published_at'], 'j M Y, H:i')) : '—' ?></td></tr>
  <?php endforeach ?>
  </tbody></table></div></div>
