<?php /** @var array $rows */ ?>
<div class="dash-card-head"><p class="lede">Share jobs, volunteering roles and internships with other members.</p>
  <span class="btn-row"><a class="btn ghost small" href="/jobs"><?= icon('search') ?> Browse the board</a><a class="btn accent small" href="/account/jobs/new"><?= icon('plus') ?> Post a job</a></span></div>
<?php if (!$rows): ?>
  <?= view('partials/empty', ['message' => 'You have not posted any jobs yet.']) ?>
<?php else: ?>
  <div class="table-card"><table class="simple-table">
    <caption class="sr-only">Your job posts</caption>
    <thead><tr><th scope="col">Job</th><th scope="col">Status</th><th scope="col">On the board until</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $j): ?>
      <tr>
        <td><a href="/jobs/<?= (int) $j['id'] ?>"><?= e($j['title']) ?></a><br><span class="muted"><?= e($j['organisation']) ?></span></td>
        <td><span class="badge<?= in_array($j['status'], ['rejected', 'expired'], true) ? ' muted' : ($j['status'] === 'pending' ? ' warn' : ' brand') ?>"><?= e(['pending' => 'Awaiting approval', 'published' => 'Live', 'rejected' => 'Not approved', 'expired' => 'Expired'][$j['status']] ?? $j['status']) ?></span>
          <?php if ($j['status'] === 'rejected' && $j['reject_reason'] !== ''): ?><br><span class="muted small-text"><?= e($j['reject_reason']) ?></span><?php endif ?></td>
        <td class="tabular"><?= $j['status'] === 'published' && $j['expires_at'] ? e(fmt_date($j['expires_at'])) : '—' ?></td>
        <td class="row-actions"><a class="btn small ghost" href="/account/jobs/<?= (int) $j['id'] ?>/edit">Edit<span class="sr-only"> <?= e($j['title']) ?></span></a>
          <form method="post" action="/account/jobs/<?= (int) $j['id'] ?>/delete" data-confirm="Remove “<?= e($j['title']) ?>” from the board?"><?= csrf_field() ?><button class="btn small ghost" type="submit">Remove<span class="sr-only"> <?= e($j['title']) ?></span></button></form></td>
      </tr>
    <?php endforeach ?>
    </tbody></table></div>
<?php endif ?>
