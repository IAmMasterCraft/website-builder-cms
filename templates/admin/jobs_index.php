<?php /** @var array $rows @var string $tab @var array $counts @var array $reports @var array $settings */
$tabs = [];
foreach (\Admin\Jobs::TABS as $k => $l) { $tabs[$k] = [$l, $counts[$k] ?? 0]; }
?>
<div class="page-head"><div><h1>Jobs</h1><p>Approve member job posts, handle reports, and choose who can see the board.</p></div>
  <div class="actions"><a class="btn ghost" href="/jobs" target="_blank" rel="noopener"><?= icon('external') ?> View board</a><a class="btn accent" href="/admin/jobs/new"><?= icon('plus') ?> Post a job</a></div></div>
<div class="dash-grid">
  <div class="panel">
    <?= tabs_html('/admin/jobs', 'tab', $tab, $tabs) ?>
    <?php if (!$rows): ?><p class="empty-row">Nothing here.</p><?php endif ?>
    <ul class="mod-list">
      <?php foreach ($rows as $j): ?>
        <li>
          <div class="mod-head">
            <div><h2 class="h3"><?= e($j['title']) ?></h2>
              <p class="muted small-text"><?= e($j['organisation']) ?><?= $j['location'] !== '' ? ' · ' . e($j['location']) : '' ?> · <?= e(JOB_TYPES[$j['job_type']] ?? '') ?><?= (int) $j['is_remote'] ? ' · remote' : '' ?><?= $j['closing_date'] ? ' · closes ' . e((new DateTimeImmutable($j['closing_date']))->format('j M Y')) : '' ?></p>
              <p class="small-text">Posted by <strong><?= e($j['poster'] ?? 'Former member') ?></strong> on <?= e(fmt_date($j['created_at'], 'j M Y, H:i')) ?><?= $j['expires_at'] && $j['status'] === 'published' ? ' · expires ' . e(fmt_date($j['expires_at'])) : '' ?></p></div>
            <span class="badge <?= e(['published' => 'published', 'pending' => 'scheduled', 'rejected' => 'bad', 'expired' => 'draft'][$j['status']] ?? '') ?>"><?= e($j['status']) ?></span>
          </div>
          <details class="mod-details"><summary>Description and how to apply</summary>
            <p class="message-body"><?= nl2br(e((string) $j['description'])) ?></p>
            <p class="small-text"><?= $j['salary_text'] !== '' ? 'Salary: ' . e($j['salary_text']) . ' · ' : '' ?>Apply: <?= e((string) ($j['apply_url'] ?? $j['apply_email'] ?? '')) ?></p>
          </details>
          <?php if ($j['reject_reason'] !== '' && $j['status'] === 'rejected'): ?><p class="small-text muted">Reason given: <?= e($j['reject_reason']) ?></p><?php endif ?>
          <?php if (isset($reports[(int) $j['id']])): ?>
            <div class="report-box"><p><strong><?= icon('flag') ?> <?= e(pluralise(count($reports[(int) $j['id']]), 'report')) ?></strong></p>
              <ul><?php foreach ($reports[(int) $j['id']] as $r): ?><li><?= e($r['display_name'] ?? 'Member') ?>: <?= e($r['reason'] !== '' ? $r['reason'] : '(no reason)') ?></li><?php endforeach ?></ul></div>
          <?php endif ?>
          <div class="btn-row">
            <?php if (in_array($j['status'], ['pending', 'rejected'], true)): ?>
              <form method="post" action="/admin/jobs/<?= (int) $j['id'] ?>/approve"><?= csrf_field() ?><button class="btn small accent" type="submit"><?= icon('check') ?> Approve<span class="sr-only"> <?= e($j['title']) ?></span></button></form>
            <?php endif ?>
            <?php if ($j['status'] !== 'rejected'): ?>
              <form method="post" action="/admin/jobs/<?= (int) $j['id'] ?>/reject" class="inline-reason">
                <?= csrf_field() ?><input type="hidden" name="tab" value="<?= e($tab) ?>">
                <label class="sr-only" for="reason-<?= (int) $j['id'] ?>">Reason for rejecting <?= e($j['title']) ?></label>
                <input type="text" id="reason-<?= (int) $j['id'] ?>" name="reason" maxlength="500" placeholder="Reason (sent to the poster)">
                <button class="btn small danger-ghost" type="submit">Reject<?= $j['status'] === 'published' ? ' and remove' : '' ?></button>
              </form>
            <?php endif ?>
            <?php if (isset($reports[(int) $j['id']])): ?><form method="post" action="/admin/jobs/<?= (int) $j['id'] ?>/resolve"><?= csrf_field() ?><button class="btn small ghost" type="submit">Keep and close reports</button></form><?php endif ?>
            <form method="post" action="/admin/jobs/<?= (int) $j['id'] ?>/delete" data-confirm="Delete this job permanently?"><?= csrf_field() ?><button class="btn small ghost" type="submit"><?= icon('trash') ?><span class="sr-only">Delete <?= e($j['title']) ?></span></button></form>
          </div>
        </li>
      <?php endforeach ?>
    </ul>
  </div>
  <form class="panel" method="post" action="/admin/jobs/settings">
    <?= csrf_field() ?>
    <div class="panel-head"><h2>Settings</h2></div>
    <div class="panel-body fields">
      <?= field_html('auto_approve_trusted', 'Publish posts from trusted members straight away', 'checkbox', $settings['auto_approve_trusted'] ? '1' : '0', null, ['hint' => 'Trusted = at least one job approved before and none rejected. Admins’ posts are always published.']) ?>
      <?= field_html('public', 'Show the job board to everyone (not only members)', 'checkbox', $settings['public'] ? '1' : '0', null, ['hint' => 'Off by default: only logged-in members see jobs.']) ?>
      <p class="hint muted">Jobs expire at the end of their closing date, or <?= JOB_MAX_DAYS ?> days after publication, whichever comes first.</p>
      <button class="btn ghost" type="submit"><?= icon('check') ?> Save settings</button>
    </div>
  </form>
</div>
