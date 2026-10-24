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
    <div class="grid cols-4 people" data-reveal-group>
      <?php foreach ($leaders as $l): ?><?= view('partials/person_card', ['leader' => $l, 'groupSlug' => $l['group_slug'], 'headingTag' => $h === 'h1' ? 'h2' : 'h3']) ?><?php endforeach ?>
    </div>
  <?php endif ?>
</div>
