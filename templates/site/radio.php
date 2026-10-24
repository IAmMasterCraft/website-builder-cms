<?php /** @var array $rows @var int $total @var int $page @var int $per @var array $categories @var string $category @var string $q @var bool $enabled */
$query = array_filter(['category' => $category, 'q' => $q]);
?>
<section class="radio-hero on-dark">
  <div class="container">
    <nav class="breadcrumbs on-dark" aria-label="Breadcrumb"><ol><li><a href="/">Home</a></li><li><span aria-current="page">Radio</span></li></ol></nav>
    <div class="radio-intro"><span class="eyebrow">Live and on demand</span><h1>Community radio</h1><p>Music, notices and conversations from the neighbourhood, live and on demand.</p></div>
    <?php if ($enabled): ?>
      <?= view('partials/radio_player', ['size' => 'full', 'heading' => setting('site.name') . ' Radio', 'headingTag' => 'h2']) ?>
    <?php else: ?>
      <div class="radio-player size-full is-offline">
        <div class="rp-stage"><div class="rp-cover"><span class="rp-cover-fallback" aria-hidden="true"><?= icon('radio') ?></span></div></div>
        <div class="rp-body"><p class="rp-eyebrow"><span class="rp-dot" aria-hidden="true"></span>Off air</p><h2 class="rp-title">The live station is switched off</h2><p class="rp-artist">You can still listen to our programmes below.</p></div>
      </div>
    <?php endif ?>
  </div>
</section>
<section class="section" aria-labelledby="library-title">
  <div class="container">
    <div class="section-head"><div><span class="eyebrow">On demand</span><h2 id="library-title">Listen again</h2><p>Programmes, interviews and music from our community, newest first.</p></div></div>
    <div class="library-toolbar">
      <?php if ($categories): ?><?= view('partials/filters', ['label' => 'Category', 'param' => 'category', 'options' => $categories, 'current' => $category, 'path' => '/radio', 'query' => $query]) ?><?php endif ?>
      <form class="search-form compact" method="get" action="/radio" role="search">
        <?php if ($category !== ''): ?><input type="hidden" name="category" value="<?= e($category) ?>"><?php endif ?>
        <label for="rq" class="sr-only">Search programmes</label>
        <input type="search" id="rq" name="q" value="<?= e($q) ?>" placeholder="Search programmes" maxlength="100">
        <button class="btn small" type="submit"><?= icon('search') ?><span class="sr-only">Search</span></button>
      </form>
    </div>
    <?php if ($rows): ?>
      <ul class="episode-list">
        <?php foreach ($rows as $r): $j = radio_media_json($r); $cover = media_find($r['cover_media_id'] ? (int) $r['cover_media_id'] : null); ?>
          <li class="episode" id="programme-<?= (int) $r['id'] ?>">
            <div class="episode-cover"><?= $cover ? img_html($cover, '8rem', '', '', true, 400) : '<span class="rp-cover-fallback" aria-hidden="true">' . icon('radio') . '</span>' ?></div>
            <div class="episode-main">
              <p class="episode-meta muted small-text"><?= $r['category_name'] ? '<span class="badge">' . e($r['category_name']) . '</span> ' : '' ?><span class="tabular"><?= e(radio_fmt_duration($r['duration'] !== null ? (float) $r['duration'] : null)) ?></span> · <?= e(fmt_date($r['publish_at'] ?? $r['created_at'])) ?></p>
              <h3><?= e($r['title']) ?></h3>
              <?php if ($r['artist'] !== ''): ?><p class="episode-artist"><?= e($r['artist']) ?></p><?php endif ?>
              <?php if ((string) $r['description'] !== ''): ?><p class="episode-desc"><?= e(excerpt($r['description'], 240)) ?></p><?php endif ?>
              <?php if ($j['media_type'] === 'video'): ?>
                <video controls preload="none" playsinline src="<?= e($j['url']) ?>"<?= $cover ? ' poster="' . e(media_url($cover, 800)) . '"' : '' ?> aria-label="Play <?= e($r['title']) ?>"></video>
              <?php else: ?>
                <audio controls preload="none" src="<?= e($j['url']) ?>" aria-label="Play <?= e($r['title']) ?>"></audio>
              <?php endif ?>
            </div>
          </li>
        <?php endforeach ?>
      </ul>
      <?= view('partials/pagination', ['total' => $total, 'per' => $per, 'page' => $page, 'path' => '/radio', 'query' => $query]) ?>
    <?php else: ?>
      <?= view('partials/empty', ['message' => $query ? 'No programmes match your search.' : 'No programmes in the library yet.']) ?>
    <?php endif ?>
  </div>
</section>
