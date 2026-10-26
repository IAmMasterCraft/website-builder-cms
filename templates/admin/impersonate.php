<?php /** @var array $target @var list<string> $warnings @var array $values @var array $errors @var bool $notify @var bool $can_members */
$isGroup = $target['role'] === 'group';
$back = $target['admin_url'] !== null && $can_members ? $target['admin_url'] : '/admin';
?>
<div class="page-head">
  <div>
    <?php if ($target['admin_url'] !== null && $can_members): ?><p class="crumbs"><a href="<?= e($isGroup ? '/admin/groups' : '/admin/members') ?>"><?= $isGroup ? 'Groups' : 'Members' ?></a> / <a href="<?= e($target['admin_url']) ?>"><?= e($target['name']) ?></a> /</p><?php endif ?>
    <h1>Log in as <?= e($target['name']) ?></h1>
    <p><?= $target['number'] !== '' ? '<span class="tabular">' . e($target['number']) . '</span> · ' : '' ?><span class="badge <?= e($target['status']) ?>"><?= e($target['status']) ?></span><?= $can_members ? ' · ' . e($target['email']) : '' ?></p>
  </div>
</div>
<div class="form-layout">
  <form class="panel" method="post" action="/admin/impersonate/<?= (int) $target['id'] ?>" novalidate>
    <?= csrf_field() ?>
    <div class="panel-head"><h2>View the site as this <?= $isGroup ? 'group' : 'member' ?></h2></div>
    <div class="panel-body fields">
      <p>You will see the site exactly as <?= e($target['name']) ?> sees it: their dashboard, profile, card, events, jobs and notices. A bar at the bottom of every page shows who you are viewing as, with <strong>Return to admin</strong>.</p>
      <ul class="plain-list">
        <li><?= icon('clock') ?> It ends automatically after 30 minutes.</li>
        <li><?= icon('lock') ?> You cannot change their password or email, delete the account, download their data, change email consent, or like, comment, report or post jobs as them. The admin area is closed until you return.</li>
        <li><?= icon('history') ?> The start, the end and any change you make are recorded in the audit log under your name.</li>
        <?php if ($notify): ?><li><?= icon('mail') ?> <?= e($target['first_name']) ?> gets a short email saying an administrator viewed their account.</li><?php endif ?>
      </ul>
      <?php foreach ($warnings as $w): ?><div class="flash warn" role="note"><?= icon('warning') ?><span><?= e($w) ?></span></div><?php endforeach ?>
      <?php if (isset($errors['form'])): ?><p class="error" id="imp-err"><?= e($errors['form']) ?></p><?php endif ?>
      <?= field_html('reason', 'Reason (optional, saved in the audit log)', 'text', (string) ($values['reason'] ?? ''), null, ['max' => 200, 'prefix' => 'imp', 'hint' => 'For example: “Member cannot find their card”.']) ?>
      <label class="check"><input type="checkbox" name="allow_changes" value="1"<?= ($values['allow_changes'] ?? '') === '1' ? ' checked' : '' ?>> Allow changes: edit their profile and photo, and register for or cancel events on their behalf (each change is logged)</label>
      <?php if ($warnings): ?><label class="check"><input type="checkbox" name="confirm" value="1" required<?= isset($errors['form']) ? ' aria-describedby="imp-err"' : '' ?>> I understand, view this account anyway</label><?php endif ?>
      <div class="form-actions btn-row">
        <button class="btn accent" type="submit"><?= icon('eye') ?> Log in as this <?= $isGroup ? 'group' : 'member' ?></button>
        <a class="btn ghost" href="<?= e($back) ?>">Cancel</a>
      </div>
    </div>
  </form>
</div>
