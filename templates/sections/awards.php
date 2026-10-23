<?php /** @var array $s @var array $d @var string $h */ ?>
<div class="container">
  <?= view('sections/_head', ['s' => $s, 'h' => $h]) ?>
  <?php if (!$d['by_year']): ?>
    <?= view('partials/empty', ['message' => 'No awards yet.']) ?>
  <?php else: foreach ($d['by_year'] as $y => $rows): ?>
    <div class="year-block">
      <?php if (count($d['by_year']) > 1 || $s['year'] !== 'latest'): ?><h3 class="year-heading tabular"><?= (int) $y ?></h3><?php endif ?>
      <div class="grid">
        <?php foreach ($rows as $a): $photo = media_find($a['photo_media_id'] ? (int) $a['photo_media_id'] : null); ?>
          <article class="card">
            <?php if ($photo): ?><div class="card-media"><?= img_html($photo, '(min-width: 72rem) 22rem, 100vw', '') ?></div><?php endif ?>
            <div class="card-body">
              <span class="badge brand"><?= icon('award') ?> <?= e($a['title']) ?><?= count($d['by_year']) === 1 && $s['year'] === 'latest' ? ' ' . (int) $y : '' ?></span>
              <h4 class="h3"><?= e($a['recipient']) ?></h4>
              <?php if ($a['citation']): ?><p><?= e(excerpt($a['citation'], 160)) ?></p><?php endif ?>
            </div>
          </article>
        <?php endforeach ?>
      </div>
    </div>
  <?php endforeach; endif ?>
</div>
