<?php /** @var array $weeks @var string $label */ ?>
<table class="calendar">
  <caption class="sr-only">Events in <?= e($label) ?></caption>
  <thead><tr><?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dn): ?><th scope="col"><?= $dn ?></th><?php endforeach ?></tr></thead>
  <tbody>
  <?php foreach ($weeks as $week): ?>
    <tr>
    <?php foreach ($week as $day):
        $cls = trim(($day['in_month'] ? '' : 'other') . ($day['is_today'] ? ' today' : '') . ($day['in_month'] && !$day['events'] ? ' empty' : '')); ?>
      <td<?= $cls !== '' ? ' class="' . e($cls) . '"' : '' ?>>
        <span class="day-num"><?= (int) $day['day'] ?></span><span class="day-label"><?= e((new DateTimeImmutable($day['date']))->format('l j F')) ?></span>
        <?php foreach ($day['events'] as $ev): ?>
          <a class="cal-event" href="<?= e(event_url($ev)) ?>"><?= e(fmt_date($ev['start_at'], 'H:i')) ?> <?= e($ev['title']) ?></a>
        <?php endforeach ?>
      </td>
    <?php endforeach ?>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
