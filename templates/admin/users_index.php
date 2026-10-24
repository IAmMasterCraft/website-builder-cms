<?php /** @var array $rows @var string $q @var string $status @var array $me @var int $super_count @var array $perms */ ?>
<div class="page-head"><div><h1>Users and roles</h1><p>Who can use the admin area, and what each admin may manage. Super admins can do everything; there must always be at least one.</p></div>
  <div class="actions"><a class="btn accent" href="/admin/users/new"><?= icon('plus') ?> New admin</a></div></div>
<div class="panel">
  <form class="toolbar" method="get" action="/admin/users" role="search">
    <label for="uq" class="sr-only">Search admins</label>
    <input type="search" id="uq" name="q" value="<?= e($q) ?>" placeholder="Name or email" maxlength="100">
    <label for="us" class="sr-only">Status</label>
    <select id="us" name="status"><option value="">All statuses</option><option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option><option value="suspended"<?= $status === 'suspended' ? ' selected' : '' ?>>Suspended</option></select>
    <button class="btn small ghost" type="submit">Filter</button>
    <?php if ($q !== '' || $status !== ''): ?><a class="btn small ghost" href="/admin/users">Clear</a><?php endif ?>
  </form>
  <div class="table-wrap"><table class="data">
    <thead><tr><th scope="col">Name</th><th scope="col">Role</th><th scope="col">May manage</th><th scope="col">Status</th><th scope="col">Last login</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty-row">No admins match.</td></tr><?php endif ?>
    <?php foreach ($rows as $r): $isMe = (int) $r['id'] === (int) $me['id']; $lastSuper = $r['role'] === 'super_admin' && $r['status'] === 'active' && $super_count <= 1; ?>
      <tr>
        <td class="title-cell"><a href="/admin/users/<?= (int) $r['id'] ?>"><?= e($r['display_name']) ?></a><?= $isMe ? ' <span class="badge">you</span>' : '' ?><br><span class="muted"><?= e($r['email']) ?></span></td>
        <td><span class="badge <?= $r['role'] === 'super_admin' ? 'members' : '' ?>"><?= e(STAFF_ROLES[$r['role']] ?? $r['role']) ?></span></td>
        <td class="small-text"><?= $r['role'] === 'super_admin' ? 'Everything' : e(implode(', ', array_map(static fn (string $k): string => explode(' (', $perms[$k] ?? $k)[0], $r['permissions'])) ?: '—') ?></td>
        <td><span class="badge <?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
        <td class="tabular"><?= $r['last_login_at'] ? e(fmt_datetime((string) $r['last_login_at'])) : 'never' ?></td>
        <td class="actions-cell">
          <a class="btn tiny ghost" href="/admin/users/<?= (int) $r['id'] ?>">Edit<span class="sr-only"> <?= e($r['display_name']) ?></span></a>
          <?php if (!$isMe && !$lastSuper): ?>
          <form method="post" action="/admin/users/<?= (int) $r['id'] ?>/status" class="inline-form"<?= $r['status'] === 'active' ? ' data-confirm="Suspend ' . e($r['display_name']) . '? They are logged out at once."' : '' ?>>
            <?= csrf_field() ?><input type="hidden" name="status" value="<?= $r['status'] === 'active' ? 'suspended' : 'active' ?>">
            <button class="btn tiny ghost" type="submit"><?= $r['status'] === 'active' ? 'Suspend' : 'Reactivate' ?><span class="sr-only"> <?= e($r['display_name']) ?></span></button>
          </form>
          <?php endif ?>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody></table></div>
</div>
<p class="muted small-text">Members and group accounts are managed in <a href="/admin/members">Members</a>. Two-step login is not available in this version.</p>
