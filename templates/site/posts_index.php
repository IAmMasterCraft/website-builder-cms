<?php /** @var string $base @var array $rows */ ?>
<?= view('partials/page_hero', ['heading' => $tag_name !== '' ? $heading . ': ' . $tag_name : $heading, 'intro' => $intro, 'crumbs' => [$heading => null]]) ?>
<section class="section">
  <div class="container">
    <div class="filter-bar">
      <?= view('partials/filters', ['label' => 'When', 'param' => 'when', 'options' => ARCHIVE_FILTERS, 'current' => $when, 'path' => '/' . $base, 'query' => $query]) ?>
      <?php if ($categories): ?>
        <?= view('partials/filters', ['label' => 'Category', 'param' => 'category', 'options' => $categories, 'current' => $category, 'path' => '/' . $base, 'query' => $query]) ?>
      <?php endif ?>
    </div>
    <?php if ($tag !== ''): ?>
      <p class="muted">Showing posts tagged “<?= e($tag_name ?: $tag) ?>”. <a href="<?= e(url_with('/' . $base, $query, ['tag' => null])) ?>">Clear tag</a></p>
    <?php endif ?>
    <p class="muted tabular" role="status"><?= e(pluralise($total, 'post')) ?></p>
    <?php if ($rows): ?>
      <div class="grid"><?php foreach ($rows as $post): ?><?= view('partials/post_card', ['post' => $post, 'headingTag' => 'h2']) ?><?php endforeach ?></div>
    <?php else: ?>
      <?= view('partials/empty', ['message' => 'No posts match these filters.']) ?>
    <?php endif ?>
    <?= view('partials/pagination', ['total' => $total, 'per' => $per, 'page' => $page, 'path' => '/' . $base, 'query' => $query]) ?>
  </div>
</section>
