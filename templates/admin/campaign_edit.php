<?php
/** @var array $c @var array $blocks @var array $audience @var array $choices @var ?array $reach @var array $errors @var array $counts @var bool $editable @var array $user @var array $images */
$err = static fn (string $k): ?string => $errors[$k] ?? null;
$types = campaign_block_types();
$imageOpts = ['' => 'Choose an image…'];
foreach ($images as $im) { $imageOpts[(string) $im['id']] = ($im['title'] !== '' ? $im['title'] : $im['original_name']) . ' (#' . $im['id'] . ')'; }
$statusLabel = ['draft' => 'Draft', 'scheduled' => 'Scheduled', 'sending' => 'Sending', 'paused' => 'Paused', 'sent' => 'Sent', 'cancelled' => 'Cancelled'][$c['status']] ?? $c['status'];
?>
<div class="page-head">
  <div><p class="crumbs"><a href="/admin/campaigns">Campaigns</a></p><h1><?= e($c['subject']) ?></h1>
    <p><span class="badge <?= e(['sent' => 'ok', 'sending' => 'members', 'scheduled' => 'scheduled'][$c['status']] ?? 'draft') ?>"><?= e($statusLabel) ?></span>
      <?php if ($c['status'] === 'scheduled'): ?> for <strong class="tabular"><?= e(fmt_datetime($c['send_at'])) ?></strong><?php endif ?>
      <?php if ($c['essential']): ?> <span class="badge warn">Essential notice</span><?php endif ?>
      <?php if (!$editable): ?> · <?= (int) $counts['sent'] ?> of <?= (int) $c['recipient_count'] ?> delivered<?= $counts['failed'] ? ', ' . (int) $counts['failed'] . ' failed' : '' ?><?php endif ?>
      <span class="save-state muted" data-save-state aria-live="polite"></span></p></div>
  <div class="actions">
    <?php if ((int) $c['recipient_count'] > 0): ?><a class="btn ghost" href="/admin/campaigns/<?= (int) $c['id'] ?>/log"><?= icon('clock') ?> Delivery log</a><?php endif ?>
    <form method="post" action="/admin/campaigns/<?= (int) $c['id'] ?>/duplicate"><?= csrf_field() ?><button class="btn ghost" type="submit"><?= icon('layers') ?> Duplicate</button></form>
    <?php if (!in_array($c['status'], ['sending', 'paused'], true)): ?><form method="post" action="/admin/campaigns/<?= (int) $c['id'] ?>/delete" data-confirm="Delete this campaign<?= $c['status'] === 'sent' ? ' and its delivery log' : '' ?>?"><?= csrf_field() ?><button class="btn danger-ghost" type="submit"><?= icon('trash') ?> Delete</button></form><?php endif ?>
  </div>
</div>
<?= form_errors_html($errors) ?>
<?php if ($c['status'] === 'paused'): ?>
  <div class="flash error" role="status"><?= icon('alert') ?><span><strong>Sending is paused.</strong> <?= e($c['pause_reason']) ?></span>
    <form method="post" action="/admin/campaigns/<?= (int) $c['id'] ?>/resume"><?= csrf_field() ?><button class="btn small" type="submit">Resume sending</button></form></div>
<?php endif ?>

<div class="campaign-layout">
  <div class="campaign-main">
  <?php if ($editable): ?>
    <form id="campaign-form" class="campaign-form" method="post" action="/admin/campaigns/<?= (int) $c['id'] ?>" data-campaign-form novalidate>
      <?= csrf_field() ?>
      <button type="submit" name="op" value="save" class="sr-only" tabindex="-1" aria-hidden="true">Save</button>
      <section class="panel"><div class="panel-head"><h2>Message</h2></div><div class="panel-body fields">
        <?= field_html('subject', 'Subject line', 'text', $c['subject'], $err('subject'), ['max' => 200, 'required' => true, 'hint' => 'Use {first_name} to personalise it.']) ?>
        <?= field_html('preheader', 'Preview text', 'text', $c['preheader'], null, ['max' => 200, 'hint' => 'Shown after the subject in most inboxes.']) ?>
        <?= view('admin/_audience', ['audience' => $audience, 'choices' => $choices, 'error' => $err('audience'), 'reach' => $reach, 'essential' => (bool) $c['essential']]) ?>
        <div class="field essential-field">
          <label class="check-line"><input type="checkbox" name="essential" value="1" aria-describedby="essential-warning"<?= $c['essential'] ? ' checked' : '' ?>> <span><strong>Essential membership notice</strong><br><span class="muted">Sent to every active member in the audience, even those who switched news emails off.</span></span></label>
          <div class="notice warn small-text" id="essential-warning"><p><?= icon('alert') ?> <strong>Only for essential membership business:</strong> notice of meetings (such as the AGM), changes to the constitution or governance, membership renewal and similar messages members must receive. Never for news, events, fundraising or promotions: those need the member’s consent.</p>
            <p>Addresses that bounced, complained or unsubscribed from all emails are still left out. The email explains why the member received it, and marking a campaign as essential is recorded in the audit log.</p></div>
        </div>
      </div></section>

      <section class="panel"><div class="panel-head"><h2>Content</h2><span class="muted small-text"><?= count($blocks) ?> block<?= count($blocks) === 1 ? '' : 's' ?></span></div>
        <ol class="block-list">
        <?php foreach ($blocks as $i => $b): $n = $i + 1; $pre = "blocks[$i]"; $last = $i === count($blocks) - 1; ?>
          <li class="block-item" id="block-<?= $i ?>">
            <fieldset>
              <legend><span class="block-num tabular"><?= $n ?></span> <?= e($types[$b['type']]['label']) ?></legend>
              <input type="hidden" name="<?= $pre ?>[type]" value="<?= e($b['type']) ?>">
              <div class="block-tools" role="group" aria-label="Block <?= $n ?> actions">
                <button class="icon-btn small" type="submit" name="op" value="up:<?= $i ?>"<?= $i === 0 ? ' disabled' : '' ?>><?= icon('arrow-up') ?><span class="sr-only">Move block <?= $n ?> up</span></button>
                <button class="icon-btn small" type="submit" name="op" value="down:<?= $i ?>"<?= $last ? ' disabled' : '' ?>><?= icon('arrow-down') ?><span class="sr-only">Move block <?= $n ?> down</span></button>
                <button class="icon-btn small" type="submit" name="op" value="dup:<?= $i ?>"><?= icon('layers') ?><span class="sr-only">Duplicate block <?= $n ?></span></button>
                <button class="icon-btn small danger" type="submit" name="op" value="remove:<?= $i ?>"><?= icon('trash') ?><span class="sr-only">Remove block <?= $n ?></span></button>
              </div>
              <div class="block-fields">
              <?php switch ($b['type']):
                case 'heading': ?>
                  <div class="field-row wide-first">
                    <?= field_html($pre . '[text]', 'Heading', 'text', $b['text'], $err("b$i.text"), ['max' => 200, 'prefix' => "b$i"]) ?>
                    <?= field_html($pre . '[size]', 'Size', 'select', $b['size'], null, ['options' => ['large' => 'Large', 'medium' => 'Medium'], 'prefix' => "b$i"]) ?>
                  </div>
                <?php break; case 'text': ?>
                  <div class="field"><span class="label" id="b<?= $i ?>-lbl">Text</span>
                    <div class="rt" data-rt>
                      <div class="rt-editor" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="b<?= $i ?>-lbl" hidden><?= \Core\Sanitizer::clean($b['html']) ?></div>
                      <textarea name="<?= $pre ?>[html]" data-richtext rows="6" aria-labelledby="b<?= $i ?>-lbl"><?= e($b['html']) ?></textarea>
                    </div>
                    <?php if ($err("b$i.html")): ?><p class="error"><?= e($err("b$i.html")) ?></p><?php endif ?>
                    <p class="hint">{first_name} is replaced with each person’s first name.</p>
                  </div>
                <?php break; case 'image': $m = $b['media_id'] ? media_find((int) $b['media_id']) : null; ?>
                  <div class="block-image">
                    <div class="media-preview"><?= $m ? img_html($m, '12rem', '', '', true, 400) : '<span>No image</span>' ?></div>
                    <div class="fields">
                      <?= field_html($pre . '[media_id]', 'Image', 'select', (string) ($b['media_id'] ?? ''), $err("b$i.media_id"), ['options' => $imageOpts, 'prefix' => "b$i", 'hint' => 'Public images from the media library.']) ?>
                      <?= field_html($pre . '[alt]', 'Description for screen readers', 'text', $b['alt'], null, ['max' => 300, 'prefix' => "b$i"]) ?>
                      <?= field_html($pre . '[link]', 'Link (optional)', 'text', $b['link'], $err("b$i.link"), ['max' => 500, 'prefix' => "b$i", 'placeholder' => 'https://… or /page']) ?>
                    </div>
                  </div>
                <?php break; case 'button': ?>
                  <div class="field-row">
                    <?= field_html($pre . '[label]', 'Button text', 'text', $b['label'], $err("b$i.label"), ['max' => 60, 'prefix' => "b$i"]) ?>
                    <?= field_html($pre . '[url]', 'Link', 'text', $b['url'], $err("b$i.url"), ['max' => 500, 'prefix' => "b$i", 'placeholder' => 'https://… or /page']) ?>
                  </div>
                <?php break; case 'divider': ?>
                  <p class="muted small-text">A thin line between sections.</p>
                <?php break; case 'latest_posts': ?>
                  <div class="field-row">
                    <?= field_html($pre . '[count]', 'How many', 'number', (string) $b['count'], null, ['min' => 1, 'max' => 6, 'prefix' => "b$i"]) ?>
                    <?= field_html($pre . '[base]', 'From', 'select', $b['base'], null, ['options' => ['' => 'All posts', 'news' => 'News', 'blog' => 'Blog', 'projects' => 'Projects'], 'prefix' => "b$i"]) ?>
                  </div>
                  <p class="hint">The newest public posts at the moment the campaign is sent.</p>
                <?php break; case 'upcoming_events': ?>
                  <?= field_html($pre . '[count]', 'How many', 'number', (string) $b['count'], null, ['min' => 1, 'max' => 6, 'prefix' => "b$i", 'hint' => 'The next public events at the moment the campaign is sent.']) ?>
              <?php endswitch ?>
              </div>
            </fieldset>
          </li>
        <?php endforeach ?>
        </ol>
        <?php if (!$blocks): ?><p class="panel-body muted">No content yet: add a block below.</p><?php endif ?>
        <div class="panel-body add-block">
          <label for="add-type">Add a block</label>
          <select id="add-type" name="add_type"><?php foreach ($types as $k => $t): ?><option value="<?= e($k) ?>"><?= e($t['label']) ?></option><?php endforeach ?></select>
          <button class="btn ghost" type="submit" name="op" value="add"><?= icon('plus') ?> Add</button>
        </div>
      </section>
      <div class="form-actions sticky-actions"><button class="btn accent" type="submit" name="op" value="save"><?= icon('check') ?> Save draft</button></div>
    </form>
  <?php else: ?>
    <section class="panel"><div class="panel-body">
      <p>This campaign was <?= $c['status'] === 'sending' ? 'queued' : 'sent' ?> on <strong class="tabular"><?= e(fmt_datetime($c['queued_at'])) ?></strong> to <strong><?= e(audience_label($audience)) ?></strong>. Sent campaigns cannot be edited; duplicate it to send a new version.</p>
      <dl class="mini-stats tabular">
        <div><dt>Recipients</dt><dd><?= (int) $c['recipient_count'] ?></dd></div>
        <div><dt>Delivered</dt><dd><?= (int) $counts['sent'] ?></dd></div>
        <div><dt>Waiting</dt><dd><?= (int) $counts['queued'] + (int) $counts['sending'] ?></dd></div>
        <div><dt>Failed</dt><dd><?= (int) $counts['failed'] ?></dd></div>
      </dl>
    </div></section>
  <?php endif ?>
  </div>

  <aside class="campaign-side">
    <?php if ($editable): ?>
    <section class="panel">
      <div class="panel-head"><h2>Send</h2></div>
      <div class="panel-body fields">
        <?php if ($c['status'] === 'scheduled'): ?>
          <p class="notice">Scheduled for <strong class="tabular"><?= e(fmt_datetime($c['send_at'])) ?></strong>. Changes you save are included.</p>
          <form method="post" action="/admin/campaigns/<?= (int) $c['id'] ?>/cancel"><?= csrf_field() ?><button class="btn ghost" type="submit">Cancel schedule</button></form>
        <?php else: ?>
          <form method="post" action="/admin/campaigns/<?= (int) $c['id'] ?>/send" data-confirm="Send this campaign now to <?= count($reach['recipients'] ?? []) ?> recipients? This cannot be undone." data-save-first>
            <?= csrf_field() ?><input type="hidden" name="mode" value="now">
            <button class="btn accent wide" type="submit"><?= icon('mail') ?> Send now</button>
          </form>
          <form method="post" action="/admin/campaigns/<?= (int) $c['id'] ?>/send" class="schedule-form" data-save-first>
            <?= csrf_field() ?><input type="hidden" name="mode" value="schedule">
            <?= field_html('send_at', 'Or schedule for (' . site_tz()->getName() . ')', 'datetime-local', '', null, ['prefix' => 'sch']) ?>
            <button class="btn ghost" type="submit"><?= icon('clock') ?> Schedule</button>
          </form>
        <?php endif ?>
        <form method="post" action="/admin/campaigns/<?= (int) $c['id'] ?>/test" class="test-form" data-save-first>
          <?= csrf_field() ?>
          <?= field_html('to', 'Send a test to', 'email', (string) $user['email'], null, ['prefix' => 'tst']) ?>
          <button class="btn ghost" type="submit"><?= icon('mail') ?> Send test</button>
        </form>
      </div>
    </section>
    <?php endif ?>
    <section class="panel email-preview-panel">
      <div class="panel-head"><h2>Preview</h2>
        <div class="segmented small" role="group" aria-label="Preview width"><button type="button" aria-pressed="true" data-preview-width="100%">Desktop</button><button type="button" aria-pressed="false" data-preview-width="375px">Mobile</button></div></div>
      <div class="email-frame-wrap"><iframe class="email-frame" src="/admin/campaigns/<?= (int) $c['id'] ?>/preview" title="Email preview" data-preview-frame></iframe></div>
      <p class="panel-body hint muted">Shows the saved version, personalised for you.</p>
    </section>
  </aside>
</div>
<script type="module" src="<?= e(asset('assets/js/campaigns.js')) ?>"></script>
