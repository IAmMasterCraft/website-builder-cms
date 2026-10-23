<?php /** @var array $s @var array $d @var string $h */ ?>
<div class="container narrow">
  <?= view('sections/_head', ['s' => $s, 'h' => $h]) ?>
  <?php if ($d['rows']): foreach ($d['rows'] as $row): $ext = strtoupper(pathinfo((string) $row['path'], PATHINFO_EXTENSION)); $locked = $row['visibility'] === 'members' && !$d['is_member']; ?>
    <div class="file-row">
      <span class="file-icon" aria-hidden="true"><?= e($ext) ?></span>
      <div class="file-main">
        <h3 class="h3"><?= e($row['title']) ?></h3>
        <p class="tabular"><?= e($ext) ?> · <?= e(number_format(((int) $row['size']) / 1024, 0)) ?> KB<?php if ($row['visibility'] === 'members'): ?> · <span class="lock"><?= icon('lock') ?> <?= e(t('members.only')) ?></span><?php endif ?></p>
      </div>
      <?php if ($locked): ?>
        <a class="btn ghost small" href="/login?next=%2Fdownloads"><?= icon('lock') ?> Log in<span class="sr-only"> to download <?= e($row['title']) ?></span></a>
      <?php else: ?>
        <a class="btn small" href="/downloads/<?= (int) $row['id'] ?>/file"><?= icon('download') ?> Download<span class="sr-only"> <?= e($row['title']) ?></span></a>
      <?php endif ?>
    </div>
  <?php endforeach; else: ?><?= view('partials/empty', ['message' => 'No downloads yet.']) ?><?php endif ?>
</div>
