<?php /** @var array $album @var array $photos */ ?>
<?= view('partials/page_hero', ['heading' => $album['title'], 'intro' => $album['summary'], 'crumbs' => ['Gallery' => '/gallery', $album['title'] => null]]) ?>
<section class="section">
  <div class="container">
    <?php if ($others): ?>
      <nav class="filters" aria-label="Other albums">
        <span class="filters-label">Albums:</span>
        <a class="chip" href="/gallery">All</a>
        <a class="chip" href="/gallery/<?= e($album['slug']) ?>" aria-current="true"><?= e($album['title']) ?></a>
        <?php foreach ($others as $o): ?><a class="chip" href="/gallery/<?= e($o['slug']) ?>"><?= e($o['title']) ?></a><?php endforeach ?>
      </nav>
    <?php endif ?>
    <?php if ($album['body']): ?><div class="prose"><?= rich($album['body']) ?></div><?php endif ?>
    <?php if ($locked): ?>
      <div class="notice"><p><strong><?= e(t('members.only')) ?>.</strong> <?= e(t('members.login_to_view')) ?></p><a class="btn" href="/login?next=<?= e(rawurlencode('/gallery/' . $album['slug'])) ?>">Log in</a></div>
    <?php elseif ($photos): ?>
      <p class="muted tabular"><?= e(pluralise($total, 'photo')) ?> · select a photo to view it larger</p>
      <ul class="photo-masonry" data-lightbox data-reveal-group>
        <?php foreach ($photos as $ph): $m = media_find((int) $ph['media_id']); if ($m === null) { continue; } ?>
          <li data-reveal><a href="<?= e(media_url($m, 1600)) ?>" data-caption="<?= e($ph['caption']) ?>">
            <?= img_html($m, '(min-width: 72rem) 24rem, (min-width: 40rem) 45vw, 100vw', $ph['alt'] !== '' ? $ph['alt'] : ($ph['caption'] !== '' ? $ph['caption'] : $m['alt']), '', true, 800) ?>
          </a></li>
        <?php endforeach ?>
      </ul>
      <?= view('partials/pagination', ['total' => $total, 'per' => $per, 'page' => $page, 'path' => '/gallery/' . $album['slug'], 'query' => []]) ?>
    <?php else: ?>
      <?= view('partials/empty', ['message' => 'This album has no photos yet.']) ?>
    <?php endif ?>
  </div>
</section>
