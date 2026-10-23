<?= view('partials/page_hero', ['heading' => 'Search', 'intro' => null, 'crumbs' => ['Search' => null]]) ?>
<section class="section">
  <div class="container narrow">
    <form class="search-form" role="search" method="get" action="/search">
      <label for="q" class="sr-only">Search the site</label>
      <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('search.placeholder')) ?>" maxlength="100" autofocus>
      <button class="btn" type="submit"><?= icon('search') ?> Search</button>
    </form>
    <?php if ($q !== ''): ?>
      <p class="muted tabular" role="status"><?= e(pluralise(count($results), 'result')) ?> for “<?= e($q) ?>”</p>
      <?php if ($results): ?>
        <ol class="search-results">
          <?php foreach ($results as $r): ?>
            <li><span class="badge muted"><?= e($r['type']) ?></span><h2><a href="<?= e($r['url']) ?>"><?= e($r['title']) ?></a></h2><p><?= e($r['excerpt']) ?></p></li>
          <?php endforeach ?>
        </ol>
      <?php else: ?>
        <?= view('partials/empty', ['message' => t('search.none', ['q' => $q])]) ?>
      <?php endif ?>
    <?php endif ?>
  </div>
</section>
