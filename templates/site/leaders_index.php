<?= view('partials/page_hero', ['heading' => 'Our leaders', 'intro' => 'The volunteers who guide the organisation.', 'crumbs' => ['Leaders' => null]]) ?>
<section class="section">
  <div class="container">
    <?php if ($groups): ?>
      <ul class="dir-list">
        <?php foreach ($groups as $g): ?>
          <li class="dir-item"><h2><a href="/leaders/<?= e($g['slug']) ?>"><?= e($g['name']) ?></a></h2><p class="muted"><?= e($g['description'] ?? '') ?></p><p class="tabular"><?= e(pluralise((int) $g['n'], 'person', 'people')) ?></p></li>
        <?php endforeach ?>
      </ul>
    <?php else: ?><?= view('partials/empty') ?><?php endif ?>
  </div>
</section>
