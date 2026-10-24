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
      <div class="grid cols-4 people" data-reveal-group>
        <?php foreach ($leaders as $l): ?><?= view('partials/person_card', ['leader' => $l, 'groupSlug' => $group['slug'], 'headingTag' => 'h2', 'past' => $past]) ?><?php endforeach ?>
      </div>
    <?php else: ?><?= view('partials/empty', ['message' => $past ? 'No past leaders recorded.' : 'No leaders listed yet.']) ?><?php endif ?>
  </div>
</section>
