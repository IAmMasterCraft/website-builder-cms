<?php /** @var array $values @var array $errors @var bool $has_password @var array $counts @var array $user */
$v = static fn (string $k): string => (string) ($values[$k] ?? '');
$err = static fn (string $k): ?string => $errors[$k] ?? null;
?>
<div class="page-head"><div><h1>Email settings</h1><p>How emails are sent, who they come from, and how fast campaigns go out.</p></div>
  <div class="actions"><a class="btn ghost" href="/admin/email-log"><?= icon('clock') ?> Delivery log</a></div></div>
<?= form_errors_html($errors) ?>
<div class="form-layout">
  <form method="post" action="/admin/settings/email" novalidate>
    <?= csrf_field() ?>
    <section class="panel"><div class="panel-head"><h2>Sender</h2></div><div class="panel-body fields">
      <div class="field-row">
        <?= field_html('from_name', 'Sender name', 'text', $v('from_name'), $err('from_name'), ['max' => 120, 'required' => true]) ?>
        <?= field_html('from_email', 'Sender address', 'email', $v('from_email'), $err('from_email'), ['required' => true, 'hint' => 'Use an address on your own domain, set up for your SMTP server.']) ?>
      </div>
    </div></section>
    <section class="panel"><div class="panel-head"><h2>Delivery</h2></div><div class="panel-body fields">
      <fieldset class="field"><legend>Send emails with</legend>
        <div class="segmented">
          <label><input type="radio" name="driver" value="smtp"<?= $v('driver') === 'smtp' ? ' checked' : '' ?>> SMTP server</label>
          <label><input type="radio" name="driver" value="file"<?= $v('driver') === 'file' ? ' checked' : '' ?>> Save to files (development)</label>
        </div>
        <p class="hint">“Save to files” writes each email to <code>storage/mail/</code> instead of sending it. Use it only while testing.</p>
        <?php if ($err('driver')): ?><p class="error"><?= e($err('driver')) ?></p><?php endif ?>
      </fieldset>
      <div class="field-row">
        <?= field_html('host', 'SMTP server', 'text', $v('host'), $err('host'), ['placeholder' => 'smtp.example.org']) ?>
        <?= field_html('port', 'Port', 'number', $v('port'), $err('port'), ['min' => 1, 'max' => 65535]) ?>
      </div>
      <?= field_html('encryption', 'Encryption', 'select', $v('encryption'), $err('encryption'), ['options' => ['tls' => 'STARTTLS (port 587)', 'ssl' => 'SSL/TLS (port 465)', 'none' => 'None (not recommended)']]) ?>
      <div class="field-row">
        <?= field_html('username', 'User name', 'text', $v('username'), $err('username'), ['autocomplete' => 'off']) ?>
        <?= field_html('password', 'Password', 'password', '', $err('password'), ['autocomplete' => 'new-password', 'hint' => $has_password ? 'A password is saved (encrypted). Leave empty to keep it.' : 'Stored encrypted in the database.']) ?>
      </div>
      <?php if ($has_password): ?><label class="check"><input type="checkbox" name="password_clear" value="1"> Remove the saved password</label><?php endif ?>
    </div></section>
    <section class="panel"><div class="panel-head"><h2>Sending pace and schedule</h2></div><div class="panel-body fields">
      <div class="field-row">
        <?= field_html('per_minute', 'Emails per minute', 'number', $v('per_minute'), $err('per_minute'), ['min' => 1, 'max' => 10000, 'hint' => 'Campaigns and queued emails are sent by cron in batches up to this many per minute. Check your provider’s limit.']) ?>
        <?= field_html('birthday_time', 'Birthday emails at', 'time', $v('birthday_time'), $err('birthday_time'), ['hint' => 'Daily, in the site time zone (' . site_tz()->getName() . ').']) ?>
      </div>
    </div></section>
    <div class="form-actions"><button class="btn accent" type="submit"><?= icon('check') ?> Save email settings</button></div>
  </form>
  <div class="side">
    <form class="panel" method="post" action="/admin/settings/email/test">
      <?= csrf_field() ?>
      <div class="panel-head"><h2>Send a test email</h2></div>
      <div class="panel-body fields">
        <p class="muted">Uses the saved settings. Save first if you changed anything.</p>
        <?= field_html('to', 'Send to', 'email', (string) $user['email'], null, ['prefix' => 't']) ?>
        <button class="btn ghost" type="submit"><?= icon('mail') ?> Send test email</button>
      </div>
    </form>
    <section class="panel"><div class="panel-head"><h2>Queue</h2></div><div class="panel-body">
      <dl class="mini-stats tabular">
        <div><dt>Waiting</dt><dd><?= (int) $counts['queued'] ?></dd></div>
        <div><dt>Sent</dt><dd><?= (int) $counts['sent'] ?></dd></div>
        <div><dt>Failed</dt><dd><?= (int) $counts['failed'] ?></dd></div>
      </dl>
      <p class="hint muted">Cron sends the queue every minute: <code>* * * * * php <?= e(BASE) ?>/bin/cron</code></p>
    </div></section>
  </div>
</div>
