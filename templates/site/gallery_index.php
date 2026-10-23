<?= view('partials/page_hero', ['heading' => 'Gallery', 'intro' => 'Photos from our events and projects, organised into albums.', 'crumbs' => ['Gallery' => null]]) ?>
<section class="section">
  <div class="container">
    <?php if ($albums): ?>
      <nav class="filters" aria-label="Jump to an album">
        <span class="filters-label">Albums:</span>
        <a class="chip" href="/gallery" aria-current="true">All</a>
        <?php foreach ($albums as $a): ?><a class="chip" href="/gallery/<?= e($a['slug']) ?>"><?= e($a['title']) ?></a><?php endforeach ?>
      </nav>
      <div class="grid">
        <?php foreach ($albums as $a): ?>
          <article class="card album-card">
            <div class="card-media"><?= img_html(media_find($a['cover_media_id'] ? (int) $a['cover_media_id'] : null), '(min-width: 72rem) 22rem, (min-width: 40rem) 45vw, 100vw', '') ?></div>
            <div class="card-body">
              <div class="card-meta">
                <span><?= icon('image') ?><?= e(pluralise((int) $a['photo_count'], 'photo')) ?></span>
                <?php if ($a['album_date']): ?><span><?= icon('calendar') ?><?= e((new DateTimeImmutable($a['album_date']))->format('M Y')) ?></span><?php endif ?>
                <?php if ($a['visibility'] === 'members'): ?><span class="lock"><?= icon('lock') ?> <?= e(t('members.only')) ?></span><?php endif ?>
              </div>
              <h2 class="h3"><a href="/gallery/<?= e($a['slug']) ?>"><?= e($a['title']) ?></a></h2>
              <p><?= e(excerpt($a['summary'] ?: $a['body'], 120)) ?></p>
            </div>
          </article>
        <?php endforeach ?>
      </div>
    <?php else: ?>
      <?= view('partials/empty', ['message' => 'No albums yet.']) ?>
    <?php endif ?>
  </div>
</section>
