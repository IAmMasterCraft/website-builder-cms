<?php /** @var ?array $n @var array $values @var array $errors @var array $audience @var array $choices @var array $reach @var array $reach_email @var int $reads */
$err = static fn (string $k): ?string => $errors[$k] ?? null;
$action = $n ? '/admin/notices/' . (int) $n['id'] : '/admin/notices';
$published = $n && $n['status'] === 'published';
?>
<div class="page-head"><div><p class="crumbs"><a href="/admin/notices">Notices</a></p><h1><?= $n ? e($n['title']) : 'New notice' ?></h1>
  <?php if ($n): ?><p><span class="badge <?= $published ? 'published' : 'draft' ?>"><?= e($n['status']) ?></span><?= $published ? ' · read by ' . (int) $reads . ' of ' . count($reach['recipients']) : '' ?><?= $n['emailed_at'] ? ' · email copies queued ' . e(fmt_datetime($n['emailed_at'])) : '' ?></p><?php endif ?></div>
  <?php if ($n): ?><div class="actions"><form method="post" action="/admin/notices/<?= (int) $n['id'] ?>/delete" data-confirm="Delete this notice?"><?= csrf_field() ?><button class="btn danger-ghost" type="submit"><?= icon('trash') ?> Delete</button></form></div><?php endif ?>
</div>
<?= form_errors_html($errors) ?>
<form method="post" action="<?= e($action) ?>" novalidate>
  <?= csrf_field() ?>
  <div class="form-layout">
    <section class="panel"><div class="panel-body fields">
      <?= field_html('title', 'Title', 'text', $values['title'] ?? '', $err('title'), ['max' => 200, 'required' => true]) ?>
      <div class="field"><label for="f-body">Message</label>
        <div class="rt" data-rt>
          <div class="rt-editor" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="f-body-lbl" hidden><?= \Core\Sanitizer::clean((string) ($values['body'] ?? '')) ?></div>
          <textarea id="f-body" name="body" data-richtext rows="10"<?= $err('body') ? ' aria-invalid="true" aria-describedby="f-body-err"' : '' ?>><?= e((string) ($values['body'] ?? '')) ?></textarea>
        </div><span id="f-body-lbl" class="sr-only">Message editor</span>
        <?php if ($err('body')): ?><p class="error" id="f-body-err"><?= e($err('body')) ?></p><?php endif ?>
      </div>
    </div></section>
    <div class="side">
      <section class="panel"><div class="panel-body fields">
        <?= view('admin/_audience', ['audience' => $audience, 'choices' => $choices, 'error' => $err('audience'), 'reach' => $reach]) ?>
        <?= field_html('email_copy', 'Also send an email copy', 'checkbox', $values['email_copy'] ?? '0', null, ['hint' => count($reach_email['recipients']) . ' of them can receive bulk email. The copy is sent once, when the notice is first published.']) ?>
        <div class="btn-row">
          <button class="btn ghost" type="submit" name="action" value="save"><?= icon('check') ?> Save</button>
          <?php if (!$published): ?><button class="btn accent" type="submit" name="action" value="publish"><?= icon('bell') ?> Publish</button>
          <?php else: ?><button class="btn ghost" type="submit" name="action" value="unpublish">Take down</button><?php endif ?>
        </div>
      </div></section>
    </div>
  </div>
</form>
<script type="module" src="<?= e(asset('assets/js/campaigns.js')) ?>"></script>
