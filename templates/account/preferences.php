<?php /** @var array $prefs @var bool $is_member @var bool $message_emails @var bool $message_findable */ ?>
<section class="dash-card narrow-card">
  <form class="form" method="post" action="/account/preferences">
    <?= csrf_field() ?>
    <fieldset class="field"><legend>Emails</legend>
      <label class="check-line"><input type="checkbox" name="newsletters" value="1"<?= $prefs['newsletters'] ? ' checked' : '' ?>> <span><strong>News and event updates</strong><br><span class="muted">Occasional newsletters and invitations.</span></span></label>
      <label class="check-line"><input type="checkbox" name="message_emails" value="1"<?= !empty($message_emails) ? ' checked' : '' ?>> <span><strong>Message notifications</strong><br><span class="muted">An email when a message has waited unread for 15 minutes (one per conversation).</span></span></label>
      <?php if ($is_member): ?>
      <label class="check-line"><input type="checkbox" name="birthday_greeting" value="1"<?= $prefs['birthday_greeting'] ? ' checked' : '' ?>> <span><strong>Birthday greeting</strong><br><span class="muted">A short email on your birthday.</span></span></label>
      <?php endif ?>
    </fieldset>
    <fieldset class="field"><legend>Messages</legend>
      <label class="check-line"><input type="checkbox" name="message_findable" value="1"<?= !empty($message_findable) ? ' checked' : '' ?>> <span><strong>Others can find me in messages</strong><br><span class="muted">Members can find you by name or member number when they start a new message. When off, only people you already have a conversation with, and the organisation’s admins, can message you.</span></span></label>
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
