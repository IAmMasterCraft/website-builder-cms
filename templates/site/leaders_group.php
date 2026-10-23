<?= view('partials/page_hero', ['heading' => $group['name'] . ($past ? ': past leaders' : ''), 'intro' => $group['description'], 'crumbs' => ['Leaders' => '/leaders', $group['name'] => null]]) ?>
<section class="section">
  <div class="container">
    <div class="filter-bar">
      <?php if (count($groups) > 1): ?>
      <nav class="filters" aria-label="Leader groups"><?php foreach ($groups as $g): ?><a class="chip" href="/leaders/<?= e($g['slug']) ?>"<?= $g['slug'] === $group['slug'] && !$past ? ' aria-current="page"' : '' ?>><?= e($g['name']) ?></a><?php endforeach ?></nav>
      <?php endif ?>
      <?php if ($past_count > 0 || $past): ?>
      <nav class="filters" aria-label="Term">
        <a class="chip" href="/leaders/<?= e($group['slug']) ?>"<?= !$past ? ' aria-current="true"' : '' ?>>Current</a>
        <a class="chip" href="/leaders/<?= e($group['slug']) ?>?term=past"<?= $past ? ' aria-current="true"' : '' ?>>Past leaders</a>
      </nav>
      <?php endif ?>
    </div>
    <?php if ($leaders): ?>
      <div class="grid small">
        <?php foreach ($leaders as $l): $photo = media_find($l['photo_media_id'] ? (int) $l['photo_media_id'] : null); ?>
          <article class="card person">
            <div class="card-media"><?= $photo ? img_html($photo, '(min-width: 40rem) 14rem, 50vw', '', '', true, 400) : '<span class="avatar-fallback" aria-hidden="true">' . e(mb_substr($l['name'], 0, 1)) . '</span>' ?></div>
            <div class="card-body">
              <h2 class="h3"><a href="/leaders/<?= e($group['slug']) ?>/<?= e($l['slug']) ?>"><?= e($l['name']) ?></a></h2>
              <span class="person-role"><?= e($l['role_title']) ?></span>
              <?php if ($past && $l['term_end']): ?><p class="muted">Served until <?= e((new DateTimeImmutable($l['term_end']))->format('M Y')) ?></p><?php endif ?>
            </div>
          </article>
        <?php endforeach ?>
      </div>
    <?php else: ?><?= view('partials/empty', ['message' => $past ? 'No past leaders recorded.' : 'No leaders listed yet.']) ?><?php endif ?>
  </div>
</section>
