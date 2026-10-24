<?php /** @var array $s @var array $d @var string $h */ ?>
<div class="container">
  <?= view('sections/_head', ['s' => $s, 'h' => $h]) ?>
  <?php if (!$d['events']): ?>
    <?= view('partials/empty', ['message' => $s['empty_text'] !== '' ? $s['empty_text'] : t('list.empty')]) ?>
  <?php elseif ($s['layout'] === 'cards'): ?>
    <div class="grid cols-3" data-reveal-group><?php foreach ($d['events'] as $event): ?><?= view('partials/event_card', ['event' => $event]) ?><?php endforeach ?></div>
  <?php else: ?>
    <ul class="event-list<?= $s['layout'] === 'grid' ? ' cols-2' : '' ?>" data-reveal-group><?php foreach ($d['events'] as $event): ?><?= view('partials/event_row', ['event' => $event]) ?><?php endforeach ?></ul>
  <?php endif ?>
</div>
