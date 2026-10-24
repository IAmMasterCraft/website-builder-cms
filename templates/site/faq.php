<?= view('partials/page_hero', ['heading' => 'Frequently asked questions', 'intro' => 'Quick answers to common questions.', 'crumbs' => ['FAQ' => null]]) ?>
<section class="section">
  <div class="container narrow-wide">
    <?php if (count($groups) > 1): ?>
      <nav class="filters" aria-label="FAQ categories"><?php foreach (array_keys($groups) as $cat): ?><a class="chip" href="#<?= e(slugify($cat)) ?>"><?= e($cat) ?></a><?php endforeach ?></nav>
    <?php endif ?>
    <?php if ($groups): foreach ($groups as $cat => $rows): ?>
      <section class="faq-group" aria-labelledby="<?= e(slugify($cat)) ?>">
        <h2 id="<?= e(slugify($cat)) ?>" class="h3 faq-group-title"><?= e($cat) ?></h2>
        <?php foreach ($rows as $f): ?>
          <details class="faq" id="faq-<?= (int) $f['id'] ?>">
            <summary><?= e($f['question']) ?> <span class="faq-icon" aria-hidden="true"></span></summary>
            <div class="faq-body"><div class="prose"><?= rich($f['answer']) ?></div></div>
          </details>
        <?php endforeach ?>
      </section>
    <?php endforeach; else: ?><?= view('partials/empty', ['message' => 'No questions yet.']) ?><?php endif ?>
  </div>
</section>
