<?php /** @var array $s @var array $errors @var array $staff @var array $recipients @var string $time */
$chosenErr = $errors['chosen'] ?? null;
?>
<div class="page-head"><div><h1>Birthday notices</h1><p>Emails to admins about members’ birthdays. They are sent at <?= e($time) ?> (site time; change it in <a href="/admin/settings/email">Email settings</a>). The birth year and age are never included.</p></div>
  <div class="actions"><a class="btn ghost" href="/admin/members/birthdays"><?= icon('gift') ?> Birthday list</a></div></div>
<form class="panel" method="post" action="/admin/settings/birthdays">
  <?= csrf_field() ?>
  <div class="panel-body fields">
    <fieldset class="field"><legend>Which emails</legend>
      <label class="check-line"><input type="checkbox" name="daily" value="1"<?= $s['daily'] ? ' checked' : '' ?>> <span><strong>Daily</strong><br><span class="muted">Today’s birthdays (and the next 7 days), every day.</span></span></label>
      <label class="check-line"><input type="checkbox" name="weekly" value="1"<?= $s['weekly'] ? ' checked' : '' ?>> <span><strong>Weekly</strong><br><span class="muted">On Mondays: every birthday from Monday to Sunday.</span></span></label>
      <label class="check-line"><input type="checkbox" name="monthly" value="1"<?= $s['monthly'] ? ' checked' : '' ?>> <span><strong>Monthly</strong><br><span class="muted">On the 1st: every birthday in the month, grouped by week.</span></span></label>
      <label class="check-line"><input type="checkbox" name="skip_empty" value="1"<?= $s['skip_empty'] ? ' checked' : '' ?>> <span><strong>Skip the email when there are no birthdays</strong></span></label>
    </fieldset>
    <fieldset class="field"<?= $chosenErr ? ' aria-describedby="chosen-err"' : '' ?>><legend>Who receives them</legend>
      <label class="check-line"><input type="radio" name="recipients" value="members"<?= $s['recipients'] === 'members' ? ' checked' : '' ?>> <span><strong>Every admin who can manage members</strong><br><span class="muted">Super admins and admins with the Members permission.</span></span></label>
      <label class="check-line"><input type="radio" name="recipients" value="chosen"<?= $s['recipients'] === 'chosen' ? ' checked' : '' ?>> <span><strong>Only these people</strong></span></label>
      <div class="indent">
        <?php foreach ($staff as $a): ?>
          <label class="check-line"><input type="checkbox" name="chosen[]" value="<?= (int) $a['id'] ?>"<?= in_array((int) $a['id'], $s['chosen'], true) ? ' checked' : '' ?>> <span><?= e($a['display_name']) ?> <span class="muted"><?= e(str_replace('_', ' ', $a['role'])) ?></span></span></label>
        <?php endforeach ?>
      </div>
      <?php if ($chosenErr): ?><p class="error" id="chosen-err"><?= e($chosenErr) ?></p><?php endif ?>
    </fieldset>
    <p class="hint">Now going to: <?= $recipients ? e(implode(', ', array_column($recipients, 'display_name'))) : 'nobody' ?>. Admins whose address is on the suppression list are skipped. Edit the wording under <a href="/admin/email-templates">Email templates</a> (Birthday notice: daily, weekly, monthly).</p>
    <div class="form-actions"><button class="btn accent" type="submit"><?= icon('check') ?> Save settings</button></div>
  </div>
</form>
