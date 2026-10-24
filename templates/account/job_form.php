<?php /** @var ?array $job @var array $values @var array $errors */ ?>
<section class="dash-card narrow-card">
  <?php if ($errors): ?><div class="flash error" role="alert"><?= icon('alert') ?> Please fix the highlighted fields.</div><?php endif ?>
  <?php if ($job && $job['status'] === 'published'): ?><p class="notice">Changes to a live job may need approval again before they appear.</p><?php endif ?>
  <form class="form" method="post" action="<?= $job ? '/account/jobs/' . (int) $job['id'] : '/account/jobs' ?>" novalidate>
    <?= csrf_field() ?>
    <?= view('partials/job_fields', ['values' => $values, 'errors' => $errors]) ?>
    <p class="hint muted">Posts are checked by an administrator before they appear, and are visible to members only.</p>
    <p class="btn-row"><button class="btn accent" type="submit"><?= icon('check') ?> <?= $job ? 'Save changes' : 'Submit job' ?></button><a class="btn ghost" href="/account/jobs">Cancel</a></p>
  </form>
</section>
