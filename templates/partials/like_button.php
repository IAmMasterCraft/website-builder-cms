<?php /** @var string $type @var int $id @var ?array $viewer @var string $return @var string $label */
$count = engagement_counts($type, $id)['likes'];
$liked = user_liked($viewer['id'] ?? null, $type, $id);
$label ??= 'Like';
?>
<?php if (viewer_is_member($viewer)): ?>
  <form class="like-form" method="post" action="/like" id="like-<?= e($type) ?>-<?= (int) $id ?>" data-like>
    <?= csrf_field() ?><input type="hidden" name="item_type" value="<?= e($type) ?>"><input type="hidden" name="item_id" value="<?= (int) $id ?>"><input type="hidden" name="return" value="<?= e($return) ?>">
    <button class="like-btn" type="submit" aria-pressed="<?= $liked ? 'true' : 'false' ?>"><?= icon('heart') ?><span class="like-label"><?= $liked ? 'Liked' : e($label) ?></span><span class="like-count tabular" data-like-count><?= (int) $count ?></span><span class="sr-only"> likes</span></button>
  </form>
<?php else: ?>
  <span class="like-static" id="like-<?= e($type) ?>-<?= (int) $id ?>"><?= icon('heart') ?><span class="tabular"><?= (int) $count ?></span><span class="sr-only"> likes</span><?php if ($viewer === null): ?> <a href="/login?next=<?= e(rawurlencode($return)) ?>" class="small-link">Log in to like</a><?php endif ?></span>
<?php endif ?>
