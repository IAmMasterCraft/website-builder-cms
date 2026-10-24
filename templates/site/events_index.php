<?= view('partials/page_hero', ['heading' => 'Events', 'intro' => 'Gatherings, workshops and celebrations. Everyone is welcome unless an event says otherwise.', 'crumbs' => ['Events' => null]]) ?>
<section class="section">
  <div class="container">
    <div class="filter-bar">
      <?= view('partials/filters', ['label' => 'Show', 'param' => 'when', 'options' => ['today' => 'Today', 'past' => 'Past'], 'current' => $when === 'upcoming' ? '' : $when, 'path' => '/events', 'query' => $query]) ?>
      <?= view('site/events_tabs', ['active' => 'list']) ?>
    </div>
    <h2 class="sr-only"><?= e(\Site\Events::WHEN[$when]) ?> events</h2>
    <p class="muted tabular" role="status"><?= e(pluralise($total, \Site\Events::WHEN[$when] === 'Today' ? 'event today' : strtolower(\Site\Events::WHEN[$when]) . ' event', \Site\Events::WHEN[$when] === 'Today' ? 'events today' : strtolower(\Site\Events::WHEN[$when]) . ' events')) ?></p>
    <?php if ($rows): ?>
      <div class="grid cols-3" data-reveal-group><?php foreach ($rows as $event): ?><?= view('partials/event_card', ['event' => $event, 'headingTag' => 'h3']) ?><?php endforeach ?></div>
    <?php else: ?>
      <?= view('partials/empty', ['message' => $when === 'past' ? 'No past events yet.' : 'Nothing scheduled here yet. Check back soon or subscribe to the calendar.']) ?>
    <?php endif ?>
    <?= view('partials/pagination', ['total' => $total, 'per' => $per, 'page' => $page, 'path' => '/events', 'query' => $query]) ?>
  </div>
</section>
