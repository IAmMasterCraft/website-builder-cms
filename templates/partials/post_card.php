<?php /** @var array $post @var string $headingTag */ $h = $headingTag ?? 'h3'; ?>
<article class="card">
  <div class="card-media">
    <?= img_html(media_find($post['cover_media_id'] ? (int) $post['cover_media_id'] : null), '(min-width: 72rem) 22rem, (min-width: 40rem) 45vw, 100vw', '') ?>
  </div>
  <div class="card-body">
    <div class="card-meta">
      <span class="badge"><?= e($post['category_name'] ?? '') ?></span>
      <span><?= icon('calendar') ?><time datetime="<?= e(iso_date($post['publish_at'])) ?>"><?= e(fmt_date($post['publish_at'])) ?></time></span>
      <?php if ($post['visibility'] === 'members'): ?><span class="lock"><?= icon('lock') ?> <?= e(t('members.only')) ?></span><?php endif ?>
    </div>
    <<?= $h ?>><a href="<?= e(post_url($post)) ?>"><?= e($post['title']) ?></a></<?= $h ?>>
    <p><?= e(excerpt($post['summary'] ?: $post['body'], 150)) ?></p>
    <?php $ec = engagement_counts('post', (int) $post['id']); if ((int) ($post['allow_likes'] ?? 1) || (int) ($post['allow_comments'] ?? 1)): ?>
      <p class="card-counts muted">
        <?php if ((int) ($post['allow_likes'] ?? 1)): ?><span><?= icon('heart') ?><span class="tabular"><?= $ec['likes'] ?></span><span class="sr-only"> likes</span></span><?php endif ?>
        <?php if ((int) ($post['allow_comments'] ?? 1)): ?><span><?= icon('help') ?><span class="tabular"><?= $ec['comments'] ?></span><span class="sr-only"> comments</span></span><?php endif ?>
      </p>
    <?php endif ?>
  </div>
</article>
