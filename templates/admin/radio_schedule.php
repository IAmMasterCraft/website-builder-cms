<?php
/** @var array $days @var DateTimeImmutable $monday @var array $slots @var array $values @var array $errors @var array $sources @var ?int $loop @var bool $enabled @var array $now @var DateTimeZone $tz */
$prev = $monday->modify('-7 days')->format('Y-m-d');
$next = $monday->modify('+7 days')->format('Y-m-d');
$today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
$nowMin = (int) (new DateTimeImmutable('now', $tz))->format('G') * 60 + (int) (new DateTimeImmutable('now', $tz))->format('i');
$loopName = $loop ? (string) q_val('SELECT name FROM radio_playlists WHERE id = ?', [$loop]) : '';
$formValues = $values + ['start' => (new DateTimeImmutable('now', $tz))->modify('+1 hour')->format('Y-m-d\TH:00'), 'repeat_rule' => 'none'];
?>
<div class="page-head">
  <div><h1>Radio schedule</h1>
    <p><span class="badge <?= $enabled ? 'ok' : '' ?>"><?= $enabled ? 'Station on' : 'Station off' ?></span>
      <?= $loop ? 'Between shows the fallback loop “' . e($loopName) . '” plays.' : 'No fallback loop: the station is off air between shows.' ?>
      Times in <?= e($tz->getName()) ?>.</p></div>
  <div class="actions"><a class="btn ghost" href="/admin/radio"><?= icon('settings') ?> Station and library</a><a class="btn accent" href="#slot-form"><?= icon('plus') ?> Add a slot</a></div>
</div>
<?= form_errors_html($errors) ?>
<?php if ($enabled): ?>
  <p class="now-strip"><span class="rp-dot<?= $now['status'] === 'offline' ? ' off' : '' ?>" aria-hidden="true"></span>
    <?php if ($now['media']): ?>Now: <strong><?= e($now['media']['title']) ?></strong> (<?= $now['status'] === 'loop' ? 'loop' : 'scheduled' ?>, <?= e(radio_fmt_duration((float) $now['remaining'])) ?> left)<?php else: ?>Off air<?php endif ?>
    <?php if ($now['next']): ?> · Next: <strong><?= e($now['next']['title']) ?></strong> in <?= e(radio_fmt_duration((float) $now['next']['starts_in'])) ?><?php endif ?></p>
<?php endif ?>

<section class="panel schedule-panel">
  <div class="panel-head">
    <h2>Week of <?= e($monday->format('j F Y')) ?></h2>
    <div class="btn-row">
      <a class="btn small ghost" href="/admin/radio/schedule?week=<?= e($prev) ?>"><?= icon('chevron-left') ?><span class="sr-only">Previous week</span></a>
      <a class="btn small ghost" href="/admin/radio/schedule">This week</a>
      <a class="btn small ghost" href="/admin/radio/schedule?week=<?= e($next) ?>"><?= icon('chevron-right') ?><span class="sr-only">Next week</span></a>
    </div>
  </div>
  <div class="week-cal" data-week-cal>
    <div class="week-hours" aria-hidden="true"><?php for ($h = 0; $h < 24; $h += 2): ?><span><?= sprintf('%02d:00', $h) ?></span><?php endfor ?></div>
    <?php foreach ($days as $ymd => $d): ?>
      <div class="week-col<?= $ymd === $today ? ' is-today' : '' ?>">
        <h3 class="week-col-head"><span><?= e($d['date']->format('D')) ?></span> <strong class="tabular"><?= e($d['date']->format('j')) ?></strong></h3>
        <ul class="week-col-body"<?= $ymd === $today ? ' data-now-min="' . $nowMin . '"' : '' ?>>
          <?php if (!$d['items']): ?><li class="week-empty muted"><?= $loop ? 'Loop all day' : 'Off air' ?></li><?php endif ?>
          <?php foreach ($d['items'] as $it): $s = (new DateTimeImmutable('@' . $it['start']))->setTimezone($tz); $e2 = (new DateTimeImmutable('@' . $it['end']))->setTimezone($tz); ?>
            <li class="week-item<?= $it['slot']['playlist_id'] ? ' is-playlist' : '' ?>" data-start-min="<?= (int) $it['start_min'] ?>" data-len-min="<?= (int) $it['len_min'] ?>">
              <a href="/admin/radio/schedule/<?= (int) $it['slot']['id'] ?>">
                <span class="week-time tabular"><?= e($s->format('H:i')) ?>–<?= e($it['end'] === $e2->setTime(0, 0)->getTimestamp() ? '24:00' : $e2->format('H:i')) ?></span>
                <span class="week-title"><?= e($it['label']) ?><?= $it['continued'] ? ' (cont.)' : '' ?></span>
                <?php if ($it['slot']['repeat_rule'] !== 'none'): ?><span class="sr-only">, repeats: <?= e(radio_repeat_label($it['slot'])) ?></span><?php endif ?>
              </a>
            </li>
          <?php endforeach ?>
        </ul>
      </div>
    <?php endforeach ?>
  </div>
</section>

<div class="form-layout">
  <section class="panel">
    <div class="panel-head"><h2>All slots</h2></div>
    <div class="table-wrap"><table class="data">
      <thead><tr><th scope="col">Show</th><th scope="col">First broadcast</th><th scope="col">Repeat</th><th scope="col">Length</th></tr></thead>
      <tbody>
      <?php if (!$slots): ?><tr><td colspan="4" class="empty-row">Nothing scheduled yet.</td></tr><?php endif ?>
      <?php foreach ($slots as $s): ?>
        <tr><td class="title-cell"><a href="/admin/radio/schedule/<?= (int) $s['id'] ?>"><?= e(radio_slot_label($s)) ?></a><?= $s['playlist_id'] ? ' <span class="badge members">playlist</span>' : '' ?></td>
          <td class="tabular"><?= e(fmt_datetime($s['start_at'])) ?></td>
          <td><?= e(radio_repeat_label($s)) ?></td>
          <td class="tabular"><?= e(radio_fmt_duration((float) $s['duration'])) ?></td></tr>
      <?php endforeach ?>
      </tbody></table></div>
  </section>
  <form class="panel side" id="slot-form" method="post" action="/admin/radio/schedule" novalidate>
    <?= csrf_field() ?><input type="hidden" name="week" value="<?= e($monday->format('Y-m-d')) ?>">
    <div class="panel-head"><h2>Add a slot</h2></div>
    <div class="panel-body fields">
      <?= view('admin/_slot_fields', ['values' => $formValues, 'errors' => $errors, 'sources' => $sources, 'prefix' => 'ns']) ?>
      <p class="hint muted">Slots cannot overlap. Programmes with an unknown length cannot be scheduled.</p>
      <button class="btn accent" type="submit"><?= icon('plus') ?> Add to schedule</button>
    </div>
  </form>
</div>
<script type="module" src="<?= e(asset('assets/js/radio-admin.js')) ?>"></script>
