<?php /** B3 help centre. @var string $slug @var ?array $page @var array $toc @var ?array $prev @var ?array $next @var string $q @var ?array $results */
$onPage = $page !== null ? array_values(array_filter($page['headings'], static fn (array $h): bool => $h['level'] === 2)) : [];
?>
<div class="help-layout">
  <nav class="help-toc" id="help-toc" aria-label="Help contents">
    <p class="help-toc-title"><a href="/admin/help"><?= icon('book-open') ?><span>Help centre</span></a></p>
    <form class="help-search" role="search" method="get" action="/admin/help/search">
      <label for="help-q" class="sr-only">Search the help</label>
      <input type="search" id="help-q" name="q" value="<?= e($q) ?>" placeholder="Search the help…" maxlength="100">
      <button class="btn small" type="submit"><?= icon('search') ?><span class="sr-only">Search</span></button>
    </form>
    <?php foreach ($toc as $section => $docs): $open = in_array($slug, array_column($docs, 'slug'), true) || ($slug === '' && $section === 'Website manager guide'); ?>
      <details class="help-toc-group"<?= $open ? ' open' : '' ?>>
        <summary><?= e($section) ?> <span class="muted tabular">(<?= count($docs) ?>)</span></summary>
        <ul>
          <?php foreach ($docs as $d): ?>
            <li><a href="<?= e(help_url($d['slug'], 'admin')) ?>"<?= $d['slug'] === $slug ? ' aria-current="page"' : '' ?>><?= e($d['title']) ?></a></li>
          <?php endforeach ?>
        </ul>
      </details>
    <?php endforeach ?>
  </nav>
  <div class="help-main">
    <?php if ($results !== null): ?>
      <p class="crumbs"><a href="/admin/help">Help centre</a> /</p>
      <h1>Search the help</h1>
      <?php if ($q === '' || help_search_terms($q) === []): ?>
        <p>Type at least two letters to search every guide.</p>
      <?php elseif ($results === []): ?>
        <p role="status">Nothing found for “<?= e($q) ?>”. Try fewer or different words, or browse the contents.</p>
      <?php else: ?>
        <p role="status" class="tabular"><?= e(pluralise(count($results), 'result')) ?> for “<?= e($q) ?>”<?= count($results) >= HELP_SEARCH_MAX_RESULTS ? ' (best matches first)' : '' ?></p>
        <ol class="help-results">
          <?php foreach ($results as $r): ?>
            <li class="panel panel-body">
              <h2><a href="<?= e(help_url($r['slug'], 'admin') . ($r['id'] !== '' ? '#' . $r['id'] : '')) ?>"><?= e($r['heading']) ?></a></h2>
              <?php if ($r['heading'] !== $r['title']): ?><p class="muted small-text"><?= e($r['title']) ?></p><?php endif ?>
              <p><?php foreach ($r['snippet'] as [$text, $match]): ?><?= $match ? '<mark>' . e($text) . '</mark>' : e($text) ?><?php endforeach ?></p>
            </li>
          <?php endforeach ?>
        </ol>
      <?php endif ?>
    <?php else: ?>
      <?php if ($slug !== 'index'): ?><p class="crumbs"><a href="/admin/help">Help centre</a> /</p><?php endif ?>
      <?php [$h1, $body] = help_split_h1($page['html']); /* both parts come from Core\Markdown: every text escaped, links and images filtered */ ?>
      <article class="md">
        <?= $h1 ?>
        <?php if (count($onPage) > 2): ?>
          <nav class="help-onpage" aria-label="On this page">
            <p class="help-onpage-title">On this page</p>
            <ul><?php foreach ($onPage as $h): ?><li><a href="#<?= e($h['id']) ?>"><?= e($h['text']) ?></a></li><?php endforeach ?></ul>
          </nav>
        <?php endif ?>
        <?= $body ?>
      </article>
      <nav class="help-pager" aria-label="Previous and next guide">
        <?php if ($prev): ?><a class="help-prev" href="<?= e(help_url($prev['slug'], 'admin')) ?>"><span class="muted">Previous</span><span><?= icon('chevron-left') ?><?= e($prev['title']) ?></span></a><?php else: ?><span></span><?php endif ?>
        <?php if ($next): ?><a class="help-next" href="<?= e(help_url($next['slug'], 'admin')) ?>"><span class="muted">Next</span><span><?= e($next['title']) ?><?= icon('chevron-right') ?></span></a><?php endif ?>
      </nav>
    <?php endif ?>
  </div>
</div>
