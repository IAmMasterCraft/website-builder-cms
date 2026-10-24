<?= view('partials/page_hero', ['heading' => 'Gallery', 'intro' => 'Photos from our events and projects, organised into albums.', 'crumbs' => ['Gallery' => null]]) ?>
<section class="section">
  <div class="container">
    <?php if ($albums): ?>
      <nav class="filters" aria-label="Jump to an album">
        <span class="filters-label">Albums:</span>
        <a class="chip" href="/gallery" aria-current="true">All</a>
        <?php foreach ($albums as $a): ?><a class="chip" href="/gallery/<?= e($a['slug']) ?>"><?= e($a['title']) ?></a><?php endforeach ?>
      </nav>
      <div class="grid cols-3 albums" data-reveal-group>
        <?php foreach ($albums as $i => $a): $cover = media_find($a['cover_media_id'] ? (int) $a['cover_media_id'] : null); ?>
          <article class="album-tile<?= $i === 0 ? ' is-feature' : '' ?>" data-reveal>
            <?= $cover ? img_html($cover, $i === 0 ? '(min-width: 64rem) 52rem, 100vw' : '(min-width: 64rem) 26rem, (min-width: 40rem) 45vw, 100vw', '', '', $i !== 0, $i === 0 ? 1600 : 800) : '' ?>
            <div class="album-info">
              <div class="card-meta">
                <span class="badge glass"><?= icon('images') ?><?= e(pluralise((int) $a['photo_count'], 'photo')) ?></span>
                <?php if ($a['visibility'] === 'members'): ?><span class="badge glass"><?= icon('lock') ?> <?= e(t('members.only')) ?></span><?php endif ?>
              </div>
              <h2><a href="/gallery/<?= e($a['slug']) ?>"><?= e($a['title']) ?></a></h2>
              <p><?= $a['album_date'] ? e((new DateTimeImmutable($a['album_date']))->format('F Y')) . ' · ' : '' ?><?= e(excerpt($a['summary'] ?: $a['body'], 90)) ?></p>
            </div>
            <span class="card-go" aria-hidden="true"><?= icon('arrow-up-right') ?></span>
          </article>
        <?php endforeach ?>
      </div>
    <?php else: ?>
      <?= view('partials/empty', ['message' => 'No albums yet.']) ?>
    <?php endif ?>
  </div>
</section>
