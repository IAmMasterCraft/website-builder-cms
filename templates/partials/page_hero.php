<?php /** @var string $heading @var ?string $intro @var array $crumbs [label => href] */ ?>
<div class="page-hero">
  <div class="container">
    <?php if (!empty($crumbs)): ?>
    <nav class="breadcrumbs" aria-label="Breadcrumb"><ol>
      <li><a href="/">Home</a></li>
      <?php foreach ($crumbs as $label => $href): ?>
        <li><?php if ($href !== null): ?><a href="<?= e($href) ?>"><?= e($label) ?></a><?php else: ?><span aria-current="page"><?= e($label) ?></span><?php endif ?></li>
      <?php endforeach ?>
    </ol></nav>
    <?php endif ?>
    <h1><?= e($heading) ?></h1>
    <?php if (!empty($intro)): ?><p><?= e($intro) ?></p><?php endif ?>
  </div>
</div>
