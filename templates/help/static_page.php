<?php /** One page of the static documentation site (bin/docs-html). @var string $slug @var array $page @var array $toc @var ?array $prev @var ?array $next */
$css = help_relative(help_static_path($slug), 'docs.css');
$home = help_url('index', 'static', $slug);
$onPage = array_values(array_filter($page['headings'], static fn (array $h): bool => $h['level'] === 2));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page['title']) ?> · Gather documentation</title>
<meta name="description" content="Gather documentation: <?= e($page['title']) ?>">
<link rel="stylesheet" href="<?= e($css) ?>">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="top">
  <a class="brand" href="<?= e($home) ?>"><span class="brand-mark" aria-hidden="true">G</span> Gather documentation</a>
</header>
<div class="layout">
  <nav class="toc" aria-label="Documentation contents">
    <?php foreach ($toc as $section => $docs): $open = in_array($slug, array_column($docs, 'slug'), true); ?>
      <details<?= $open ? ' open' : '' ?>>
        <summary><?= e($section) ?></summary>
        <ul>
          <?php foreach ($docs as $d): ?>
            <li><a href="<?= e(help_url($d['slug'], 'static', $slug)) ?>"<?= $d['slug'] === $slug ? ' aria-current="page"' : '' ?>><?= e($d['title']) ?></a></li>
          <?php endforeach ?>
        </ul>
      </details>
    <?php endforeach ?>
  </nav>
  <main id="main">
    <?php [$h1, $body] = help_split_h1($page['html']); /* Core\Markdown output: text escaped, links and images filtered */ ?>
    <article class="md">
      <?= $h1 ?>
      <?php if (count($onPage) > 2): ?>
        <nav class="onpage" aria-label="On this page"><p>On this page</p>
          <ul><?php foreach ($onPage as $h): ?><li><a href="#<?= e($h['id']) ?>"><?= e($h['text']) ?></a></li><?php endforeach ?></ul>
        </nav>
      <?php endif ?>
      <?= $body ?>
    </article>
    <nav class="pager" aria-label="Previous and next guide">
      <?php if ($prev): ?><a href="<?= e(help_url($prev['slug'], 'static', $slug)) ?>"><span>Previous</span><?= e($prev['title']) ?></a><?php else: ?><span></span><?php endif ?>
      <?php if ($next): ?><a class="next" href="<?= e(help_url($next['slug'], 'static', $slug)) ?>"><span>Next</span><?= e($next['title']) ?></a><?php endif ?>
    </nav>
  </main>
</div>
</body>
</html>
