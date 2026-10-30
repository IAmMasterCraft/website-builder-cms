<?php /** @var array $values @var array $errors @var string $example @var ?array $background @var ?array $signature @var array $card_location_options */
$v = static fn (string $k): string => (string) ($values[$k] ?? '');
$f = static function (string $k, string $label, string $type = 'text', string $hint = '', string $extra = '') use ($v, $errors): string {
    $err = $errors[$k] ?? null;
    $desc = trim(($hint !== '' ? "ms-$k-hint " : '') . ($err ? "ms-$k-err" : ''));
    $control = $type === 'textarea'
        ? '<textarea id="ms-' . e($k) . '" name="' . e($k) . '" rows="5"' . ($desc ? ' aria-describedby="' . e($desc) . '"' : '') . ($err ? ' aria-invalid="true"' : '') . '>' . e($v($k)) . '</textarea>'
        : '<input type="' . e($type) . '" id="ms-' . e($k) . '" name="' . e($k) . '" value="' . e($v($k)) . '"' . $extra . ($desc ? ' aria-describedby="' . e($desc) . '"' : '') . ($err ? ' aria-invalid="true"' : '') . '>';
    return '<div class="field"><label for="ms-' . e($k) . '">' . e($label) . '</label>' . $control
        . ($hint !== '' ? '<p class="hint" id="ms-' . e($k) . '-hint">' . e($hint) . '</p>' : '') . ($err ? '<p class="error" id="ms-' . e($k) . '-err">' . e($err) . '</p>' : '') . '</div>';
};
?>
<div class="page-head"><div><h1>Membership settings</h1><p>Member numbers, card validity, sign-up options and ID card artwork.</p></div></div>
<?php if ($errors): ?><div class="flash error" role="alert"><?= icon('alert') ?><span>Please fix the highlighted fields.</span></div><?php endif ?>
<form method="post" action="/admin/settings/membership" enctype="multipart/form-data" novalidate>
  <?= csrf_field() ?>
  <div class="form-layout">
    <div>
      <section class="panel"><div class="panel-head"><h2>Member numbers</h2></div><div class="panel-body fields">
        <div class="field-row"><?= $f('prefix', 'Member prefix', 'text', 'e.g. ORG', ' maxlength="8"') ?><?= $f('group_prefix', 'Group prefix', 'text', 'e.g. GRP', ' maxlength="8"') ?></div>
        <?= $f('number_format', 'Number format', 'text', 'Tokens: {PREFIX}, {YY} or {YYYY} (year of verification) and {0000} (sequence, 2–8 digits). The sequence restarts each year when the format has a year.', ' maxlength="30" class="mono"') ?>
        <p class="hint">Example: <strong class="tabular"><?= e($example) ?></strong>. Numbers are assigned when a member verifies their email and are never reused, even after a member is removed.</p>
      </div></section>
      <section class="panel"><div class="panel-head"><h2>Sign-up form</h2></div><div class="panel-body fields">
        <div class="field-row"><?= $f('card_months', 'Membership valid for (months)', 'number', 'Sets “valid until” on new cards.', ' min="1" max="120"') ?><?= $f('free_first_n', 'Free for the first N members', 'number', '0 turns the offer off. Shown as a banner on /join.', ' min="0"') ?></div>
        <?= $f('terms_url', 'Terms and privacy page', 'text', 'Linked from the consent box, e.g. /privacy-policy') ?>
        <div class="field">
          <label class="check-line"><input type="checkbox" name="newsletter_default" value="1" aria-describedby="ms-newsletter-hint"<?= !empty($values['newsletter_default']) ? ' checked' : '' ?>> <span><strong>Tick the “news and event updates” box on the sign-up form by default</strong></span></label>
          <p class="hint" id="ms-newsletter-hint">Recommended: leave this off. Under UK GDPR and PECR a pre-ticked box is not valid consent for marketing emails; members can opt in later from their dashboard or Email preferences.</p>
        </div>
        <?= $f('genders', 'Gender options (one per line)', 'textarea', 'Gender is always optional.') ?>
        <?= $f('types', 'Membership types (one per line)', 'textarea', 'The first one is given to new members.') ?>
        <p class="hint">Country of residence, region/state and city, and nationality, use the built-in location lists (<a href="/admin/locations">Location lists</a>). Extra questions are managed under <a href="/admin/custom-fields">Custom fields</a> (the “Location” type gives the same cascading lists).</p>
      </div></section>
      <section class="panel" id="origin"><div class="panel-head"><h2>Origin on the sign-up form</h2></div><div class="panel-body fields">
        <div class="field">
          <label class="check-line"><input type="checkbox" name="origin_enabled" value="1" aria-describedby="ms-origin-hint"<?= $v('origin_enabled') === '1' ? ' checked' : '' ?>> <span><strong>Ask where members are from</strong> (Country → State → Local area)</span></label>
          <p class="hint" id="ms-origin-hint">For example “State of origin” and “LGA” for a Nigerian community. Members can edit it on their profile; admins can filter members and send campaigns by it.</p>
        </div>
        <?= $f('origin_legend', 'Heading of the section', 'text', 'e.g. Where you are from', ' maxlength="80"') ?>
        <div class="field-row">
          <div class="field"><label for="ms-origin_levels">Levels to ask</label><select id="ms-origin_levels" name="origin_levels"<?= isset($errors['origin_levels']) ? ' aria-invalid="true" aria-describedby="ms-origin_levels-err"' : '' ?>><?php foreach (['1' => 'Country only', '2' => 'Country and state/region', '3' => 'Country, state and local area'] as $k => $l): ?><option value="<?= $k ?>"<?= $v('origin_levels') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select><?php if (isset($errors['origin_levels'])): ?><p class="error" id="ms-origin_levels-err"><?= e($errors['origin_levels']) ?></p><?php endif ?></div>
          <div class="field"><label for="ms-origin_required">Required</label><select id="ms-origin_required" name="origin_required"<?= isset($errors['origin_required']) ? ' aria-invalid="true" aria-describedby="ms-origin_required-err"' : '' ?>><?php foreach (['0' => 'Optional', '1' => 'Country required', '2' => 'Country and state required', '3' => 'Every level required'] as $k => $l): ?><option value="<?= $k ?>"<?= $v('origin_required') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select><?php if (isset($errors['origin_required'])): ?><p class="error" id="ms-origin_required-err"><?= e($errors['origin_required']) ?></p><?php endif ?></div>
        </div>
        <div class="field"><label for="ms-origin_country">Country</label><select id="ms-origin_country" name="origin_country" aria-describedby="ms-origin_country-hint<?= isset($errors['origin_country']) ? ' ms-origin_country-err' : '' ?>"><option value="">Any country (members choose)</option><?php foreach (geo_countries() as $cc => $name): ?><option value="<?= e($cc) ?>"<?= $v('origin_country') === $cc ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach ?></select><p class="hint" id="ms-origin_country-hint">Fix one country to start the form at its states.</p><?php if (isset($errors['origin_country'])): ?><p class="error" id="ms-origin_country-err"><?= e($errors['origin_country']) ?></p><?php endif ?></div>
        <div class="field-row"><?= $f('origin_country_label', 'Country label', 'text', 'Default: Country of origin', ' maxlength="60"') ?><?= $f('origin_l1_label', 'State label', 'text', 'Empty = the country’s own word + “of origin”, e.g. State of origin', ' maxlength="60"') ?></div>
        <?= $f('origin_l2_label', 'Local area label', 'text', 'Empty = the country’s own word, e.g. Local Government Area. Short forms such as “LGA” are fine.', ' maxlength="60"') ?>
      </div></section>
    </div>
    <div class="side">
      <section class="panel"><div class="panel-head"><h2>ID card artwork</h2></div><div class="panel-body fields">
        <?= $f('signatory', 'Signatory title', 'text', 'Printed under the signature, e.g. Secretary', ' maxlength="60"') ?>
        <?php foreach (['card_location_1' => 'Location on the card (line 1)', 'card_location_2' => 'Location on the card (line 2)'] as $k => $label): ?>
          <div class="field"><label for="ms-<?= $k ?>"><?= e($label) ?></label><select id="ms-<?= $k ?>" name="<?= $k ?>"<?= isset($errors[$k]) ? ' aria-invalid="true"' : '' ?>><option value="">None</option><?php foreach ($card_location_options as $ok => $o): ?><option value="<?= e($ok) ?>"<?= $v($k) === $ok ? ' selected' : '' ?>><?= e($o[0]) ?></option><?php endforeach ?></select><?php if (isset($errors[$k])): ?><p class="error"><?= e($errors[$k]) ?></p><?php endif ?></div>
        <?php endforeach ?>
        <p class="hint">Two extra lines fit on the card: location lines come first, then custom fields marked “show on the card”.</p>
        <?php foreach (['background' => ['Card background', $background, 'A subtle pattern or photo, shown faintly behind the front of the card.'], 'signature' => ['Signature image', $signature, 'A PNG with a transparent background works best.']] as $k => [$label, $m, $hint]): ?>
          <div class="field media-field"><span class="label"><?= e($label) ?></span>
            <div class="media-preview file"><?= $m ? img_html($m, '12rem', '', '', true, 400) : '<span>None</span>' ?></div>
            <label for="ms-<?= $k ?>" class="hint"><?= e($hint) ?></label><input type="file" id="ms-<?= $k ?>" name="<?= $k ?>" accept="image/png,image/jpeg,image/webp">
            <?php if (isset($errors[$k])): ?><p class="error"><?= e($errors[$k]) ?></p><?php endif ?>
            <?php if ($m): ?><label class="check"><input type="checkbox" name="<?= $k ?>_remove" value="1"> Remove</label><?php endif ?>
          </div>
        <?php endforeach ?>
      </div></section>
      <div class="panel panel-body"><button class="btn accent" type="submit"><?= icon('check') ?> Save settings</button></div>
    </div>
  </div>
</form>
