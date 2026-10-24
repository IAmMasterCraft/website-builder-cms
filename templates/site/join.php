<?php /** @var array $values @var array $errors @var array $settings @var array $fields @var ?int $free_left */
$fv = ['values' => $values, 'errors' => $errors];
$terms = safe_url($settings['terms_url']) ?: '/privacy-policy';
?>
<?= view('partials/page_hero', ['heading' => 'Join us', 'intro' => 'Become a member in about five minutes. You will get a member number and a digital member card as soon as you confirm your email.', 'crumbs' => ['Join' => null]]) ?>
<section class="section">
  <div class="container join-layout">
    <div class="join-main">
      <?php if ($free_left !== null && $free_left > 0): ?><div class="notice offer"><strong>Free membership</strong> for the first <?= (int) $settings['free_first_n'] ?> members: <?= e(pluralise($free_left, 'place')) ?> left.</div><?php endif ?>
      <?php if ($errors): ?><div class="flash error" role="alert" tabindex="-1" id="form-errors"><?= icon('alert') ?><span><?= isset($errors['form']) ? e($errors['form']) : 'Please check the ' . e(pluralise(count($errors), 'highlighted field')) . ' below.' ?></span></div><?php endif ?>
      <form class="form join-form" method="post" action="/join" enctype="multipart/form-data" novalidate data-join-form>
        <?= csrf_field() ?>
        <?= form_guard_fields('join') ?>
        <fieldset class="form-section"><legend><span class="step-num" aria-hidden="true">1</span> About you</legend>
          <?= view('partials/profile_inputs', $fv + ['settings' => $settings]) ?>
        </fieldset>
        <?php if ($fields): ?>
          <fieldset class="form-section"><legend><span class="step-num" aria-hidden="true">2</span> A little more</legend>
            <?= view('partials/custom_fields_inputs', $fv + ['fields' => $fields]) ?>
          </fieldset>
        <?php endif ?>
        <fieldset class="form-section"><legend><span class="step-num" aria-hidden="true"><?= $fields ? 3 : 2 ?></span> Photo for your member card</legend>
          <div class="photo-field" data-photo-crop>
            <div class="field">
              <label for="j-photo">Your photo</label>
              <input type="file" id="j-photo" name="photo" accept="image/jpeg,image/png,image/webp" required aria-describedby="j-photo-hint<?= isset($errors['photo']) ? ' j-photo-err' : '' ?>"<?= isset($errors['photo']) ? ' aria-invalid="true"' : '' ?>>
              <p class="hint" id="j-photo-hint">A clear, recent photo of your face (JPEG, PNG or WebP). It is cropped to a square and only shown on your card, to you and to our administrators.</p>
              <?php if (isset($errors['photo'])): ?><p class="error" id="j-photo-err"><?= e($errors['photo']) ?></p><?php endif ?>
            </div>
          </div>
        </fieldset>
        <fieldset class="form-section"><legend><span class="step-num" aria-hidden="true"><?= $fields ? 4 : 3 ?></span> Your account</legend>
          <?= view('partials/ffield', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'auto' => 'email', 'hint' => 'We send a link here to confirm it is yours.', 'extra' => ' maxlength="190"'] + $fv) ?>
          <?php if (!empty($values['email_checked'])): ?><input type="hidden" name="email_checked" value="<?= e((string) $values['email_checked']) ?>"><?php endif ?>
          <div class="form-row">
            <?= view('partials/ffield', ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'auto' => 'new-password', 'hint' => 'At least 10 characters.', 'extra' => ' minlength="10" maxlength="200"'] + $fv) ?>
            <?= view('partials/ffield', ['name' => 'password_confirm', 'label' => 'Repeat password', 'type' => 'password', 'required' => true, 'auto' => 'new-password', 'extra' => ' maxlength="200"'] + $fv) ?>
          </div>
          <?= view('partials/ffield', ['name' => 'newsletters', 'label' => 'Send me news and event updates by email (you can change this at any time).', 'type' => 'checkbox'] + $fv) ?>
          <?= view('partials/ffield', ['name' => 'consent', 'label' => 'I agree to the <a href="' . e($terms) . '" target="_blank" rel="noopener">terms and privacy policy</a>.', 'type' => 'checkbox', 'required' => true] + $fv) ?>
        </fieldset>
        <div class="form-submit">
          <button class="btn accent" type="submit"><?= icon('check') ?> Create my account</button>
          <p class="muted">Already a member? <a href="/login">Log in</a>. Representing an association? <a href="/join/group">Register a group</a>.</p>
        </div>
      </form>
    </div>
    <div class="side-col join-aside">
      <div class="aside-box">
        <h2 class="h3">What happens next</h2>
        <ol class="steps-list">
          <li><strong>Check your email.</strong> Open the link we send you to confirm your address.</li>
          <li><strong>Get your member number.</strong> It is assigned the moment you confirm.</li>
          <li><strong>Use your card.</strong> Download or print your digital member card with its QR code.</li>
        </ol>
      </div>
    </div>
  </div>
</section>
<script type="module" src="<?= e(asset('assets/js/photo-crop.js')) ?>"></script>
