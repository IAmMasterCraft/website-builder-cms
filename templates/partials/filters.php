<?php /** @var string $label @var string $param @var array $options [value => label] @var string $current @var string $path @var array $query */ ?>
<nav class="filters" aria-label="<?= e($label) ?>">
  <span class="filters-label"><?= e($label) ?>:</span>
  <a class="chip" href="<?= e(url_with($path, $query, [$param => null, 'page' => null])) ?>"<?= $current === '' ? ' aria-current="true"' : '' ?>>All</a>
  <?php foreach ($options as $value => $text): ?>
    <a class="chip" href="<?= e(url_with($path, $query, [$param => (string) $value, 'page' => null])) ?>"<?= $current === (string) $value ? ' aria-current="true"' : '' ?>><?= e($text) ?></a>
  <?php endforeach ?>
</nav>
