<?php /** @var array $page @var bool $is_home @var bool $has_hero @var string $sections_html @var bool $is_preview */ ?>
<?php if ($is_preview): ?><div class="container"><p class="notice warn preview-note">Preview: visitors cannot see this page (<?= e($page['status']) ?>, <?= e($page['visibility']) ?>).</p></div><?php endif ?>
<?php if (!$has_hero): ?>
  <?php if ($is_home): ?><h1 class="sr-only"><?= e(setting('site.name')) ?></h1>
  <?php else: ?><?= view('partials/page_hero', ['heading' => $page['title'], 'intro' => $page['summary'], 'crumbs' => [$page['title'] => null]]) ?><?php endif ?>
<?php endif ?>
<?= $sections_html ?>
<?php if (trim($sections_html) === ''): ?><section class="section"><div class="container narrow"><?= view('partials/empty', ['message' => 'This page has no content yet.']) ?></div></section><?php endif ?>
