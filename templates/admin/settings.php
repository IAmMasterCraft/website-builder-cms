<?php
$v = static fn (string $k): string => (string) ($values[$k] ?? '');
$f = static function (string $k, string $label, string $type = 'text', string $hint = '') use ($v, $errors): string {
    $err = $errors[$k] ?? null;
    $desc = trim(($hint !== '' ? "s-$k-hint " : '') . ($err ? "s-$k-err" : ''));
    return '<div class="field"><label for="s-' . e($k) . '">' . e($label) . '</label>'
        . ($type === 'textarea'
            ? '<textarea id="s-' . e($k) . '" name="' . e($k) . '" rows="3"' . ($desc ? ' aria-describedby="' . e($desc) . '"' : '') . ($err ? ' aria-invalid="true"' : '') . '>' . e($v($k)) . '</textarea>'
            : '<input type="' . e($type) . '" id="s-' . e($k) . '" name="' . e($k) . '" value="' . e($v($k)) . '"' . ($desc ? ' aria-describedby="' . e($desc) . '"' : '') . ($err ? ' aria-invalid="true"' : '') . '>')
        . ($hint !== '' ? '<p class="hint" id="s-' . e($k) . '-hint">' . e($hint) . '</p>' : '')
        . ($err ? '<p class="error" id="s-' . e($k) . '-err">' . e($err) . '</p>' : '') . '</div>';
};
?>
<div class="page-head"><div><h1>Site settings</h1><p>Identity, contact details, header and theme. More settings arrive with later features.</p></div></div>
<?php if ($errors): ?><div class="flash error" role="alert"><?= icon('alert') ?><span>Please fix the highlighted fields.</span></div><?php endif ?>
<form method="post" action="/admin/settings" enctype="multipart/form-data" novalidate>
  <?= csrf_field() ?>
  <div class="form-layout">
    <div>
      <section class="panel"><div class="panel-head"><h2>Identity</h2></div><div class="panel-body fields">
        <?= $f('site_name', 'Organisation name') ?>
        <?= $f('tagline', 'Tagline', 'text', 'Shown under the name on the home page and in search results.') ?>
        <?= $f('footer_about', 'Footer “about” text', 'textarea') ?>
        <?= $f('charity_number', 'Charity / registration number', 'text', 'Shown in the top bar and footer when filled in.') ?>
        <div class="field"><label for="s-timezone">Time zone</label><select id="s-timezone" name="timezone"><?php foreach (DateTimeZone::listIdentifiers() as $tz): ?><option value="<?= e($tz) ?>"<?= $v('timezone') === $tz ? ' selected' : '' ?>><?= e($tz) ?></option><?php endforeach ?></select></div>
      </div></section>
      <section class="panel"><div class="panel-head"><h2>Contact</h2></div><div class="panel-body fields">
        <div class="field-row"><?= $f('contact_email', 'Email', 'email') ?><?= $f('contact_phone', 'Phone', 'tel') ?></div>
        <?= $f('contact_address', 'Address') ?>
      </div></section>
      <section class="panel"><div class="panel-head"><h2>Social links</h2></div><div class="panel-body fields">
        <div class="field-row"><?php foreach (\Admin\Settings::SOCIAL as $k => $l): ?><?= $f('social_' . $k, $l, 'url') ?><?php endforeach ?></div>
      </div></section>
    </div>
    <div class="side">
      <section class="panel"><div class="panel-head"><h2>Header</h2></div><div class="panel-body fields">
        <label class="check"><input type="checkbox" name="topbar" value="1"<?= $v('topbar') === '1' ? ' checked' : '' ?>> Show the top bar (phone, email, charity number, social icons)</label>
        <label class="check"><input type="checkbox" name="show_auth" value="1"<?= $v('show_auth') === '1' ? ' checked' : '' ?>> Show log in and “Join us” buttons</label>
        <label class="check"><input type="checkbox" name="sticky" value="1"<?= $v('sticky') === '1' ? ' checked' : '' ?>> Keep the header visible when scrolling</label>
        <div class="field media-field"><span class="label">Logo</span>
          <div class="media-preview file"><?= $logo ? img_html($logo, '12rem', 'Current logo', '', true, 400) : '<span>No logo: the site name is shown instead</span>' ?></div>
          <label for="s-logo" class="hint">Upload a logo (PNG or WebP with transparency works best)</label><input type="file" id="s-logo" name="logo_upload" accept="<?= e(media_accept('image')) ?>">
          <?php if (isset($errors['logo'])): ?><p class="error"><?= e($errors['logo']) ?></p><?php endif ?>
          <?php if ($logo): ?><label class="check"><input type="checkbox" name="logo_remove" value="1"> Remove logo</label><?php endif ?>
        </div>
      </div></section>
      <section class="panel"><div class="panel-head"><h2>Theme</h2></div><div class="panel-body fields">
        <?php foreach ($themes as $key => $t): ?>
          <label class="check"><input type="radio" name="theme" value="<?= e($key) ?>"<?= $v('theme') === $key ? ' checked' : '' ?>> <span><strong><?= e($t['name']) ?></strong><br><span class="muted"><?= e($t['description']) ?></span></span></label>
        <?php endforeach ?>
        <p class="hint">Colour, font and corner controls with live preview arrive with the page builder (phase 3).</p>
      </div></section>
      <div class="panel panel-body"><button class="btn accent" type="submit"><?= icon('check') ?> Save settings</button></div>
    </div>
  </div>
</form>
