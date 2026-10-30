<?php
/**
 * Cascading location field (C4): Country -> state/region -> local area (+ city).
 * Works without JavaScript: the server renders the lists for the chosen
 * country/state and "Update the lists" re-shows the form; location.js loads
 * the lists instantly, filters long ones and suggests a country.
 * @var string $prefix input name prefix (res, nat, origin, cf_<key>)
 * @var array $cfg geo_config() result
 * @var array $values @var array $errors
 * @var ?string $suggest country placed under "Suggested" (CDN header), or null
 */
$p = $prefix;
$id = 'j-' . preg_replace('/[^a-z0-9_-]/i', '-', $p);
$val = static fn (string $k): string => is_scalar($values[$p . '_' . $k] ?? null) ? trim((string) $values[$p . '_' . $k]) : '';
$cc = $cfg['country'] !== '' ? $cfg['country'] : strtoupper($val('country'));
$cc = geo_country_exists($cc) ? $cc : '';
$a1 = $val('state');
$a1Options = $cc !== '' && $cfg['levels'] >= 2 ? geo_admin1_options($cc) : [];
$a1Valid = isset($a1Options[$a1]);
$a2Options = $a1Valid && $cfg['levels'] >= 3 ? geo_admin2_options($cc, $a1) : [];
// A level is shown once the level above it is known (or typed as text).
$showL1 = $cc !== '' && $cfg['levels'] >= 2;
$showL2 = $showL1 && $cfg['levels'] >= 3 && ($a1Valid || ($a1Options === [] && $val('state_text') !== ''));
$required = static fn (int $level): bool => $cfg['required'] >= $level;
$err = static fn (string $k): ?string => $errors[$p . '_' . $k] ?? null;
$described = static function (string $k, string $fid) use ($err): string {
    $e = $err($k);
    return $e !== null ? ' aria-invalid="true" aria-describedby="' . e($fid) . '-err"' : '';
};
$errLine = static fn (string $k, string $fid): string => ($e = $err($k)) !== null ? '<p class="error" id="' . e($fid) . '-err">' . e($e) . '</p>' : '';
$opt = static fn (bool $req): string => $req ? '' : ' <span class="muted">(optional)</span>';
$countries = geo_countries();
?>
<fieldset class="field location-field" data-loc data-loc-prefix="<?= e($p) ?>" data-levels="<?= (int) $cfg['levels'] ?>"<?= $cfg['country'] !== '' ? ' data-fixed="' . e($cfg['country']) . '"' : '' ?> data-l1-label="<?= e($cfg['l1_label']) ?>" data-l2-label="<?= e($cfg['l2_label']) ?>" data-l1-suffix="<?= e($cfg['l1_suffix']) ?>"<?= !empty($cfg['hint']) ? ' data-loc-hint' : '' ?><?= geo_form_attrs() ?>>
  <legend<?= $cfg['levels'] === 1 && !$cfg['city'] && $cfg['label'] === $cfg['country_label'] ? ' class="sr-only"' : '' ?>><?= e($cfg['label']) ?></legend>
  <div class="loc-grid">
    <?php if ($cfg['country'] === ''): ?>
    <div class="field">
      <label for="<?= e($id) ?>-country"><?= e($cfg['country_label']) ?><?= $opt($required(1)) ?></label>
      <select id="<?= e($id) ?>-country" name="<?= e($p) ?>_country" data-loc-country autocomplete="<?= $p === 'res' ? 'country' : 'off' ?>"<?= $required(1) ? ' required' : '' ?><?= $described('country', $id . '-country') ?>>
        <option value=""><?= $required(1) ? 'Choose…' : '—' ?></option>
        <optgroup label="Suggested" data-loc-suggested<?= $suggest === null ? ' hidden disabled' : '' ?>><?php if ($suggest !== null): ?><option value="<?= e($suggest) ?>"><?= e($countries[$suggest] ?? $suggest) ?></option><?php endif ?></optgroup>
        <optgroup label="All countries"><?php foreach ($countries as $code => $name): ?><option value="<?= e($code) ?>"<?= $code === $cc ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach ?></optgroup>
      </select>
      <?= $errLine('country', $id . '-country') ?>
    </div>
    <?php endif ?>
    <?php if ($cfg['levels'] >= 2): $label1 = geo_level_label($cfg, 1, $cc ?: null); $useSelect = $a1Options !== []; ?>
    <div class="field" data-loc-level="1"<?= $showL1 ? '' : ' hidden' ?>>
      <label for="<?= e($id) ?>-state<?= $useSelect ? '' : '-text' ?>" data-loc-label><span data-loc-label-text><?= e($label1) ?></span><?= $opt($required(2)) ?></label>
      <select id="<?= e($id) ?>-state" name="<?= e($p) ?>_state" data-loc-select<?= $useSelect ? '' : ' hidden disabled' ?><?= $described('state', $id . '-state') ?>>
        <option value="">—</option>
        <?php foreach ($a1Options as $code => $name): ?><option value="<?= e((string) $code) ?>"<?= (string) $code === $a1 ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach ?>
      </select>
      <input type="text" id="<?= e($id) ?>-state-text" name="<?= e($p) ?>_state_text" value="<?= e($val('state_text')) ?>" maxlength="120" data-loc-text<?= $useSelect ? ' hidden disabled' : '' ?><?= $described('state', $id . '-state') ?>>
      <?= $errLine('state', $id . '-state') ?>
    </div>
    <?php endif ?>
    <?php if ($cfg['levels'] >= 3): $label2 = geo_level_label($cfg, 2, $cc ?: null); $useSelect2 = $a2Options !== []; ?>
    <div class="field" data-loc-level="2"<?= $showL2 ? '' : ' hidden' ?>>
      <label for="<?= e($id) ?>-area<?= $useSelect2 ? '' : '-text' ?>" data-loc-label><span data-loc-label-text><?= e($label2) ?></span><?= $opt($required(3)) ?></label>
      <select id="<?= e($id) ?>-area" name="<?= e($p) ?>_area" data-loc-select<?= $useSelect2 ? '' : ' hidden disabled' ?><?= $described('area', $id . '-area') ?>>
        <option value="">—</option>
        <?php foreach ($a2Options as $code => $name): ?><option value="<?= e((string) $code) ?>"<?= (string) $code === $val('area') ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach ?>
      </select>
      <input type="text" id="<?= e($id) ?>-area-text" name="<?= e($p) ?>_area_text" value="<?= e($val('area_text')) ?>" maxlength="120" data-loc-text<?= $useSelect2 ? ' hidden disabled' : '' ?><?= $described('area', $id . '-area') ?>>
      <?= $errLine('area', $id . '-area') ?>
    </div>
    <?php endif ?>
    <?php if ($cfg['city']): ?>
    <div class="field">
      <label for="<?= e($id) ?>-city"><?= e($cfg['city_label']) ?> <span class="muted">(optional)</span></label>
      <input type="text" id="<?= e($id) ?>-city" name="<?= e($p) ?>_city" value="<?= e($val('city')) ?>" maxlength="80" autocomplete="<?= $p === 'res' ? 'address-level2' : 'off' ?>"<?= $described('city', $id . '-city') ?>>
      <?= $errLine('city', $id . '-city') ?>
    </div>
    <?php endif ?>
  </div>
  <p class="hint loc-guess" data-loc-guess role="status"></p>
  <?php if ($cfg['levels'] >= 2): ?>
  <p class="loc-refresh" data-loc-refresh><button class="btn small ghost" type="submit" name="_loc_refresh" value="<?= e($p) ?>" formnovalidate><?= icon('history') ?> Update the lists</button> <span class="hint">After choosing <?= $cfg['country'] === '' ? 'a country' : 'a ' . e(lcfirst_label($label1 ?? 'region')) ?>, press this to see the next list.</span></p>
  <?php endif ?>
</fieldset>
<script type="module" src="<?= e(asset('assets/js/location.js')) ?>"></script>
