<?php /** @var array $rows */ ?>
<p class="lede">Documents shared with members only.</p>
<?php if ($rows): foreach ($rows as $d): $ext = strtoupper(pathinfo((string) $d['path'], PATHINFO_EXTENSION)); ?>
  <div class="file-row">
    <span class="file-icon" aria-hidden="true"><?= e($ext) ?></span>
    <div class="file-main"><h2 class="h3"><?= e($d['title']) ?></h2><p class="tabular"><?= e($ext) ?> · <?= e(number_format(((int) $d['size']) / 1024, 0)) ?> KB<?= $d['category'] !== '' ? ' · ' . e($d['category']) : '' ?></p></div>
    <a class="btn small" href="/downloads/<?= (int) $d['id'] ?>/file"><?= icon('download') ?> Download<span class="sr-only"> <?= e($d['title']) ?></span></a>
  </div>
<?php endforeach; else: ?><?= view('partials/empty', ['message' => 'No member downloads yet.']) ?><?php endif ?>
