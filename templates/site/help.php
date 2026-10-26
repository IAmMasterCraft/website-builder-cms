<?php /** B3: the member guide on the public site. @var array $page @var string $q @var ?array $results */
$sections = array_values(array_filter($page['headings'], static fn (array $h): bool => $h['level'] === 2));
?>
<?= view('partials/page_hero', ['heading' => $page['title'], 'intro' => 'Joining, your dashboard and member card, email preferences, the job board and your account.', 'crumbs' => ['Help' => null]]) ?>
<div class="container help-page">
  <aside class="help-aside" aria-label="Member guide">
    <form class="help-search" role="search" method="get" action="/help">
      <label for="help-q" class="sr-only">Search the member guide</label>
      <input type="search" id="help-q" name="q" value="<?= e($q) ?>" placeholder="Search the guide…" maxlength="100">
      <button class="btn small" type="submit"><?= icon('search') ?><span class="sr-only">Search</span></button>
    </form>
    <?php if ($sections): ?>
      <nav aria-labelledby="help-contents"><h2 id="help-contents">Contents</h2>
        <ul><?php foreach ($sections as $h): ?><li><a href="#<?= e($h['id']) ?>"><?= e($h['text']) ?></a></li><?php endforeach ?></ul>
      </nav>
    <?php endif ?>
  </aside>
  <div>
    <?php if ($results !== null): ?>
      <section class="help-search-results" aria-labelledby="help-results-title">
        <h2 id="help-results-title">Search results</h2>
        <?php if ($results === []): ?>
          <p role="status">Nothing found for “<?= e($q) ?>”. Try other words, or read the guide below.</p>
        <?php else: ?>
          <p role="status"><?= e(pluralise(count($results), 'result')) ?> for “<?= e($q) ?>”.</p>
          <ol class="help-results">
            <?php foreach ($results as $r): ?>
              <li><a href="<?= e($r['id'] !== '' ? '#' . $r['id'] : '#main') ?>"><strong><?= e($r['heading']) ?></strong></a><br>
                <span><?php foreach ($r['snippet'] as [$text, $match]): ?><?= $match ? '<mark>' . e($text) . '</mark>' : e($text) ?><?php endforeach ?></span></li>
            <?php endforeach ?>
          </ol>
        <?php endif ?>
      </section>
    <?php endif ?>
    <article class="md"><?= help_without_h1($page['html']) /* rendered by Core\Markdown: every text escaped, links and images filtered */ ?></article>
  </div>
</div>
