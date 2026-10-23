<?php
/** @var int $total @var int $per @var int $page @var string $path @var array $query */
$pages = (int) ceil($total / max(1, $per));
if ($pages > 1):
    $window = array_unique(array_filter([1, $page - 1, $page, $page + 1, $pages], static fn ($n) => $n >= 1 && $n <= $pages));
    sort($window);
?>
<nav class="pagination" aria-label="Pagination">
  <?php if ($page > 1): ?><a href="<?= e(url_with($path, $query, ['page' => $page - 1])) ?>" rel="prev"><?= icon('chevron-left') ?><span class="sr-only">Previous page</span></a><?php endif ?>
  <?php $prev = 0; foreach ($window as $n): ?>
    <?php if ($n - $prev > 1): ?><span aria-hidden="true">…</span><?php endif ?>
    <?php if ($n === $page): ?><span aria-current="page"><?= (int) $n ?></span><?php else: ?><a href="<?= e(url_with($path, $query, ['page' => $n])) ?>"><span class="sr-only">Page </span><?= (int) $n ?></a><?php endif ?>
  <?php $prev = $n; endforeach ?>
  <?php if ($page < $pages): ?><a href="<?= e(url_with($path, $query, ['page' => $page + 1])) ?>" rel="next"><?= icon('chevron-right') ?><span class="sr-only">Next page</span></a><?php endif ?>
</nav>
<?php endif ?>
