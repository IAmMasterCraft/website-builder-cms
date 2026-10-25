<?php /** @var array $values @var array $errors @var bool $has_password @var array $counts @var array $user @var array $bounce @var array $bounce_errors @var array $bounce_saved @var array $bounce_state @var array $webhook_urls @var array $webhook_on @var int $unparsed_local @var string $cron_line */
$v = static fn (string $k): string => (string) ($values[$k] ?? '');
$err = static fn (string $k): ?string => $errors[$k] ?? null;
?>
<div class="page-head"><div><h1>Email settings</h1><p>How emails are sent, who they come from, and how fast campaigns go out.</p></div>
  <div class="actions"><a class="btn ghost" href="/admin/email-log"><?= icon('clock') ?> Delivery log</a><a class="btn ghost" href="/admin/email-suppressions"><?= icon('alert') ?> Suppression list</a></div></div>
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
    <form class="panel" id="test" method="post" action="/admin/settings/email/test">
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
      <p class="hint muted">Cron sends the queue every minute: <code><?= e($cron_line) ?></code></p>
    </div></section>
  </div>
</div>

<?php
$bv = static fn (string $k): string => (string) ($bounce[$k] ?? '');
$be = static fn (string $k): ?string => $bounce_errors[$k] ?? null;
$secretHint = static fn (string $k, string $what): string => !empty($bounce_saved[$k]) ? $what . ' is saved (encrypted). Leave empty to keep it.' : 'Stored encrypted.';
?>
<form class="bounce-settings" id="bounces" method="post" action="/admin/settings/email/bounces" novalidate>
  <?= csrf_field() ?>
  <?= form_errors_html($bounce_errors) ?>
  <section class="panel"><div class="panel-head"><h2>Bounces and complaints</h2></div><div class="panel-body fields">
    <p class="muted">Protects your sending reputation: addresses that bounce or complain are suppressed automatically. Set a dedicated bounce address on your own domain so every bounce maps to exactly one email, then read that mailbox from here or connect your provider’s webhooks.</p>
    <p class="hint">Deliverability also needs DNS records for your sending domain: <strong>SPF</strong> (which servers may send for it), <strong>DKIM</strong> (your provider signs each email) and <strong>DMARC</strong> (what receivers do with mail that fails both). Your email provider gives you the exact records to add.</p>
    <div class="field-row">
      <?= field_html('bounce_address', 'Bounce address (Return-Path)', 'email', $bv('bounce_address'), $be('bounce_address'), ['placeholder' => 'bounces@yourdomain.org', 'hint' => 'Each email is sent with a signed per-message address such as bounces+c123-…@yourdomain.org. The mailbox must accept “+” addresses (most do). Leave empty to use the sender address.']) ?>
      <?= field_html('per_domain_minute', 'Emails per minute to one domain', 'number', $bv('per_domain_minute'), $be('per_domain_minute'), ['min' => 0, 'max' => 10000, 'hint' => 'For example at most 30 a minute to gmail.com, so a large list never looks like abuse. 0 = no limit.']) ?>
    </div>
  </div></section>
  <div class="form-layout">
    <section class="panel"><div class="panel-head"><h2>Bounce mailbox</h2></div><div class="panel-body fields">
      <fieldset class="field"><legend>Read the bounce mailbox</legend>
        <div class="segmented">
          <?php foreach (['off' => 'Off', 'imap' => 'IMAP', 'pop3' => 'POP3'] as $k => $l): ?><label><input type="radio" name="mb_protocol" value="<?= e($k) ?>"<?= $bv('mb_protocol') === $k ? ' checked' : '' ?>> <?= e($l) ?></label><?php endforeach ?>
        </div>
        <p class="hint">Cron reads new messages every 5 minutes over an encrypted connection, records bounces and spam reports, and deletes what it processed so the mailbox never fills up. No PHP extension is needed.</p>
        <?php if ($be('mb_protocol')): ?><p class="error"><?= e((string) $be('mb_protocol')) ?></p><?php endif ?>
      </fieldset>
      <div class="field-row">
        <?= field_html('mb_host', 'Mail server', 'text', $bv('mb_host'), $be('mb_host'), ['placeholder' => 'imap.example.org']) ?>
        <?= field_html('mb_port', 'Port', 'number', $bv('mb_port'), $be('mb_port'), ['min' => 1, 'max' => 65535, 'hint' => 'IMAP 993 or POP3 995 (SSL/TLS); 143 or 110 with STARTTLS.']) ?>
      </div>
      <?= field_html('mb_encryption', 'Encryption', 'select', $bv('mb_encryption'), $be('mb_encryption'), ['options' => ['ssl' => 'SSL/TLS', 'starttls' => 'STARTTLS']]) ?>
      <div class="field-row">
        <?= field_html('mb_username', 'User name', 'text', $bv('mb_username'), $be('mb_username'), ['autocomplete' => 'off']) ?>
        <?= field_html('mb_password', 'Password', 'password', '', null, ['autocomplete' => 'new-password', 'hint' => $secretHint('mb_password', 'A password')]) ?>
      </div>
      <div class="field-row">
        <?= field_html('mb_folder', 'Folder to read (IMAP)', 'text', $bv('mb_folder'), $be('mb_folder'), ['max' => 100]) ?>
        <?= field_html('mb_aside_folder', 'Folder for messages it could not read (IMAP)', 'text', $bv('mb_aside_folder'), $be('mb_aside_folder'), ['max' => 100, 'hint' => 'With POP3 they are kept on this server instead.']) ?>
      </div>
      <div class="field-row">
        <?= field_html('mb_max_messages', 'Keep at most this many set-aside messages', 'number', $bv('mb_max_messages'), $be('mb_max_messages'), ['min' => 10, 'max' => 100000]) ?>
        <?= field_html('mb_max_age_days', '…for at most (days)', 'number', $bv('mb_max_age_days'), $be('mb_max_age_days'), ['min' => 1, 'max' => 3650]) ?>
      </div>
      <dl class="mini-stats tabular">
        <div><dt>Last check</dt><dd><?= $bounce_state['last_poll'] > 0 ? e(fmt_datetime(gmdate('Y-m-d H:i:s', $bounce_state['last_poll']))) : 'never' ?></dd></div>
        <div><dt>Processed</dt><dd><?= (int) $bounce_state['processed'] ?></dd></div>
        <div><dt>Set aside</dt><dd><?= (int) $bounce_state['unparsed'] ?></dd></div>
      </dl>
      <?php if ($bounce_state['last_error'] !== '' && $bounce_state['last_error'] !== 'off'): ?><p class="error"><?= icon('alert') ?> Last check failed: <?= e($bounce_state['last_error']) ?></p><?php elseif ($bounce_state['last_result'] !== ''): ?><p class="hint muted">Last check: <?= e($bounce_state['last_result']) ?>.</p><?php endif ?>
    </div></section>
    <section class="panel"><div class="panel-head"><h2>Provider webhooks</h2></div><div class="panel-body fields">
      <p class="muted">If you send through a provider, let it tell this site about bounces and complaints. Each endpoint only accepts signed or authenticated requests.</p>
      <div class="table-wrap"><table class="data">
        <thead><tr><th scope="col">Provider</th><th scope="col">Webhook address</th></tr></thead>
        <tbody>
        <?php foreach (['ses' => 'Amazon SES (SNS)', 'mailgun' => 'Mailgun', 'postmark' => 'Postmark', 'sendgrid' => 'SendGrid', 'brevo' => 'Brevo'] as $k => $l): ?>
          <tr><td><?= e($l) ?><br><span class="badge <?= $webhook_on[$k] ? 'ok' : '' ?>"><?= $webhook_on[$k] ? 'on' : 'off' ?></span></td><td><code class="small-text"><?= e($webhook_urls[$k]) ?></code></td></tr>
        <?php endforeach ?>
        </tbody></table></div>
      <label class="check"><input type="checkbox" name="wh_ses" value="1"<?= $bv('wh_ses') === '1' ? ' checked' : '' ?>> Accept Amazon SES notifications through SNS</label>
      <?= field_html('wh_ses_topics', 'Allowed SNS topic ARNs (one per line)', 'textarea', $bv('wh_ses_topics'), $be('wh_ses_topics'), ['hint' => 'The SNS signature is checked against Amazon’s certificate (fetched only from sns.<region>.amazonaws.com); the subscription is confirmed automatically for these topics.']) ?>
      <?= field_html('wh_mailgun_key', 'Mailgun webhook signing key', 'password', '', null, ['autocomplete' => 'off', 'hint' => $secretHint('wh_mailgun_key', 'A key')]) ?>
      <div class="field-row">
        <?= field_html('wh_postmark_user', 'Postmark basic-auth user', 'text', $bv('wh_postmark_user'), null, ['autocomplete' => 'off']) ?>
        <?= field_html('wh_postmark_password', 'Postmark basic-auth password', 'password', '', null, ['autocomplete' => 'new-password', 'hint' => $secretHint('wh_postmark_password', 'A password')]) ?>
      </div>
      <?= field_html('wh_postmark_token', 'Or a Postmark token (custom header X-Gather-Webhook-Token)', 'password', '', null, ['autocomplete' => 'off', 'hint' => $secretHint('wh_postmark_token', 'A token')]) ?>
      <?= field_html('wh_sendgrid_key', 'SendGrid signed event webhook verification key', 'textarea', $bv('wh_sendgrid_key'), $be('wh_sendgrid_key'), ['hint' => 'The public key shown when you switch on “Signed Event Webhook” (not a secret).']) ?>
      <?= field_html('wh_brevo_token', 'Brevo webhook token (sent as a bearer token)', 'password', '', null, ['autocomplete' => 'off', 'hint' => $secretHint('wh_brevo_token', 'A token')]) ?>
    </div></section>
  </div>
  <div class="form-actions"><button class="btn accent" type="submit"><?= icon('check') ?> Save bounce settings</button></div>
</form>
<form class="panel" method="post" action="/admin/settings/email/bounces/poll">
  <?= csrf_field() ?>
  <div class="panel-body btn-row"><button class="btn ghost" type="submit"><?= icon('mail') ?> Check the bounce mailbox now</button>
    <?php if ($unparsed_local > 0): ?><span class="muted small-text"><?= (int) $unparsed_local ?> unreadable message(s) kept in storage/bounces/</span><?php endif ?></div>
</form>
