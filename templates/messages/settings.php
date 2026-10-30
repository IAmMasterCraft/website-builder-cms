<?php /** @var array $s @var array $errors @var array $staff @var array $team_staff */ ?>
<div class="page-head"><div><h1>Message settings</h1><p>Who members can message, the Team inbox and how long messages are kept.</p></div></div>
<form class="panel" method="post" action="/admin/settings/messages">
  <?= csrf_field() ?>
  <div class="panel-body fields">
    <fieldset class="field"><legend>Members</legend>
      <label class="check-line"><input type="checkbox" name="member_to_member" value="1"<?= $s['member_to_member'] ? ' checked' : '' ?>> <span><strong>Members can message each other</strong><br><span class="muted">When off, members can still message the team. New members (first 24 hours) can only message the team either way.</span></span></label>
    </fieldset>
    <fieldset class="field"><legend>Messages to the organisation</legend>
      <label class="check-line"><input type="checkbox" name="team_inbox" value="1"<?= $s['team_inbox'] ? ' checked' : '' ?>> <span><strong>Team inbox</strong><br><span class="muted">Members can write to “the team”; the message goes to Messages → Team inbox, where any admin with the Messages permission can answer<?= $team_staff !== [] ? ' (now: ' . e(implode(', ', array_map('msg_account_name', array_slice(array_values($team_staff), 0, 6)))) . (count($team_staff) > 6 ? '…' : '') . ')' : '' ?>.</span></span></label>
      <p class="hint">Admins members can also message by name (as well as, or instead of, the Team inbox):</p>
      <?php foreach ($staff as $a): ?>
        <label class="check-line"><input type="checkbox" name="team_admins[]" value="<?= (int) $a['id'] ?>"<?= in_array((int) $a['id'], $s['team_admins'], true) ? ' checked' : '' ?>> <span><?= e($a['display_name']) ?> <span class="muted"><?= e(str_replace('_', ' ', $a['role'])) ?></span></span></label>
      <?php endforeach ?>
    </fieldset>
    <div class="field">
      <label for="retention_months">Delete messages older than (months)</label>
      <input type="number" id="retention_months" name="retention_months" min="1" max="120" value="<?= $s['retention_months'] > 0 ? (int) $s['retention_months'] : '' ?>"<?= isset($errors['retention_months']) ? ' aria-invalid="true" aria-describedby="ret-err"' : '' ?> aria-describedby="ret-hint">
      <?php if (isset($errors['retention_months'])): ?><p class="error" id="ret-err"><?= e($errors['retention_months']) ?></p><?php endif ?>
      <p class="hint" id="ret-hint">Leave empty to keep messages forever (default). Old messages and their attachments are deleted every night.</p>
    </div>
    <div class="form-actions"><button class="btn accent" type="submit"><?= icon('check') ?> Save settings</button></div>
  </div>
</form>
