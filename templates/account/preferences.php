<?php /** @var array $prefs @var bool $is_member */ ?>
<section class="dash-card narrow-card">
  <form class="form" method="post" action="/account/preferences">
    <?= csrf_field() ?>
    <fieldset class="field"><legend>Emails</legend>
      <label class="check-line"><input type="checkbox" name="newsletters" value="1"<?= $prefs['newsletters'] ? ' checked' : '' ?>> <span><strong>News and event updates</strong><br><span class="muted">Occasional newsletters and invitations.</span></span></label>
      <?php if ($is_member): ?>
      <label class="check-line"><input type="checkbox" name="birthday_greeting" value="1"<?= $prefs['birthday_greeting'] ? ' checked' : '' ?>> <span><strong>Birthday greeting</strong><br><span class="muted">A short email on your birthday.</span></span></label>
      <?php endif ?>
    </fieldset>
    <?php if ($is_member): ?>
    <fieldset class="field"><legend>Privacy</legend>
      <label class="check-line"><input type="checkbox" name="celebrants" value="1"<?= $prefs['celebrants'] ? ' checked' : '' ?>> <span><strong>Show me in the birthday celebrants list</strong><br><span class="muted">Your first name and last initial appear on your birthday. Your age is never shown.</span></span></label>
    </fieldset>
    <?php endif ?>
    <p class="hint muted">Important messages about your account (like password resets) are always sent.</p>
    <p><button class="btn accent" type="submit"><?= icon('check') ?> Save preferences</button></p>
  </form>
</section>
