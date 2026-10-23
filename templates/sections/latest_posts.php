<?php /** @var array $s @var array $d @var string $h */ $posts = $d['posts']; ?>
<div class="container">
  <?= view('sections/_head', ['s' => $s, 'h' => $h]) ?>
  <?php if (!$posts): ?>
    <?= view('partials/empty', ['message' => 'No posts yet. Check back soon.']) ?>
  <?php elseif ($s['layout'] === 'list'): ?>
    <ul class="post-list">
      <?php foreach ($posts as $p): ?>
        <li><time class="muted tabular" datetime="<?= e(iso_date($p['publish_at'])) ?>"><?= e(fmt_date($p['publish_at'])) ?></time>
          <h3 class="h3"><a href="<?= e(post_url($p)) ?>"><?= e($p['title']) ?></a></h3>
          <p><?= e(excerpt($p['summary'] ?: $p['body'], 160)) ?></p></li>
      <?php endforeach ?>
    </ul>
  <?php elseif ($s['layout'] === 'carousel'): ?>
    <div class="slider carousel" data-slider>
      <ul class="slider-track" tabindex="0" aria-label="Latest posts, swipe or scroll sideways">
        <?php foreach ($posts as $p): ?><li class="slide"><?= view('partials/post_card', ['post' => $p]) ?></li><?php endforeach ?>
      </ul>
      <div class="slider-controls" hidden>
        <button type="button" class="slider-btn" data-dir="-1"><?= icon('chevron-left') ?><span class="sr-only">Previous posts</span></button>
        <span class="slider-count tabular" aria-live="polite"></span>
        <button type="button" class="slider-btn" data-dir="1"><?= icon('chevron-right') ?><span class="sr-only">Next posts</span></button>
      </div>
    </div>
  <?php else: ?>
    <div class="grid"><?php foreach ($posts as $p): ?><?= view('partials/post_card', ['post' => $p]) ?><?php endforeach ?></div>
  <?php endif ?>
</div>
