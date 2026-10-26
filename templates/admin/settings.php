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
<div class="page-head"><div><h1>Site settings</h1><p>Identity, contact details, donations and logo.</p></div></div>
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
      <section class="panel"><div class="panel-head"><h2>Donations</h2></div><div class="panel-body fields">
        <p class="muted">Used by the Donate section and footer widget. There is no online payment: buttons link to your own donation pages.</p>
        <?= $f('bank_text', 'Bank transfer details', 'textarea', 'For example account name, sort code and account number.') ?>
        <?php for ($i = 1; $i <= 3; $i++): ?><div class="field-row"><?= $f('donate_label_' . $i, 'Button ' . $i . ' text') ?><?= $f('donate_url_' . $i, 'Button ' . $i . ' link', 'url') ?></div><?php endfor ?>
      </div></section>
      <section class="panel"><div class="panel-head"><h2>Analytics and cookies</h2></div><div class="panel-body fields">
        <p class="muted">Off by default. Pick a provider from the list and give its site ID; you cannot paste other scripts. Providers that set cookies are only loaded after a visitor accepts the cookie banner; without analytics there is no banner.</p>
        <div class="field"><label for="s-analytics_provider">Provider</label><select id="s-analytics_provider" name="analytics_provider"<?= isset($errors['analytics_provider']) ? ' aria-invalid="true" aria-describedby="s-analytics_provider-err"' : '' ?>><option value="">None (no analytics, no cookie banner)</option><?php foreach (analytics_providers() as $pk => $pd): ?><option value="<?= e($pk) ?>"<?= $v('analytics_provider') === $pk ? ' selected' : '' ?>><?= e($pd['label']) ?></option><?php endforeach ?></select>
          <?php if (isset($errors['analytics_provider'])): ?><p class="error" id="s-analytics_provider-err"><?= e($errors['analytics_provider']) ?></p><?php endif ?></div>
        <?= $f('analytics_id', 'Site ID', 'text', 'Plausible: your domain (example.org). Fathom: the site ID (ABCDEFGH). Google Analytics 4: the measurement ID (G-…).') ?>
        <?= $f('cookie_banner', 'Cookie banner text', 'textarea', 'Shown with “Accept analytics” and “No thanks” buttons when the provider sets cookies. Leave empty for the default wording.') ?>
      </div></section>
      <section class="panel"><div class="panel-head"><h2>Help and member support</h2></div><div class="panel-body fields">
        <input type="hidden" name="support_panel" value="1">
        <div class="field"><label for="s-help_member_guide">Member guide at /help</label><select id="s-help_member_guide" name="help_member_guide" aria-describedby="s-help_member_guide-hint"><option value="public"<?= $v('help_member_guide') !== 'members' ? ' selected' : '' ?>>Everyone (public)</option><option value="members"<?= $v('help_member_guide') === 'members' ? ' selected' : '' ?>>Logged-in members only</option></select>
          <p class="hint" id="s-help_member_guide-hint">The member guide explains joining, the dashboard, the member card and email preferences. Staff always have the full help centre under Admin → Help centre.</p></div>
        <div class="field"><label class="check"><input type="checkbox" name="impersonation_notify" value="1"<?= $v('impersonation_notify') === '1' ? ' checked' : '' ?> aria-describedby="s-impersonation_notify-hint"> Email the member when an admin views their account</label>
          <p class="hint" id="s-impersonation_notify-hint">Sent when someone uses “Log in as this member”. The wording is the email template “An administrator viewed your account”. Recommended: on.</p></div>
      </div></section>
      <section class="panel"><div class="panel-head"><h2>Social links</h2></div><div class="panel-body fields">
        <div class="field-row"><?php foreach (\Admin\Settings::SOCIAL as $k => $l): ?><?= $f('social_' . $k, $l, 'url') ?><?php endforeach ?></div>
      </div></section>
    </div>
    <div class="side">
      <section class="panel"><div class="panel-head"><h2>Logo</h2></div><div class="panel-body fields">
        <div class="field media-field"><span class="label">Logo</span>
          <div class="media-preview file"><?= $logo ? img_html($logo, '12rem', 'Current logo', '', true, 400) : '<span>No logo: the site name is shown instead</span>' ?></div>
          <label for="s-logo" class="hint">Upload a logo (PNG or WebP with transparency works best)</label><input type="file" id="s-logo" name="logo_upload" accept="<?= e(media_accept('image')) ?>">
          <?php if (isset($errors['logo'])): ?><p class="error"><?= e($errors['logo']) ?></p><?php endif ?>
          <?php if ($logo): ?><label class="check"><input type="checkbox" name="logo_remove" value="1"> Remove logo</label><?php endif ?>
        </div>
      </div></section>
      <section class="panel"><div class="panel-head"><h2>More settings</h2></div><div class="panel-body">
        <ul class="link-list">
          <li><a href="/admin/design">Design: theme, colours and fonts</a></li>
          <li><a href="/admin/layout">Header and footer</a></li>
          <li><a href="/admin/settings/membership">Membership and ID cards</a></li>
          <li><a href="/admin/custom-fields">Custom profile fields</a></li>
        </ul>
      </div></section>
      <div class="panel panel-body"><button class="btn accent" type="submit"><?= icon('check') ?> Save settings</button></div>
    </div>
  </div>
</form>
