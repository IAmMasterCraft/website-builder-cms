<?php /** @var array $page @var ?array $cover @var bool $is_home */ ?>
<?php if ($is_home): ?>
  <section class="hero"><div class="container"><div><h1><?= e($page['title']) ?></h1><?php if ($page['summary']): ?><p class="lede"><?= e($page['summary']) ?></p><?php endif ?></div>
  <?php if ($cover): ?><div class="hero-media"><?= img_html($cover, '(min-width: 56rem) 34rem, 100vw', '', '', false, 1600) ?></div><?php endif ?></div></section>
<?php else: ?>
  <?= view('partials/page_hero', ['heading' => $page['title'], 'intro' => $page['summary'], 'crumbs' => [$page['title'] => null]]) ?>
<?php endif ?>
<section class="section">
  <div class="container narrow">
    <?php if ($is_preview): ?><p class="notice warn">Preview: visitors cannot see this page (<?= e($page['status']) ?>, <?= e($page['visibility']) ?>).</p><?php endif ?>
    <?php if ($cover && !$is_home): ?><figure class="article-cover"><?= img_html($cover, '(min-width: 44rem) 44rem, 100vw', '', '', false, 1600) ?></figure><?php endif ?>
    <div class="prose"><?= rich($page['body']) ?></div>
  </div>
</section>
