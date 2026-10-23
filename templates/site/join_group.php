<?php /** @var array $values @var array $errors @var array $settings @var array $fields */
$fv = ['values' => $values, 'errors' => $errors];
$terms = safe_url($settings['terms_url']) ?: '/privacy-policy';
?>
<?= view('partials/page_hero', ['heading' => 'Register a group', 'intro' => 'Affiliated associations, towns and community groups can register here. Once your email is confirmed, your group gets an ID and appears in our directory.', 'crumbs' => ['Join' => '/join', 'Register a group' => null]]) ?>
<section class="section">
  <div class="container narrow">
    <?php if ($errors): ?><div class="flash error" role="alert"><?= icon('alert') ?><span><?= isset($errors['form']) ? e($errors['form']) : 'Please check the ' . e(pluralise(count($errors), 'highlighted field')) . ' below.' ?></span></div><?php endif ?>
    <form class="form join-form" method="post" action="/join/group" enctype="multipart/form-data" novalidate>
      <?= csrf_field() ?>
      <?= form_guard_fields('join_group') ?>
      <fieldset class="form-section"><legend>Your organisation</legend>
        <?= view('partials/ffield', ['name' => 'name', 'label' => 'Organisation name', 'required' => true, 'auto' => 'organization', 'extra' => ' maxlength="200"'] + $fv) ?>
        <div class="form-row">
          <?= view('partials/ffield', ['name' => 'contact_person', 'label' => 'Contact person', 'required' => true, 'auto' => 'name', 'extra' => ' maxlength="160"'] + $fv) ?>
          <?= view('partials/ffield', ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'auto' => 'tel', 'extra' => ' maxlength="40"'] + $fv) ?>
        </div>
        <?= view('partials/ffield', ['name' => 'address', 'label' => 'Address', 'auto' => 'street-address', 'extra' => ' maxlength="300"'] + $fv) ?>
        <?= view('partials/ffield', ['name' => 'country', 'label' => 'Country', 'required' => true, 'auto' => 'country-name', 'extra' => ' maxlength="80"'] + $fv) ?>
        <?= view('partials/custom_fields_inputs', $fv + ['fields' => $fields]) ?>
        <div class="field"><label for="j-logo">Logo <span class="muted">(optional)</span></label>
          <input type="file" id="j-logo" name="logo" accept="image/jpeg,image/png,image/webp" aria-describedby="j-logo-hint<?= isset($errors['logo']) ? ' j-logo-err' : '' ?>">
          <p class="hint" id="j-logo-hint">Shown next to your group in the public directory.</p>
          <?php if (isset($errors['logo'])): ?><p class="error" id="j-logo-err"><?= e($errors['logo']) ?></p><?php endif ?></div>
      </fieldset>
      <fieldset class="form-section"><legend>Account</legend>
        <?= view('partials/ffield', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'auto' => 'email', 'extra' => ' maxlength="190"'] + $fv) ?>
        <div class="form-row">
          <?= view('partials/ffield', ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'auto' => 'new-password', 'hint' => 'At least 10 characters.', 'extra' => ' minlength="10" maxlength="200"'] + $fv) ?>
          <?= view('partials/ffield', ['name' => 'password_confirm', 'label' => 'Repeat password', 'type' => 'password', 'required' => true, 'auto' => 'new-password', 'extra' => ' maxlength="200"'] + $fv) ?>
        </div>
        <?= view('partials/ffield', ['name' => 'consent', 'label' => 'I agree to the <a href="' . e($terms) . '" target="_blank" rel="noopener">terms and privacy policy</a>.', 'type' => 'checkbox', 'required' => true] + $fv) ?>
      </fieldset>
      <div class="form-submit"><button class="btn accent" type="submit"><?= icon('check') ?> Register our group</button></div>
    </form>
  </div>
</section>
