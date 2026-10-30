<?php /** @var array $values @var array $errors @var ?array $row @var array $types */
$v = static fn (string $k): string => (string) ($values[$k] ?? '');
$err = static fn (string $k): string => isset($errors[$k]) ? '<p class="error" id="cf-' . $k . '-err">' . e($errors[$k]) . '</p>' : '';
?>
<div class="page-head"><div><p class="crumbs"><a href="/admin/custom-fields">Custom fields</a> /</p><h1><?= $row ? e((string) $row['label']) : 'New field' ?></h1></div></div>
<div class="form-layout">
  <form class="panel" method="post" action="/admin/custom-fields<?= $row ? '/' . (int) $row['id'] : '' ?>" novalidate>
    <?= csrf_field() ?>
    <div class="panel-body fields">
      <div class="field"><label for="cf-label">Label</label><input type="text" id="cf-label" name="label" value="<?= e($v('label')) ?>" maxlength="120" required<?= isset($errors['label']) ? ' aria-invalid="true" aria-describedby="cf-label-err"' : '' ?>><?= $err('label') ?></div>
      <div class="field-row">
        <div class="field"><label for="cf-type">Type</label><select id="cf-type" name="type"><?php foreach ($types as $tk => $tl): ?><option value="<?= e($tk) ?>"<?= $v('type') === $tk ? ' selected' : '' ?>><?= e($tl) ?></option><?php endforeach ?></select><?= $err('type') ?></div>
        <div class="field"><label for="cf-applies">Asked of</label><select id="cf-applies" name="applies_to"><option value="member"<?= $v('applies_to') !== 'group' ? ' selected' : '' ?>>Members</option><option value="group"<?= $v('applies_to') === 'group' ? ' selected' : '' ?>>Group accounts</option></select><?= $err('applies_to') ?></div>
      </div>
      <div class="field"><label for="cf-options">Choices (for “Choice from a list”)</label><textarea id="cf-options" name="options" rows="5" aria-describedby="cf-options-hint<?= isset($errors['options']) ? ' cf-options-err' : '' ?>"><?= e($v('options')) ?></textarea><p class="hint" id="cf-options-hint">One choice per line.</p><?= $err('options') ?></div>
      <fieldset class="field fieldset-plain" aria-describedby="cf-loc-hint">
        <legend>Location settings (for “Location”)</legend>
        <p class="hint" id="cf-loc-hint">Members pick from the bundled lists: country, then state/region, then local area. Changing a text or choice field to Location converts the saved answers (names that match the lists get their codes; the rest are kept as typed).</p>
        <div class="field-row">
          <div class="field"><label for="cf-loc-levels">Levels</label><select id="cf-loc-levels" name="loc_levels"<?= isset($errors['loc_levels']) ? ' aria-invalid="true" aria-describedby="cf-loc_levels-err"' : '' ?>><?php foreach (['1' => 'Country only', '2' => 'Country and state/region', '3' => 'Country, state and local area'] as $k => $l): ?><option value="<?= $k ?>"<?= $v('loc_levels') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select><?= $err('loc_levels') ?></div>
          <div class="field"><label for="cf-loc-country">Country</label><select id="cf-loc-country" name="loc_country"<?= isset($errors['loc_country']) ? ' aria-invalid="true" aria-describedby="cf-loc_country-err"' : '' ?>><option value="">Any country (members choose)</option><?php foreach (geo_countries() as $cc => $name): ?><option value="<?= e($cc) ?>"<?= $v('loc_country') === $cc ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach ?></select><?= $err('loc_country') ?></div>
        </div>
        <div class="field-row">
          <div class="field"><label for="cf-loc-country-label">Country label</label><input type="text" id="cf-loc-country-label" name="loc_country_label" value="<?= e($v('loc_country_label')) ?>" maxlength="60" placeholder="Country"><?= $err('loc_country_label') ?></div>
          <div class="field"><label for="cf-loc-l1">State label</label><input type="text" id="cf-loc-l1" name="loc_l1_label" value="<?= e($v('loc_l1_label')) ?>" maxlength="60" placeholder="Automatic (State, Region, Nation…)"><?= $err('loc_l1_label') ?></div>
          <div class="field"><label for="cf-loc-l2">Local area label</label><input type="text" id="cf-loc-l2" name="loc_l2_label" value="<?= e($v('loc_l2_label')) ?>" maxlength="60" placeholder="Automatic (LGA, County, Area…)"><?= $err('loc_l2_label') ?></div>
        </div>
        <div class="field"><label for="cf-loc-card">On the member card, show</label><select id="cf-loc-card" name="loc_card_level"><?php foreach (\Admin\CustomFields::CARD_LEVELS as $k => $l): ?><option value="<?= e($k) ?>"<?= $v('loc_card_level') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select></div>
      </fieldset>
      <label class="check"><input type="checkbox" name="is_required" value="1"<?= $v('is_required') === '1' ? ' checked' : '' ?>> Required</label>
      <label class="check"><input type="checkbox" name="visible_to_members" value="1"<?= $v('visible_to_members') === '1' ? ' checked' : '' ?>> Visible to members (asked at sign-up and editable on their profile; otherwise admins only)</label>
      <label class="check"><input type="checkbox" name="show_on_card" value="1"<?= $v('show_on_card') === '1' ? ' checked' : '' ?>> Show on the member card (up to two fields fit)</label>
      <div class="field"><label for="cf-sort">Order</label><input type="number" id="cf-sort" name="sort" value="<?= e($v('sort')) ?>" min="-999" max="999"></div>
      <div class="form-actions"><button class="btn accent" type="submit"><?= icon('check') ?> <?= $row ? 'Save field' : 'Add field' ?></button><a class="btn ghost" href="/admin/custom-fields">Cancel</a></div>
    </div>
  </form>
  <?php if ($row): ?>
  <form class="panel panel-body" method="post" action="/admin/custom-fields/<?= (int) $row['id'] ?>/delete" data-confirm="Delete this field and every saved answer?">
    <?= csrf_field() ?><h2>Delete</h2><p class="muted">Removes the field and all answers members gave.</p>
    <button class="btn danger-ghost small" type="submit"><?= icon('trash') ?> Delete field</button>
  </form>
  <?php endif ?>
</div>
