<?php /** @var array $s @var array $d @var string $h */ $leaders = $d['leaders']; ?>
<div class="container">
  <?= view('sections/_head', ['s' => $s, 'h' => $h]) ?>
  <?php if (!$leaders): ?>
    <?= view('partials/empty', ['message' => 'No leaders listed yet.']) ?>
  <?php elseif ($s['layout'] === 'list'): ?>
    <ul class="people-list">
      <?php foreach ($leaders as $l): ?><li><a href="/leaders/<?= e($l['group_slug']) ?>/<?= e($l['slug']) ?>"><strong><?= e($l['name']) ?></strong></a><span class="person-role"><?= e($l['role_title']) ?></span></li><?php endforeach ?>
    </ul>
  <?php else: ?>
    <div class="grid small">
      <?php foreach ($leaders as $l): $photo = media_find($l['photo_media_id'] ? (int) $l['photo_media_id'] : null); ?>
        <article class="card person">
          <div class="card-media"><?= $photo ? img_html($photo, '(min-width: 40rem) 14rem, 50vw', '', '', true, 400) : '<span class="avatar-fallback" aria-hidden="true">' . e(mb_substr($l['name'], 0, 1)) . '</span>' ?></div>
          <div class="card-body">
            <h3><a href="/leaders/<?= e($l['group_slug']) ?>/<?= e($l['slug']) ?>"><?= e($l['name']) ?></a></h3>
            <span class="person-role"><?= e($l['role_title']) ?></span>
          </div>
        </article>
      <?php endforeach ?>
    </div>
  <?php endif ?>
</div>
