<?= view('partials/page_hero', ['heading' => 'Awards', 'intro' => 'Celebrating members and volunteers who made a difference.', 'crumbs' => ['Awards' => null]]) ?>
<section class="section">
  <div class="container">
    <?php if ($years): ?><?= view('partials/filters', ['label' => 'Year', 'param' => 'year', 'options' => array_combine($years, $years), 'current' => $year ? (string) $year : '', 'path' => '/awards', 'query' => $year ? ['year' => $year] : []]) ?><?php endif ?>
    <?php if ($by_year): foreach ($by_year as $y => $rows): ?>
      <section class="year-block" aria-labelledby="y-<?= (int) $y ?>">
        <h2 class="year-heading tabular" id="y-<?= (int) $y ?>"><?= (int) $y ?></h2>
        <div class="grid">
          <?php foreach ($rows as $a): $photo = media_find($a['photo_media_id'] ? (int) $a['photo_media_id'] : null); ?>
            <article class="card" id="award-<?= (int) $a['id'] ?>">
              <?php if ($photo): ?><div class="card-media"><?= img_html($photo, '(min-width: 72rem) 22rem, 100vw', '') ?></div><?php endif ?>
              <div class="card-body">
                <span class="badge brand"><?= icon('award') ?> <?= e($a['title']) ?></span>
                <h3><?= e($a['recipient']) ?></h3>
                <?php if ($a['citation']): ?><div class="prose"><?= rich($a['citation']) ?></div><?php endif ?>
              </div>
            </article>
          <?php endforeach ?>
        </div>
      </section>
    <?php endforeach; else: ?><?= view('partials/empty', ['message' => 'No awards yet.']) ?><?php endif ?>
  </div>
</section>
