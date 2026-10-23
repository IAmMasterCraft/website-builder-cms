<?php /** @var string $view @var string $label */ ?>
<?= view('partials/page_hero', ['heading' => 'Event calendar', 'intro' => 'Browse events by month or week. Times are shown in ' . site_tz()->getName() . '.', 'crumbs' => ['Events' => '/events', 'Calendar' => null]]) ?>
<section class="section">
  <div class="container">
    <div class="filter-bar"><?= view('site/events_tabs', ['active' => $view]) ?>
      <a class="chip" href="/events/calendar?view=list"<?= $view === 'list' ? ' aria-current="page"' : '' ?>>Agenda</a>
    </div>
    <div class="cal-toolbar">
      <h2 aria-live="polite"><?= e($label) ?></h2>
      <?php if ($prev !== null): ?>
      <div class="cal-nav">
        <a class="btn ghost small" href="<?= e($prev) ?>"><?= icon('chevron-left') ?> Previous</a>
        <a class="btn ghost small" href="<?= e($current) ?>">Today</a>
        <a class="btn ghost small" href="<?= e($next) ?>">Next <?= icon('chevron-right') ?></a>
      </div>
      <?php endif ?>
    </div>
    <?php if ($view === 'month'): ?>
      <?= view('partials/calendar_month', ['weeks' => $weeks, 'label' => $label]) ?>
    <?php elseif ($view === 'week'): ?>
      <ol class="week-list">
        <?php foreach ($days as $d): ?>
          <li class="week-day<?= $d['is_today'] ? ' today' : '' ?>">
            <div><strong><?= e($d['date']->format('l')) ?></strong><br><span class="muted"><?= e($d['date']->format('j M')) ?></span></div>
            <div>
              <?php if ($d['events']): foreach ($d['events'] as $ev): ?>
                <p><a href="<?= e(event_url($ev)) ?>"><strong><?= e($ev['title']) ?></strong></a><br><span class="muted"><?= e(fmt_date($ev['start_at'], 'H:i')) ?><?= $ev['venue_name'] ? ' · ' . e($ev['venue_name']) : '' ?></span></p>
              <?php endforeach; else: ?><span class="muted">No events</span><?php endif ?>
            </div>
          </li>
        <?php endforeach ?>
      </ol>
    <?php else: ?>
      <?php if ($events): ?><ul class="event-list"><?php foreach ($events as $event): ?><?= view('partials/event_row', ['event' => $event]) ?><?php endforeach ?></ul>
      <?php else: ?><?= view('partials/empty', ['message' => 'No upcoming events.']) ?><?php endif ?>
    <?php endif ?>
  </div>
</section>
