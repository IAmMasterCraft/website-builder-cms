<?php /** @var array $member @var array $values @var array $errors @var array $settings @var array $fields @var ?array $photo */ ?>
<div class="account-panels">
  <section class="dash-card">
    <h2>Details</h2>
    <form class="form" method="post" action="/account/profile" novalidate>
      <?= csrf_field() ?>
      <div class="field"><span class="label">Email</span><p class="static-value"><?= e($user['email']) ?></p><p class="hint">To change your email, please contact us.</p></div>
      <?= view('partials/profile_inputs', ['values' => $values, 'errors' => $errors, 'settings' => $settings]) ?>
      <?= view('partials/custom_fields_inputs', ['fields' => $fields, 'values' => $values, 'errors' => $errors]) ?>
      <p><button class="btn accent" type="submit"><?= icon('check') ?> Save profile</button></p>
    </form>
  </section>
  <section class="dash-card">
    <h2>Photo</h2>
    <div class="profile-photo-row">
      <div class="profile-photo small"><?= $photo ? img_html($photo, '8rem', 'Your current photo', '', false, 400) : '<span class="avatar-fallback" aria-hidden="true">' . e(mb_substr($member['first_name'], 0, 1)) . '</span>' ?></div>
      <form class="form" method="post" action="/account/photo" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div data-photo-crop><div class="field"><label for="p-photo">New photo</label><input type="file" id="p-photo" name="photo" accept="image/jpeg,image/png,image/webp" required aria-describedby="p-photo-hint"><p class="hint" id="p-photo-hint">Cropped to a square for your card. Only you and our administrators can see it.</p></div></div>
        <p><button class="btn" type="submit"><?= icon('upload') ?> Upload photo</button></p>
      </form>
    </div>
  </section>
</div>
<script type="module" src="<?= e(asset('assets/js/photo-crop.js')) ?>"></script>
