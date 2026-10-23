<?php /** @var array $m @var array $values @var array $errors @var array $settings @var array $fields @var ?array $photo @var array $notes @var int $registrations @var ?string $verify_url */
$fv = ['values' => array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $values), 'errors' => $errors];
?>
<div class="page-head">
  <div>
    <p class="crumbs"><a href="/admin/members">Members</a> /</p>
    <h1><?= e(member_full_name($m)) ?></h1>
    <p><span class="badge <?= e($m['status']) ?>"><?= e($m['status']) ?></span> <?= $m['member_number'] ? '<span class="tabular">' . e($m['member_number']) . '</span>' : 'No number yet' ?> · joined <?= e(fmt_date((string) $m['joined'])) ?><?= $m['last_login_at'] ? ' · last login ' . e(fmt_date((string) $m['last_login_at'])) : '' ?></p>
  </div>
  <div class="actions">
    <?php if ($m['member_number']): ?><a class="btn ghost" href="/admin/members/<?= (int) $m['id'] ?>/card" target="_blank" rel="noopener"><?= icon('award') ?> View / print card</a><?php endif ?>
  </div>
</div>
<?php if ($m['deletion_requested_at']): ?><div class="notice-box">This member asked for their account to be deleted on <?= e(fmt_date((string) $m['deletion_requested_at'])) ?>. Use “Remove member” below to confirm.</div><?php endif ?>
<div class="form-layout">
  <form class="panel" method="post" action="/admin/members/<?= (int) $m['id'] ?>" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <div class="panel-head"><h2>Profile</h2></div>
    <div class="panel-body fields">
      <?= view('partials/ffield', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true] + $fv) ?>
      <?= view('partials/profile_inputs', $fv + ['settings' => $settings, 'forAdmin' => true]) ?>
      <h3>Membership</h3>
      <div class="form-row">
        <?= view('partials/ffield', ['name' => 'membership_type', 'label' => 'Membership type', 'type' => 'select', 'required' => true, 'options' => array_combine($settings['types'], $settings['types']) + [$m['membership_type'] => $m['membership_type']]] + $fv) ?>
        <?= view('partials/ffield', ['name' => 'membership_fee_status', 'label' => 'Fee status', 'type' => 'select', 'options' => ['free' => 'Free', 'due' => 'Due', 'paid' => 'Paid', 'waived' => 'Waived']] + $fv) ?>
      </div>
      <?= view('partials/ffield', ['name' => 'card_valid_until', 'label' => 'Card valid until', 'type' => 'date', 'hint' => 'Leave empty for a card without expiry.'] + $fv) ?>
      <?php if ($fields): ?><h3>Custom fields</h3><?= view('partials/custom_fields_inputs', $fv + ['fields' => $fields]) ?><p class="hint">Fields not visible to members can only be edited here.</p><?php endif ?>
      <div class="field media-field"><span class="label">Photo</span>
        <div class="media-preview square"><?= $photo ? img_html($photo, '12rem', 'Member photo', '', true, 400) : '<span>No photo</span>' ?></div>
        <label for="m-photo" class="hint">Replace the photo (cropped to a square)</label><input type="file" id="m-photo" name="photo" accept="image/jpeg,image/png,image/webp">
        <?php if (isset($errors['photo'])): ?><p class="error"><?= e($errors['photo']) ?></p><?php endif ?>
      </div>
      <div class="form-actions"><button class="btn accent" type="submit"><?= icon('check') ?> Save member</button></div>
    </div>
  </form>
  <div class="side">
    <section class="panel"><div class="panel-head"><h2>Account</h2></div><div class="panel-body fields">
      <?php if ($m['status'] === 'unverified'): ?>
        <form method="post" action="/admin/members/<?= (int) $m['id'] ?>/status"><?= csrf_field() ?><input type="hidden" name="action" value="verify"><button class="btn small" type="submit">Mark email as verified</button></form>
      <?php endif ?>
      <?php if ($m['status'] !== 'suspended'): ?>
        <form method="post" action="/admin/members/<?= (int) $m['id'] ?>/status" data-confirm="Suspend this member? They will not be able to log in and their card will show as not valid."><?= csrf_field() ?><input type="hidden" name="action" value="suspend"><button class="btn small danger-ghost" type="submit">Suspend</button></form>
      <?php else: ?>
        <form method="post" action="/admin/members/<?= (int) $m['id'] ?>/status"><?= csrf_field() ?><input type="hidden" name="action" value="activate"><button class="btn small" type="submit">Reactivate</button></form>
      <?php endif ?>
      <form method="post" action="/admin/members/<?= (int) $m['id'] ?>/reset"><?= csrf_field() ?><button class="btn small ghost" type="submit"><?= icon('mail') ?> Email a password link</button></form>
      <p class="hint"><?= e(pluralise($registrations, 'event registration')) ?></p>
    </div></section>
    <?php if ($m['member_number']): ?>
    <section class="panel"><div class="panel-head"><h2>ID card</h2></div><div class="panel-body fields">
      <p class="hint">Card code <code><?= e((string) $m['card_code']) ?></code><?php if ($verify_url): ?> · <a href="<?= e(parse_url($verify_url, PHP_URL_PATH) ?: '/') ?>" target="_blank" rel="noopener">check page</a><?php endif ?></p>
      <form method="post" action="/admin/members/<?= (int) $m['id'] ?>/card-code" data-confirm="Create a new card code? The QR code on the member’s current card will stop working."><?= csrf_field() ?><button class="btn small ghost" type="submit">Regenerate card code</button></form>
      <p class="hint">Set the expiry date in the profile form.</p>
    </div></section>
    <?php endif ?>
    <section class="panel" id="notes"><div class="panel-head"><h2>Notes</h2></div><div class="panel-body fields">
      <form method="post" action="/admin/members/<?= (int) $m['id'] ?>/notes"><?= csrf_field() ?>
        <div class="field"><label for="note-body">Add a note</label><textarea id="note-body" name="body" rows="3" maxlength="2000"></textarea></div>
        <button class="btn small" type="submit">Add note</button></form>
      <?php if ($notes): ?><ul class="note-list"><?php foreach ($notes as $n): ?><li><p><?= nl2br(e($n['body']), false) ?></p><span class="muted"><?= e($n['author'] ?? 'Unknown') ?> · <?= e(fmt_datetime((string) $n['created_at'])) ?></span></li><?php endforeach ?></ul><?php else: ?><p class="muted">No notes yet. Only staff see notes.</p><?php endif ?>
    </div></section>
    <form class="panel panel-body" method="post" action="/admin/members/<?= (int) $m['id'] ?>/delete" data-confirm="Remove this member permanently?">
      <?= csrf_field() ?>
      <h2>Remove member</h2>
      <p class="muted">Deletes the account and photo. Event registrations are kept without the name; the member number is retired.</p>
      <div class="field"><label for="del-confirm">Type DELETE to confirm</label><input type="text" id="del-confirm" name="confirm" autocomplete="off" pattern="DELETE"></div>
      <button class="btn danger-ghost small" type="submit"><?= icon('trash') ?> Remove member</button>
    </form>
  </div>
</div>
