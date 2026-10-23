<?php /** @var ?array $group @var array $values @var array $errors @var array $fields @var ?array $logo */
$fv = ['values' => array_map(static fn ($v) => is_string($v) ? $v : (string) ($v ?? ''), $values), 'errors' => $errors];
$fv['values']['description'] = trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p><p>'], ["\n", "\n\n"], (string) ($values['description'] ?? ''))), ENT_QUOTES | ENT_HTML5));
?>
<section class="dash-card">
  <form class="form" method="post" action="/account/profile" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <div class="field"><span class="label">Group ID</span><p class="static-value tabular"><?= e($group['group_number'] ?? 'Assigned when your email is verified') ?></p></div>
    <?= view('partials/ffield', ['name' => 'name', 'label' => 'Organisation name', 'required' => true, 'extra' => ' maxlength="200"'] + $fv) ?>
    <div class="form-row">
      <?= view('partials/ffield', ['name' => 'contact_person', 'label' => 'Contact person', 'required' => true, 'extra' => ' maxlength="160"'] + $fv) ?>
      <?= view('partials/ffield', ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'extra' => ' maxlength="40"'] + $fv) ?>
    </div>
    <?= view('partials/ffield', ['name' => 'address', 'label' => 'Address', 'extra' => ' maxlength="300"'] + $fv) ?>
    <div class="form-row">
      <?= view('partials/ffield', ['name' => 'country', 'label' => 'Country', 'required' => true, 'extra' => ' maxlength="80"'] + $fv) ?>
      <?= view('partials/ffield', ['name' => 'website', 'label' => 'Website', 'type' => 'url', 'extra' => ' maxlength="500" placeholder="https://"'] + $fv) ?>
    </div>
    <?= view('partials/ffield', ['name' => 'description', 'label' => 'About your group', 'type' => 'textarea', 'hint' => 'Shown in the public groups directory.'] + $fv) ?>
    <?= view('partials/custom_fields_inputs', $fv + ['fields' => $fields]) ?>
    <div class="field"><label for="g-logo">Logo</label><?= $logo ? img_html($logo, '6rem', 'Current logo', 'dir-logo', false, 400) : '' ?><input type="file" id="g-logo" name="logo" accept="image/jpeg,image/png,image/webp"><?php if (isset($errors['logo'])): ?><p class="error"><?= e($errors['logo']) ?></p><?php endif ?></div>
    <p><button class="btn accent" type="submit"><?= icon('check') ?> Save group profile</button></p>
  </form>
</section>
