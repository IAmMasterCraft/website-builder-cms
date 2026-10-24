<?php /** @var array $rows @var int $total @var array $filters @var int $page @var int $per @var ?array $user */
$query = array_filter($filters, static fn ($v) => $v !== '');
?>
<?= view('partials/page_hero', ['heading' => 'Job board', 'intro' => 'Jobs, volunteering and internships shared by our members and partners.', 'crumbs' => ['Jobs' => null]]) ?>
<section class="section">
  <div class="container">
    <div class="jobs-toolbar">
      <form class="job-filters" method="get" action="/jobs" role="search">
        <label for="jq" class="sr-only">Search jobs</label>
        <input type="search" id="jq" name="q" value="<?= e($filters['q']) ?>" placeholder="Search title, organisation or place" maxlength="100">
        <label for="jt" class="sr-only">Type</label>
        <select id="jt" name="type"><option value="">All types</option><?php foreach (JOB_TYPES as $k => $l): ?><option value="<?= e($k) ?>"<?= $filters['type'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select>
        <label class="check-line"><input type="checkbox" name="remote" value="1"<?= $filters['remote'] === '1' ? ' checked' : '' ?>> <span>Remote</span></label>
        <button class="btn small" type="submit"><?= icon('search') ?> Filter</button>
        <?php if ($query): ?><a class="btn small ghost" href="/jobs">Clear</a><?php endif ?>
      </form>
      <?php if (viewer_is_member($user)): ?><a class="btn accent small" href="<?= is_staff($user) ? '/admin/jobs/new' : '/account/jobs/new' ?>"><?= icon('plus') ?> Post a job</a><?php endif ?>
    </div>
    <p class="muted tabular" aria-live="polite"><?= e(pluralise($total, 'job')) ?></p>
    <?php if ($rows): ?>
      <ul class="job-list">
        <?php foreach ($rows as $j): ?>
          <li class="job-card">
            <div class="job-card-main">
              <h2 class="h3"><a href="/jobs/<?= (int) $j['id'] ?>"><?= e($j['title']) ?></a></h2>
              <p class="job-org"><?= e($j['organisation']) ?><?= $j['location'] !== '' ? ' · ' . e($j['location']) : '' ?></p>
              <p class="job-tags"><span class="badge brand"><?= e(JOB_TYPES[$j['job_type']] ?? '') ?></span><?= (int) $j['is_remote'] ? ' <span class="badge">Remote</span>' : '' ?><?= $j['salary_text'] !== '' ? ' <span class="badge muted">' . e($j['salary_text']) . '</span>' : '' ?></p>
            </div>
            <p class="job-date muted small-text tabular"><?= $j['closing_date'] ? 'Closes ' . e((new DateTimeImmutable($j['closing_date']))->format('j M')) : 'Posted ' . e(fmt_date($j['published_at'], 'j M')) ?></p>
          </li>
        <?php endforeach ?>
      </ul>
      <?= view('partials/pagination', ['total' => $total, 'per' => $per, 'page' => $page, 'path' => '/jobs', 'query' => $query]) ?>
    <?php else: ?>
      <?= view('partials/empty', ['message' => $query ? 'No jobs match these filters.' : 'No jobs on the board right now. Check back soon.']) ?>
    <?php endif ?>
  </div>
</section>
