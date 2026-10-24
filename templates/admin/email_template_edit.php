<?php /** @var array $t @var array $values @var array $errors */
$err = static fn (string $k): ?string => $errors[$k] ?? null;
$placeholders = $t['placeholders'] + email_common_placeholders();
?>
<div class="page-head">
  <div><p class="crumbs"><a href="/admin/email-templates">Email templates</a></p><h1><?= e($t['label']) ?></h1><p><?= e($t['description']) ?></p></div>
  <div class="actions">
    <form method="post" action="/admin/email-templates/<?= e($t['key']) ?>/test"><?= csrf_field() ?><button class="btn ghost" type="submit"><?= icon('mail') ?> Send me a test</button></form>
    <?php if ($t['customised']): ?><form method="post" action="/admin/email-templates/<?= e($t['key']) ?>/reset" data-confirm="Go back to the default text? Your changes are lost."><?= csrf_field() ?><button class="btn danger-ghost" type="submit">Reset to default</button></form><?php endif ?>
  </div>
</div>
<?= form_errors_html($errors) ?>
<div class="split-editor">
  <form class="panel" method="post" action="/admin/email-templates/<?= e($t['key']) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="panel-body fields">
      <?= field_html('subject', 'Subject line', 'text', $values['subject'] ?? '', $err('subject'), ['max' => 200, 'required' => true]) ?>
      <?= field_html('body', 'Email text', 'textarea', $values['body'] ?? '', $err('body'), ['rows' => 14, 'max' => 8000, 'required' => true, 'hint' => 'Plain text. Leave an empty line to start a new paragraph.']) ?>
      <?php if (isset($t['button'])): ?>
        <?= field_html('button_label', 'Button text', 'text', $values['button_label'] ?? '', $err('button_label'), ['max' => 60, 'hint' => 'The button links to {' . $t['button']['url'] . '}.']) ?>
      <?php endif ?>
      <div class="field"><span class="label">Placeholders you can use</span>
        <ul class="chip-list">
          <?php foreach ($placeholders as $name => $label): ?><li><code>{<?= e($name) ?>}</code> <span class="muted"><?= e($label) ?></span></li><?php endforeach ?>
        </ul>
      </div>
      <div class="form-actions"><button class="btn accent" type="submit"><?= icon('check') ?> Save template</button>
        <?php if ($t['updated_at']): ?><span class="muted small-text">Last edited <?= e(fmt_datetime((string) $t['updated_at'])) ?></span><?php endif ?></div>
    </div>
  </form>
  <section class="panel email-preview-panel">
    <div class="panel-head"><h2>Preview</h2><span class="muted small-text">With sample values; saved version</span></div>
    <iframe class="email-frame" src="/admin/email-templates/<?= e($t['key']) ?>/preview" title="Email preview" loading="lazy"></iframe>
  </section>
</div>
