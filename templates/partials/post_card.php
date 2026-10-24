<?php /** @var array $post @var ?string $headingTag @var ?bool $feature */
$h = $headingTag ?? 'h3';
$cover = media_find($post['cover_media_id'] ? (int) $post['cover_media_id'] : null);
$feature = !empty($feature); ?>
<article class="card post-card<?= $feature ? ' is-feature' : '' ?>" data-reveal>
  <div class="card-media<?= $cover ? '' : ' no-image' ?>">
    <?= $cover ? img_html($cover, $feature ? '(min-width: 56rem) 44rem, 100vw' : '(min-width: 72rem) 24rem, (min-width: 40rem) 45vw, 100vw', '', '', true, $feature ? 1600 : 800) : icon('image') ?>
    <?php if (($post['category_name'] ?? '') !== ''): ?><span class="badge glass chip-over"><?= e($post['category_name']) ?></span><?php endif ?>
  </div>
  <div class="card-body">
    <div class="card-meta">
      <span><?= icon('calendar') ?><time datetime="<?= e(iso_date($post['publish_at'])) ?>"><?= e(fmt_date($post['publish_at'])) ?></time></span>
      <?php if ($post['visibility'] === 'members'): ?><span class="lock"><?= icon('lock') ?> <?= e(t('members.only')) ?></span><?php endif ?>
    </div>
    <<?= $h ?>><a href="<?= e(post_url($post)) ?>"><?= e($post['title']) ?></a></<?= $h ?>>
    <p><?= e(excerpt($post['summary'] ?: $post['body'], $feature ? 220 : 140)) ?></p>
    <div class="card-foot">
      <?php $ec = engagement_counts('post', (int) $post['id']); if ((int) ($post['allow_likes'] ?? 1) || (int) ($post['allow_comments'] ?? 1)): ?>
        <p class="card-counts muted">
          <?php if ((int) ($post['allow_likes'] ?? 1)): ?><span><?= icon('heart') ?><span class="tabular"><?= $ec['likes'] ?></span><span class="sr-only"> likes</span></span><?php endif ?>
          <?php if ((int) ($post['allow_comments'] ?? 1)): ?><span><?= icon('message') ?><span class="tabular"><?= $ec['comments'] ?></span><span class="sr-only"> comments</span></span><?php endif ?>
        </p>
      <?php else: ?><span></span><?php endif ?>
      <span class="card-go" aria-hidden="true"><?= icon('arrow-right') ?></span>
    </div>
  </div>
</article>
