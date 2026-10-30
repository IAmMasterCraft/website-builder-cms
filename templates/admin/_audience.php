<?php /** @var array $audience @var array $choices @var ?string $error @var ?array $reach */
$a = $audience;
$fieldOpts = ['' => 'Choose a field…'];
foreach ($choices['fields'] as $f) { $fieldOpts[(string) $f['id']] = $f['label']; }
$eventOpts = ['' => 'Choose an event…'];
foreach ($choices['events'] as $ev) { $eventOpts[(string) $ev['id']] = $ev['title'] . ' (' . fmt_date($ev['start_at']) . ')'; }
$types = $choices['types'];
if ($a['type'] !== 'region') {
    unset($types['region']); // older audiences only; new ones use "location"
}
$loc = $a['type'] === 'location' ? geo_filter_decode($a['value']) : null;
$locValues = ['aud_loc_country' => $loc[1] ?? '', 'aud_loc_state' => $loc[2] ?? '', 'aud_loc_area' => $loc[3] ?? ''];
?>
<fieldset class="field audience" data-audience>
  <legend>Audience</legend>
  <?= field_html('audience_type', 'Send to', 'select', $a['type'], $error, ['options' => $types, 'prefix' => 'aud']) ?>
  <div class="audience-extra" data-audience-for="membership_type"><?= field_html('audience_membership_type', 'Membership type', 'select', $a['type'] === 'membership_type' ? $a['value'] : '', null, ['options' => array_combine($choices['membership_types'], $choices['membership_types']) ?: [], 'prefix' => 'aud']) ?></div>
  <div class="audience-extra" data-audience-for="region"><?= field_html('audience_region', 'Region', 'select', $a['type'] === 'region' ? $a['value'] : '', null, ['options' => array_combine($choices['regions'], $choices['regions']) ?: [], 'prefix' => 'aud']) ?></div>
  <div class="audience-extra" data-audience-for="location">
    <?= field_html('audience_loc_slot', 'Which location', 'select', $loc[0] ?? 'residence', null, ['options' => $choices['location_slots'], 'prefix' => 'aud']) ?>
    <?= view('partials/location_field', ['prefix' => 'aud_loc', 'cfg' => geo_config(['levels' => 3, 'label' => 'Place', 'country_label' => 'Country']), 'values' => $locValues, 'errors' => [], 'suggest' => null]) ?>
    <p class="hint">Pick a country, then optionally a region/state and a local area. Example: Origin → Nigeria → Ekiti.</p>
  </div>
  <div class="audience-extra field-row" data-audience-for="custom_field">
    <?= field_html('audience_field_id', 'Custom field', 'select', (string) $a['field_id'], null, ['options' => $fieldOpts, 'prefix' => 'aud']) ?>
    <?= field_html('audience_field_value', 'Equals', 'text', $a['type'] === 'custom_field' ? $a['value'] : '', null, ['max' => 200, 'prefix' => 'aud']) ?>
  </div>
  <div class="audience-extra" data-audience-for="event"><?= field_html('audience_event_id', 'Event', 'select', $a['type'] === 'event' ? $a['value'] : '', null, ['options' => $eventOpts, 'prefix' => 'aud']) ?></div>
  <?php if ($reach !== null): ?>
    <p class="reach" data-reach aria-live="polite"><?= icon('users') ?> <span><strong class="tabular" data-reach-count><?= count($reach['recipients']) ?></strong> <?= count($reach['recipients']) === 1 ? 'recipient' : 'recipients' ?><?php if ($reach['excluded'] > 0): ?> · <span class="muted"><?= (int) $reach['excluded'] ?> left out <?= !empty($essential) ? '(unsubscribed, bounced or complained)' : 'by their email preferences' ?></span><?php endif ?></span></p>
  <?php endif ?>
  <p class="hint"><?= !empty($essential) ? 'Essential notice: members receive it whatever their news email preference; anyone who unsubscribed, bounced or complained is still left out.' : 'Members only receive bulk email when “News and event updates” is on in their preferences; anyone who unsubscribed is always left out.' ?></p>
</fieldset>
