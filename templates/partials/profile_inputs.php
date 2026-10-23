<?php /** Standard member profile inputs. @var array $values @var array $errors @var array $settings @var bool $forAdmin */
$fv = ['values' => $values, 'errors' => $errors];
$months = [];
for ($i = 1; $i <= 12; $i++) {
    $months[(string) $i] = (new DateTimeImmutable("2000-$i-01"))->format('F');
}
$days = array_combine(array_map('strval', range(1, 31)), array_map('strval', range(1, 31)));
?>
<div class="form-row">
  <?= view('partials/ffield', ['name' => 'first_name', 'label' => 'First name', 'required' => true, 'auto' => 'given-name', 'extra' => ' maxlength="80"'] + $fv) ?>
  <?= view('partials/ffield', ['name' => 'last_name', 'label' => 'Last name', 'required' => true, 'auto' => 'family-name', 'extra' => ' maxlength="80"'] + $fv) ?>
</div>
<fieldset class="field birthday-field<?= isset($errors['birth_day']) || isset($errors['birth_year']) ? ' has-error' : '' ?>">
  <legend>Birthday</legend>
  <div class="birthday-row">
    <div class="field"><label for="j-birth_day">Day</label><select id="j-birth_day" name="birth_day" required autocomplete="bday-day"<?= isset($errors['birth_day']) ? ' aria-invalid="true" aria-describedby="j-birth_day-err"' : '' ?>><option value="">Day</option><?php foreach ($days as $dv => $dl): ?><option value="<?= $dv ?>"<?= (string) ($values['birth_day'] ?? '') === (string) $dv ? ' selected' : '' ?>><?= $dl ?></option><?php endforeach ?></select></div>
    <div class="field"><label for="j-birth_month">Month</label><select id="j-birth_month" name="birth_month" required autocomplete="bday-month"><option value="">Month</option><?php foreach ($months as $mv => $ml): ?><option value="<?= $mv ?>"<?= (string) ($values['birth_month'] ?? '') === (string) $mv ? ' selected' : '' ?>><?= e($ml) ?></option><?php endforeach ?></select></div>
    <div class="field"><label for="j-birth_year">Year <span class="muted">(optional)</span></label><input type="text" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" id="j-birth_year" name="birth_year" value="<?= e((string) ($values['birth_year'] ?? '')) ?>" autocomplete="bday-year"<?= isset($errors['birth_year']) ? ' aria-invalid="true" aria-describedby="j-birth_year-err"' : '' ?>></div>
  </div>
  <p class="hint">Your birth year is never shown publicly.<?= empty($forAdmin) ? ' We use your birthday to send you greetings (you can turn this off).' : '' ?></p>
  <?php if (isset($errors['birth_day'])): ?><p class="error" id="j-birth_day-err"><?= e($errors['birth_day']) ?></p><?php endif ?>
  <?php if (isset($errors['birth_year'])): ?><p class="error" id="j-birth_year-err"><?= e($errors['birth_year']) ?></p><?php endif ?>
</fieldset>
<div class="form-row">
  <?= view('partials/ffield', ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'auto' => 'tel', 'extra' => ' maxlength="40"'] + $fv) ?>
  <?= view('partials/ffield', ['name' => 'gender', 'label' => 'Gender', 'type' => 'select', 'options' => array_combine($settings['genders'], $settings['genders']) ?: []] + $fv) ?>
</div>
<?= view('partials/ffield', ['name' => 'address', 'label' => 'Address', 'auto' => 'street-address', 'extra' => ' maxlength="300"'] + $fv) ?>
<div class="form-row">
  <?= view('partials/ffield', ['name' => 'city', 'label' => 'Town or city', 'auto' => 'address-level2', 'extra' => ' maxlength="80"'] + $fv) ?>
  <?= view('partials/ffield', ['name' => 'region', 'label' => 'Region', 'type' => 'select', 'options' => array_combine($settings['regions'], $settings['regions']) ?: []] + $fv) ?>
</div>
<div class="form-row">
  <?= view('partials/ffield', ['name' => 'country', 'label' => 'Country of residence', 'required' => empty($forAdmin), 'auto' => 'country-name', 'extra' => ' maxlength="80"'] + $fv) ?>
  <?= view('partials/ffield', ['name' => 'nationality', 'label' => 'Nationality', 'extra' => ' maxlength="80"'] + $fv) ?>
</div>
