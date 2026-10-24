<?php /** @var array $job @var ?array $user @var bool $reported */
$apply = job_apply_href($job);
$live = $job['status'] === 'published' && ($job['expires_at'] === null || $job['expires_at'] > utc_now());
?>
<?= view('partials/page_hero', ['heading' => $job['title'], 'intro' => $job['organisation'] . ($job['location'] !== '' ? ' · ' . $job['location'] : ''), 'crumbs' => ['Jobs' => '/jobs', $job['title'] => null]]) ?>
<section class="section">
  <div class="container layout-with-aside">
    <article>
      <?php if (!$live): ?><p class="notice warn">This post is not on the board (<?= e($job['status']) ?>). Only you and the moderators can see it.</p><?php endif ?>
      <div class="prose"><?= nl2br(e((string) $job['description'])) ?></div>
    </article>
    <div class="side-col">
      <div class="aside-box">
        <h2>At a glance</h2>
        <ul class="event-facts">
          <li><?= icon('flag') ?><span><strong>Type</strong><?= e(JOB_TYPES[$job['job_type']] ?? '') ?><?= (int) $job['is_remote'] ? ', remote possible' : '' ?></span></li>
          <?php if ($job['location'] !== ''): ?><li><?= icon('pin') ?><span><strong>Location</strong><?= e($job['location']) ?></span></li><?php endif ?>
          <?php if ($job['salary_text'] !== ''): ?><li><?= icon('tag') ?><span><strong>Pay</strong><?= e($job['salary_text']) ?></span></li><?php endif ?>
          <?php if ($job['closing_date']): ?><li><?= icon('clock') ?><span><strong>Closing date</strong><?= e((new DateTimeImmutable($job['closing_date']))->format('l j F Y')) ?></span></li><?php endif ?>
          <?php if ($job['published_at']): ?><li><?= icon('calendar') ?><span><strong>Posted</strong><?= e(fmt_date($job['published_at'], 'j F Y')) ?></span></li><?php endif ?>
        </ul>
        <?php if ($apply !== '' && $live): ?><p><a class="btn accent" href="<?= e($apply) ?>"<?= str_starts_with($apply, 'http') ? ' target="_blank" rel="noopener nofollow"' : '' ?>><?= icon(str_starts_with($apply, 'mailto:') ? 'mail' : 'external') ?> How to apply<?= str_starts_with($apply, 'http') ? '<span class="sr-only"> (opens in a new tab)</span>' : '' ?></a></p><?php endif ?>
      </div>
      <?php if ($live && viewer_is_member($user) && (int) ($job['user_id'] ?? 0) !== (int) $user['id']): ?>
        <div class="aside-box">
          <?php if ($reported): ?><p class="muted"><?= icon('flag') ?> You reported this job. Thank you.</p>
          <?php else: ?>
          <details class="report"><summary><?= icon('flag') ?> Report this job</summary>
            <form method="post" action="/jobs/<?= (int) $job['id'] ?>/report" class="form">
              <?= csrf_field() ?>
              <div class="field"><label for="report-reason">What is wrong with it?</label><textarea id="report-reason" name="reason" rows="3" maxlength="500"></textarea></div>
              <button class="btn small ghost" type="submit">Send report</button>
            </form>
          </details>
          <?php endif ?>
        </div>
      <?php endif ?>
    </div>
  </div>
</section>
