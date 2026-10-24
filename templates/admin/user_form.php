<?php /** @var array $values @var array $errors @var ?array $user @var bool $is_me @var array $perms @var array $roles @var array $recent @var bool $last_super */
$v = static fn (string $k): string => is_scalar($values[$k] ?? null) ? (string) $values[$k] : '';
$granted = array_values(array_filter((array) ($values['permissions'] ?? []), 'is_string'));
$lockRole = $is_me || $last_super;
?>
<div class="page-head"><div><p class="crumbs"><a href="/admin/users">Users and roles</a> /</p><h1><?= $user ? e((string) $user['display_name']) : 'New admin' ?></h1>
  <?php if ($user): ?><p class="muted">Created <?= e(fmt_date((string) $user['created_at'])) ?> · last login <?= $user['last_login_at'] ? e(fmt_datetime((string) $user['last_login_at'])) : 'never' ?></p><?php endif ?></div></div>
<?= form_errors_html($errors) ?>
<div class="form-layout">
  <form class="panel" method="post" action="/admin/users<?= $user ? '/' . (int) $user['id'] : '' ?>" novalidate>
    <?= csrf_field() ?>
    <div class="panel-body fields">
      <div class="field-row">
        <?= field_html('display_name', 'Name', 'text', $v('display_name'), $errors['display_name'] ?? null, ['required' => true, 'max' => 120, 'prefix' => 'u', 'autocomplete' => 'off']) ?>
        <?= field_html('email', 'Email (used to log in)', 'email', $v('email'), $errors['email'] ?? null, ['required' => true, 'max' => 190, 'prefix' => 'u', 'autocomplete' => 'off']) ?>
      </div>
      <?php if ($lockRole): ?>
        <input type="hidden" name="role" value="<?= e($v('role')) ?>"><input type="hidden" name="status" value="<?= e($v('status')) ?>">
        <p class="notice-box">Role: <strong><?= e($roles[$v('role')] ?? $v('role')) ?></strong>, status: <strong><?= e($v('status')) ?></strong>.
          <?= $is_me ? 'You cannot change your own role or status; ask another super admin.' : 'This is the only active super admin, so they cannot be demoted or suspended. Make someone else a super admin first.' ?></p>
        <?php if (isset($errors['role'])): ?><p class="error"><?= e($errors['role']) ?></p><?php endif ?>
      <?php else: ?>
      <fieldset class="field"><legend>Role</legend>
        <?php foreach ($roles as $rk => $rl): ?>
          <label class="check"><input type="radio" name="role" value="<?= e($rk) ?>"<?= $v('role') === $rk ? ' checked' : '' ?> data-role-choice> <?= e($rl) ?>: <span class="muted"><?= $rk === 'super_admin' ? 'everything, including settings, design, users, backups and the audit log' : 'only the areas ticked below' ?></span></label>
        <?php endforeach ?>
        <?php if (isset($errors['role'])): ?><p class="error"><?= e($errors['role']) ?></p><?php endif ?>
      </fieldset>
      <?php if ($user): ?>
      <fieldset class="field"><legend>Status</legend>
        <label class="check"><input type="radio" name="status" value="active"<?= $v('status') === 'active' ? ' checked' : '' ?>> Active</label>
        <label class="check"><input type="radio" name="status" value="suspended"<?= $v('status') === 'suspended' ? ' checked' : '' ?>> Suspended (cannot log in; logged out at once)</label>
      </fieldset>
      <?php else: ?><input type="hidden" name="status" value="active"><?php endif ?>
      <?php endif ?>
      <fieldset class="field" data-perms<?= isset($errors['permissions']) ? ' aria-describedby="u-perms-err"' : '' ?>><legend>Admins may manage</legend>
        <p class="hint">Ignored for super admins, who can do everything.</p>
        <div class="perm-grid">
        <?php foreach ($perms as $pk => $pl): ?>
          <label class="check"><input type="checkbox" name="permissions[]" value="<?= e($pk) ?>"<?= in_array($pk, $granted, true) ? ' checked' : '' ?>> <?= e($pl) ?></label>
        <?php endforeach ?>
        </div>
        <?php if (isset($errors['permissions'])): ?><p class="error" id="u-perms-err"><?= e($errors['permissions']) ?></p><?php endif ?>
      </fieldset>
      <?php if (!$user): ?>
      <fieldset class="field"><legend>Password</legend>
        <label class="check"><input type="radio" name="password_mode" value="invite"<?= $v('password_mode') !== 'set' ? ' checked' : '' ?>> Email an invitation to choose a password (link works for <?= (int) STAFF_INVITE_HOURS ?> hours)</label>
        <label class="check"><input type="radio" name="password_mode" value="set"<?= $v('password_mode') === 'set' ? ' checked' : '' ?>> Set a password now and tell them yourself</label>
        <?= field_html('password', 'Password (at least 10 characters)', 'password', '', $errors['password'] ?? null, ['prefix' => 'u', 'autocomplete' => 'new-password']) ?>
      </fieldset>
      <?php endif ?>
      <div class="form-actions"><button class="btn accent" type="submit"><?= icon('check') ?> <?= $user ? 'Save' : 'Create admin' ?></button><a class="btn ghost" href="/admin/users">Cancel</a></div>
    </div>
  </form>
  <?php if ($user): ?>
  <div class="side">
    <form class="panel panel-body" method="post" action="/admin/users/<?= (int) $user['id'] ?>/reset">
      <?= csrf_field() ?><h2>Password</h2><p class="muted">Email a link to choose a new password (valid for one hour).</p>
      <button class="btn ghost small" type="submit"><?= icon('mail') ?> Send password link</button>
    </form>
    <section class="panel"><div class="panel-head"><h2>Recent actions</h2><a class="small-text" href="/admin/audit?user=<?= (int) $user['id'] ?>">Full history</a></div>
      <?php if (!$recent): ?><p class="panel-body muted">Nothing recorded yet.</p><?php else: ?>
      <ul class="row-list"><?php foreach ($recent as $a): ?><li><div><span class="row-title"><?= e($a['action']) ?> <?= e($a['item_type']) ?></span><p class="muted small-text"><?= e($a['summary']) ?></p></div><div class="row-meta tabular small-text"><?= e(fmt_datetime((string) $a['created_at'])) ?></div></li><?php endforeach ?></ul>
      <?php endif ?>
    </section>
  </div>
  <?php endif ?>
</div>
