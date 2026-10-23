<?php
/** @var array $themes @var string $theme @var array $values @var array $errors @var array $fonts @var array $radii @var array $buttons @var array $report @var array $config */
$err = static fn (string $k): string => isset($errors[$k]) ? '<p class="error" id="d-' . e($k) . '-err">' . e($errors[$k]) . '</p>' : '';
$aria = static fn (string $k): string => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="d-' . e($k) . '-err"' : '';
?>
<div class="page-head">
  <div><h1>Design</h1><p>Pick a theme, then fine-tune colours, fonts and shapes. The preview updates as you change things; nothing changes for visitors until you save.</p></div>
</div>
<?php if ($errors): ?><div class="flash error" role="alert"><?= icon('alert') ?><span>Please fix the highlighted fields.</span></div><?php endif ?>
<form method="post" action="/admin/design" class="design-layout" data-design>
  <?= csrf_field() ?>
  <script type="application/json" id="design-config"><?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
  <div class="design-controls">
    <section class="panel"><div class="panel-head"><h2>Theme</h2></div><div class="panel-body">
      <fieldset class="theme-grid"><legend class="sr-only">Theme</legend>
        <?php foreach ($themes as $key => $t): ?>
          <label class="theme-card">
            <input type="radio" name="theme" value="<?= e($key) ?>"<?= $key === $theme ? ' checked' : '' ?>>
            <span class="theme-thumb">
              <?php if ($t['has_preview']): ?><img src="/theme-preview/<?= e($key) ?>.png" alt="" width="320" height="200" loading="lazy">
              <?php else: ?><svg viewBox="0 0 4 1" preserveAspectRatio="none" aria-hidden="true" focusable="false"><?php foreach (array_slice($t['swatches'], 0, 4) as $i => $sw): ?><rect x="<?= $i ?>" y="0" width="1" height="1" fill="<?= e(hex_valid($sw) ? $sw : '#cccccc') ?>"/><?php endforeach ?></svg><?php endif ?>
            </span>
            <span class="theme-name"><?= e($t['name']) ?></span>
            <span class="muted theme-desc"><?= e($t['description']) ?></span>
          </label>
        <?php endforeach ?>
      </fieldset>
      <?= $err('theme') ?>
    </div></section>

    <section class="panel"><div class="panel-head"><h2>Colours</h2></div><div class="panel-body fields">
      <?php foreach (['brand' => ['Brand colour', 'Header bands, buttons, footer.'], 'accent' => ['Accent colour', 'Links, highlights and secondary buttons.']] as $k => [$label, $hint]): ?>
        <div class="field">
          <label for="d-<?= $k ?>"><?= e($label) ?></label>
          <div class="color-field">
            <input type="color" data-color-for="d-<?= $k ?>" value="<?= e($values[$k]) ?>" aria-label="<?= e($label) ?> picker" hidden>
            <input type="text" id="d-<?= $k ?>" name="<?= $k ?>" value="<?= e($values[$k]) ?>" pattern="#[0-9a-fA-F]{6}" maxlength="7" spellcheck="false" autocomplete="off" class="mono"<?= $aria($k) ?> aria-describedby="d-<?= $k ?>-hint">
          </div>
          <p class="hint" id="d-<?= $k ?>-hint"><?= e($hint) ?></p>
          <?= $err($k) ?>
        </div>
      <?php endforeach ?>
      <div class="contrast" aria-live="polite">
        <h3>Contrast check <span class="muted">(WCAG AA needs 4.5:1)</span></h3>
        <ul class="contrast-list">
          <?php foreach ($report as $i => $r): ?>
            <li data-check="<?= $i ?>" class="<?= $r['ok'] ? 'pass' : 'fail' ?>">
              <span class="contrast-sample" aria-hidden="true"><svg viewBox="0 0 2 1" focusable="false"><rect width="2" height="1" fill="<?= e($r['bg']) ?>"/><text x="1" y="0.72" text-anchor="middle" font-size="0.6" font-weight="700" fill="<?= e($r['fg']) ?>">Aa</text></svg></span>
              <span class="contrast-label"><?= e($r['label']) ?></span>
              <span class="contrast-ratio tabular"><?= e(number_format($r['ratio'], 2)) ?>:1</span>
              <span class="badge <?= $r['ok'] ? 'ok' : 'bad' ?>"><?= $r['ok'] ? 'Pass' : 'Too low' ?></span>
            </li>
          <?php endforeach ?>
        </ul>
      </div>
    </div></section>

    <section class="panel"><div class="panel-head"><h2>Type and shape</h2></div><div class="panel-body fields">
      <div class="field-row">
        <?php foreach (['heading_font' => 'Heading font', 'body_font' => 'Body font'] as $k => $label): ?>
          <div class="field"><label for="d-<?= $k ?>"><?= e($label) ?></label>
            <select id="d-<?= $k ?>" name="<?= $k ?>"<?= $aria($k) ?>><?php foreach ($fonts as $fk => $f): ?><option value="<?= e($fk) ?>"<?= $values[$k] === $fk ? ' selected' : '' ?>><?= e($f['label']) ?></option><?php endforeach ?></select>
            <?= $err($k) ?></div>
        <?php endforeach ?>
      </div>
      <p class="hint">Fonts are served from this website (no third-party requests). “No download” options use fonts already on visitors’ devices.</p>
      <fieldset class="field"><legend>Corner radius</legend>
        <div class="segmented"><?php foreach ($radii as $rk => $r): ?><label><input type="radio" name="radius" value="<?= e($rk) ?>"<?= $values['radius'] === $rk ? ' checked' : '' ?>><span><?= e($r['label']) ?></span></label><?php endforeach ?></div><?= $err('radius') ?>
      </fieldset>
      <fieldset class="field"><legend>Button style</legend>
        <div class="segmented"><?php foreach ($buttons as $bk => $bl): ?><label><input type="radio" name="button" value="<?= e($bk) ?>"<?= $values['button'] === $bk ? ' checked' : '' ?>><span><?= e($bl) ?></span></label><?php endforeach ?></div><?= $err('button') ?>
      </fieldset>
    </div></section>

    <div class="panel panel-body design-actions">
      <button class="btn accent" type="submit"><?= icon('check') ?> Save design</button>
      <button class="btn ghost" type="submit" name="reset" value="1" data-confirm-click="Reset colours, fonts and shapes to the chosen theme’s defaults?">Reset to theme defaults</button>
    </div>
  </div>

  <div class="design-preview">
    <div class="preview-bar">
      <span class="muted">Live preview</span>
      <div class="segmented small" role="group" aria-label="Preview width">
        <button type="button" data-width="100%" aria-pressed="true">Desktop</button>
        <button type="button" data-width="390px" aria-pressed="false">Mobile</button>
      </div>
    </div>
    <div class="preview-frame-wrap"><iframe src="/?theme_preview=<?= e($theme) ?>" title="Preview of the home page with these design settings" data-preview-frame></iframe></div>
  </div>
</form>
<script type="module" src="<?= e(asset('assets/js/design.js')) ?>"></script>
