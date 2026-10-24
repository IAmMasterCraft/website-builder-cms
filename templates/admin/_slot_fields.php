<?php /** @var array $values @var array $errors @var array $sources @var string $prefix */
$err = static fn (string $k): ?string => $errors[$k] ?? null;
$v = static fn (string $k, string $d = ''): string => is_string($values[$k] ?? null) ? $values[$k] : $d;
$days = array_map('strval', is_array($values['days'] ?? null) ? $values['days'] : []);
$rule = $v('repeat_rule', 'none');
$src = $v('source');
?>
<?= field_html('title', 'Show name (optional)', 'text', $v('title'), $err('title'), ['max' => 160, 'prefix' => $prefix, 'hint' => 'Shown in “up next”. Empty: the programme or playlist name.']) ?>
<div class="field"><label for="<?= e($prefix) ?>-source">Plays <span class="req" aria-hidden="true">*</span></label>
  <select id="<?= e($prefix) ?>-source" name="source"<?= $err('source') ? ' aria-invalid="true" aria-describedby="' . e($prefix) . '-source-err"' : '' ?>>
    <option value="">Choose a programme or playlist…</option>
    <optgroup label="Playlists">
      <?php foreach ($sources['playlists'] as $pl): $ok = $pl['duration'] !== null; ?><option value="playlist:<?= (int) $pl['id'] ?>"<?= $src === 'playlist:' . $pl['id'] ? ' selected' : '' ?><?= $ok ? '' : ' disabled' ?>><?= e($pl['name']) ?> (<?= $ok ? e(radio_fmt_duration($pl['duration'])) : 'empty or unknown length' ?>)</option><?php endforeach ?>
    </optgroup>
    <optgroup label="Programmes">
      <?php foreach ($sources['media'] as $m): $ok = $m['duration'] !== null; ?><option value="media:<?= (int) $m['id'] ?>"<?= $src === 'media:' . $m['id'] ? ' selected' : '' ?><?= $ok ? '' : ' disabled' ?>><?= e($m['title']) ?> (<?= $ok ? e(radio_fmt_duration((float) $m['duration'])) : 'unknown length: cannot be scheduled' ?>)</option><?php endforeach ?>
    </optgroup>
  </select>
  <?php if ($err('source')): ?><p class="error" id="<?= e($prefix) ?>-source-err"><?= e($err('source')) ?></p><?php endif ?>
</div>
<?= field_html('start', 'Starts (' . site_tz()->getName() . ')', 'datetime-local', $v('start'), $err('start'), ['prefix' => $prefix, 'required' => true]) ?>
<fieldset class="field" data-repeat><legend>Repeat</legend>
  <div class="segmented small">
    <?php foreach (['none' => 'Once', 'daily' => 'Every day', 'weekly' => 'Weekly'] as $k => $l): ?><label><input type="radio" name="repeat_rule" value="<?= $k ?>"<?= $rule === $k ? ' checked' : '' ?>> <?= e($l) ?></label><?php endforeach ?>
  </div>
  <div class="day-picks" data-repeat-days>
    <span class="hint">On (weekly):</span>
    <?php foreach (RADIO_DAY_NAMES as $n => $name): ?><label class="day-pick"><input type="checkbox" name="days[]" value="<?= $n ?>"<?= in_array((string) $n, $days, true) ? ' checked' : '' ?>> <?= e($name) ?></label><?php endforeach ?>
  </div>
  <?php if ($err('days')): ?><p class="error"><?= e($err('days')) ?></p><?php endif ?>
  <div data-repeat-until><?= field_html('repeat_until', 'Repeat until (optional)', 'date', $v('repeat_until'), $err('repeat_until'), ['prefix' => $prefix]) ?></div>
</fieldset>
