<?php /** @var array $s @var array $d @var string $h */ ?>
<div class="container">
  <?= view('sections/_head', ['s' => $s, 'h' => $h]) ?>
  <?php if ($d['events']): ?>
    <ul class="event-list<?= $s['layout'] === 'grid' ? ' cols-2' : '' ?>"><?php foreach ($d['events'] as $event): ?><?= view('partials/event_row', ['event' => $event]) ?><?php endforeach ?></ul>
  <?php else: ?>
    <?= view('partials/empty', ['message' => $s['empty_text'] !== '' ? $s['empty_text'] : t('list.empty')]) ?>
  <?php endif ?>
</div>
