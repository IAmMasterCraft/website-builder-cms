<?php /** B2: shown on every member-facing page while an admin views the site as a member. */
$imp = impersonation_banner();
if ($imp !== null): ?>
<div class="imp-banner" role="region" aria-label="Viewing as a member">
  <div class="imp-inner">
    <p><?= icon('eye') ?><span>You are viewing as <strong><?= e($imp['name']) ?></strong><?= $imp['number'] !== '' ? ' (<span class="tabular">' . e($imp['number']) . '</span>)' : '' ?>. <span class="imp-meta"><?= $imp['allow_changes'] ? 'Changes allowed' : 'Read only' ?> · ends at <time datetime="<?= e($imp['ends_iso']) ?>"><?= e($imp['ends']) ?></time></span></span></p>
    <form method="post" action="/impersonation/stop"><?= csrf_field() ?><button type="submit" class="imp-return"><?= icon('arrow-right') ?> Return to admin</button></form>
  </div>
</div>
<?php endif ?>
