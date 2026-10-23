<?= view('install/_steps', ['step' => $step]) ?>
<h1>Choose a theme</h1>
<p class="muted">You can change it, and adjust colours and fonts, at any time.</p>
<form method="post" action="/install/theme">
  <?= csrf_field() ?>
  <fieldset class="theme-choices"><legend class="sr-only">Theme</legend>
    <?php foreach ($themes as $key => $t): ?>
      <label class="theme-choice"><input type="radio" name="theme" value="<?= e($key) ?>"<?= $key === $current ? ' checked' : '' ?>>
        <strong><?= e($t['name']) ?></strong>
        <span class="swatches" aria-hidden="true"><?php foreach (array_slice($t['swatches'], 0, 4) as $i => $sw): ?><span class="swatch swatch-<?= $i + 1 ?>"></span><?php endforeach ?></span>
        <span class="muted"><?= e($t['description']) ?></span></label>
    <?php endforeach ?>
  </fieldset>
  <?php if (isset($errors['theme'])): ?><p class="error"><?= e($errors['theme']) ?></p><?php endif ?>
  <p class="muted">Three more themes (Heritage, Harvest, Monochrome) arrive with the page builder.</p>
  <div class="form-actions"><button class="btn accent" type="submit">Continue</button></div>
</form>
