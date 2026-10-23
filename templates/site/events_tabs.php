<?php /** @var string $active */ ?>
<nav class="filters" aria-label="Event views">
  <a class="chip" href="/events"<?= $active === 'list' ? ' aria-current="page"' : '' ?>><?= icon('menu') ?> List</a>
  <a class="chip" href="/events/calendar"<?= $active === 'month' ? ' aria-current="page"' : '' ?>><?= icon('calendar') ?> Month</a>
  <a class="chip" href="/events/calendar?view=week"<?= $active === 'week' ? ' aria-current="page"' : '' ?>><?= icon('grid') ?> Week</a>
  <a class="chip" href="/events.ics"><?= icon('download') ?> Subscribe (.ics)</a>
</nav>
