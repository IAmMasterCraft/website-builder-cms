<?php /** @var array $values @var array $errors @var string $example @var ?array $background @var ?array $signature */
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
        <div class="field-row"><?= $f('regions', 'Regions (one per line)', 'textarea') ?><?= $f('genders', 'Gender options (one per line)', 'textarea', 'Gender is always optional.') ?></div>
        <?= $f('types', 'Membership types (one per line)', 'textarea', 'The first one is given to new members.') ?>
        <p class="hint">Extra questions (for example a district of origin) are managed under <a href="/admin/custom-fields">Custom fields</a>.</p>
      </div></section>
    </div>
    <div class="side">
      <section class="panel"><div class="panel-head"><h2>ID card artwork</h2></div><div class="panel-body fields">
        <?= $f('signatory', 'Signatory title', 'text', 'Printed under the signature, e.g. Secretary', ' maxlength="60"') ?>
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
