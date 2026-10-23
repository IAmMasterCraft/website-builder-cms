<?= view('install/_steps', ['step' => $step]) ?>
<h1>Choose a theme</h1>
<p class="muted">You can change it, and adjust colours and fonts, at any time under Design.</p>
<form method="post" action="/install/theme">
  <?= csrf_field() ?>
  <fieldset class="theme-choices"><legend class="sr-only">Theme</legend>
    <?php foreach ($themes as $key => $t): ?>
      <label class="theme-choice"><input type="radio" name="theme" value="<?= e($key) ?>"<?= $key === $current ? ' checked' : '' ?>>
        <span class="theme-thumb">
          <?php if ($t['has_preview']): ?><img src="/theme-preview/<?= e($key) ?>.png" alt="" width="320" height="200" loading="lazy">
          <?php else: ?><svg viewBox="0 0 4 1" preserveAspectRatio="none" aria-hidden="true" focusable="false"><?php foreach (array_slice($t['swatches'], 0, 4) as $i => $sw): ?><rect x="<?= $i ?>" y="0" width="1" height="1" fill="<?= e(hex_valid($sw) ? $sw : '#cccccc') ?>"/><?php endforeach ?></svg><?php endif ?>
        </span>
        <strong><?= e($t['name']) ?></strong>
        <span class="muted"><?= e($t['description']) ?></span></label>
    <?php endforeach ?>
  </fieldset>
  <?php if (isset($errors['theme'])): ?><p class="error"><?= e($errors['theme']) ?></p><?php endif ?>
  <div class="form-actions"><button class="btn accent" type="submit">Continue</button></div>
</form>
