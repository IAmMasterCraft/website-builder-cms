<?php /** @var array $columns @var array $header @var array $errors @var array $widgets @var array $menus */ ?>
<div class="page-head"><div><h1>Header and footer</h1><p>What appears around every page. Menus themselves are edited under <a href="/admin/menus">Menus</a>; the logo and contact details under <a href="/admin/settings">Site settings</a>.</p></div></div>
<?php if ($errors): ?><div class="flash error" role="alert"><?= icon('alert') ?><span>Please fix the highlighted fields.</span></div><?php endif ?>
<form method="post" action="/admin/layout">
  <?= csrf_field() ?>
  <div class="form-layout">
    <div>
      <section class="panel"><div class="panel-head"><h2>Footer columns</h2></div><div class="panel-body">
        <p class="muted">Up to four columns, each built from a widget. Columns with nothing to show (an empty menu, no posts yet) are skipped.</p>
        <div class="footer-cols">
          <?php foreach ($columns as $i => $col): $n = $i + 1; ?>
            <fieldset class="footer-col" data-footer-col>
              <legend>Column <?= $n ?></legend>
              <div class="field"><label for="col-w-<?= $i ?>">Widget</label>
                <select id="col-w-<?= $i ?>" name="col_widget[]" data-col-widget<?= isset($errors["col_$i"]) ? ' aria-invalid="true" aria-describedby="col-err-' . $i . '"' : '' ?>>
                  <option value="">Not used</option>
                  <?php foreach ($widgets as $wk => $wl): ?><option value="<?= e($wk) ?>"<?= $col['widget'] === $wk ? ' selected' : '' ?>><?= e($wl) ?></option><?php endforeach ?>
                </select></div>
              <div class="field"><label for="col-t-<?= $i ?>">Heading <span class="muted">(optional)</span></label><input type="text" id="col-t-<?= $i ?>" name="col_title[]" value="<?= e($col['title']) ?>" maxlength="60"></div>
              <div class="field" data-col-show="menu"><label for="col-m-<?= $i ?>">Menu</label>
                <select id="col-m-<?= $i ?>" name="col_menu[]"><?php foreach ($menus as $mk => $ml): ?><option value="<?= e($mk) ?>"<?= $col['menu'] === $mk ? ' selected' : '' ?>><?= e($ml) ?></option><?php endforeach ?></select></div>
              <div class="field" data-col-show="about join donate"><label for="col-x-<?= $i ?>">Text <span class="muted">(optional)</span></label><textarea id="col-x-<?= $i ?>" name="col_text[]" rows="2" maxlength="500"><?= e($col['text']) ?></textarea></div>
              <?php if (isset($errors["col_$i"])): ?><p class="error" id="col-err-<?= $i ?>"><?= e($errors["col_$i"]) ?></p><?php endif ?>
            </fieldset>
          <?php endforeach ?>
        </div>
      </div></section>
      <section class="panel"><div class="panel-head"><h2>Footer call to action</h2></div><div class="panel-body fields">
        <label class="check"><input type="checkbox" name="footer_cta" value="1"<?= $header['footer_cta'] ? ' checked' : '' ?>> Show a large “join us” strip at the top of the footer</label>
        <?= field_html('footer_cta_heading', 'Heading', 'text', $header['footer_cta_heading'], $errors['footer_cta_heading'] ?? null, ['max' => 120, 'prefix' => 'fc']) ?>
        <?= field_html('footer_cta_text', 'Text', 'textarea', $header['footer_cta_text'], $errors['footer_cta_text'] ?? null, ['max' => 300, 'rows' => 2, 'prefix' => 'fc', 'hint' => 'The strip links to the sign-up page and, when set up in Site settings, your first donation link.']) ?>
        <label class="check"><input type="checkbox" name="footer_word" value="1"<?= $header['footer_word'] ? ' checked' : '' ?>> Show the site name as a large outlined word at the very bottom</label>
      </div></section>
    </div>
    <div class="side">
      <section class="panel"><div class="panel-head"><h2>Header</h2></div><div class="panel-body fields">
        <label class="check"><input type="checkbox" name="topbar" value="1"<?= $header['topbar'] ? ' checked' : '' ?>> Show the top bar (phone, email, charity number, social icons)</label>
        <label class="check"><input type="checkbox" name="show_auth" value="1"<?= $header['show_auth'] ? ' checked' : '' ?>> Show log in and “Join us” buttons</label>
        <label class="check"><input type="checkbox" name="sticky" value="1"<?= $header['sticky'] ? ' checked' : '' ?>> Keep the header visible when scrolling</label>
        <label class="check"><input type="checkbox" name="listen_live" value="1"<?= $header['listen_live'] ? ' checked' : '' ?>> Show a “Listen live” button while the radio is on air</label>
        <p class="hint">The radio arrives in a later update; the button appears automatically once the station is switched on.</p>
      </div></section>
      <div class="panel panel-body"><button class="btn accent" type="submit"><?= icon('check') ?> Save header and footer</button></div>
    </div>
  </div>
</form>
