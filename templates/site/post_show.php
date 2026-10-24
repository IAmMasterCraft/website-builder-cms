<?php /** @var array $post @var bool $locked @var ?array $cover @var array $related */ ?>
<article>
  <header class="article-head">
    <div class="container narrow-wide">
      <nav class="breadcrumbs" aria-label="Breadcrumb"><ol><li><a href="/">Home</a></li><li><a href="/<?= e($post['base']) ?>"><?= e($base_label) ?></a></li><li><span aria-current="page"><?= e($post['title']) ?></span></li></ol></nav>
      <?php if ($is_preview): ?><p class="notice warn">Preview: this post is not published yet (<?= e($post['status']) ?>).</p><?php endif ?>
      <span class="eyebrow"><?= e($post['category_name']) ?></span>
      <h1><?= e($post['title']) ?></h1>
      <?php if ($post['summary']): ?><p class="lede"><?= e($post['summary']) ?></p><?php endif ?>
      <div class="article-meta">
        <span><?= icon('calendar') ?><time datetime="<?= e(iso_date($post['publish_at'])) ?>"><?= e(fmt_date($post['publish_at'], 'j F Y')) ?></time></span>
        <span><?= icon('clock') ?><?= max(1, (int) round(str_word_count(strip_tags((string) $post['body'])) / 220)) ?> min read</span>
        <?php if ($post['visibility'] === 'members'): ?><span class="lock"><?= icon('lock') ?> <?= e(t('members.only')) ?></span><?php endif ?>
      </div>
    </div>
  </header>
  <?php if ($cover): preload_image($cover, '(min-width: 80rem) 80rem, 100vw'); ?><div class="container"><figure class="article-cover"><?= img_html($cover, '(min-width: 80rem) 80rem, 100vw', '', '', false, 1600) ?></figure></div><?php endif ?>
  <div class="section article-body">
    <div class="container narrow">
      <?php if ($locked): ?>
        <div class="notice"><p><strong><?= e(t('members.only')) ?>.</strong> <?= e(t('members.login_to_view')) ?></p><a class="btn" href="/login?next=<?= e(rawurlencode(post_url($post))) ?>">Log in</a></div>
      <?php else: ?>
        <div class="prose"><?= rich($post['body']) ?></div>
        <?php if ($post['tag_rows']): ?>
          <ul class="tag-list" aria-label="Tags"><?php foreach ($post['tag_rows'] as $tg): ?><li><a class="chip" href="/<?= e($post['base']) ?>?tag=<?= e(rawurlencode($tg['slug'])) ?>">#<?= e($tg['name']) ?></a></li><?php endforeach ?></ul>
        <?php endif ?>
        <?php if ((int) $post['allow_likes'] || (int) $post['allow_comments']): ?><div class="engage-row">
          <?php if ((int) $post['allow_likes']): ?><?= view('partials/like_button', ['type' => 'post', 'id' => (int) $post['id'], 'viewer' => $viewer, 'return' => post_url($post)]) ?><?php endif ?>
          <?php if ((int) $post['allow_comments']): ?><a class="comment-count-link" href="#comments"><?= icon('message') ?> <span class="tabular"><?= e(pluralise(engagement_counts('post', (int) $post['id'])['comments'], 'comment')) ?></span></a><?php endif ?>
        </div><?php endif ?>
        <?= view('site/share', ['url' => $share_url, 'text' => $post['title']]) ?>
        <?php if ((int) $post['allow_comments'] && !$is_preview): ?>
          <?= view('partials/comments', ['type' => 'post', 'id' => (int) $post['id'], 'viewer' => $viewer, 'return' => post_url($post), 'error' => $comment_error, 'thread' => $thread, 'count' => engagement_counts('post', (int) $post['id'])['comments']]) ?>
        <?php endif ?>
      <?php endif ?>
    </div>
  </div>
</article>
<?php if ($related): ?>
<section class="section soft" aria-labelledby="related-title">
  <div class="container">
    <div class="section-head"><div><span class="eyebrow">Keep reading</span><h2 id="related-title">More from <?= e($base_label) ?></h2></div><a class="link-arrow" href="/<?= e($post['base']) ?>">See all <?= icon('arrow-right') ?></a></div>
    <div class="grid cols-3" data-reveal-group><?php foreach (array_slice($related, 0, 3) as $r): ?><?= view('partials/post_card', ['post' => $r]) ?><?php endforeach ?></div>
  </div>
</section>
<?php endif ?>
