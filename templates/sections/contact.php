<?php /** @var array $s @var array $d @var string $h @var array $ctx */
$err = $d['errors'];
$old = $d['old'];
$val = static fn (string $k): string => (string) ($old[$k] ?? '');
$page = $ctx['page'] ?? null;
$field = static function (string $k, string $label, string $type = 'text', bool $req = true, string $auto = '') use ($err, $val): string {
    $id = 'cf-' . $k;
    $e = $err[$k] ?? null;
    $control = $type === 'textarea'
        ? '<textarea id="' . $id . '" name="' . $k . '" rows="5" maxlength="5000"' . ($req ? ' required' : '') . ($e ? ' aria-invalid="true" aria-describedby="' . $id . '-err"' : '') . '>' . e($val($k)) . '</textarea>'
        : '<input type="' . $type . '" id="' . $id . '" name="' . $k . '" value="' . e($val($k)) . '" maxlength="190"' . ($auto !== '' ? ' autocomplete="' . $auto . '"' : '') . ($req ? ' required' : '') . ($e ? ' aria-invalid="true" aria-describedby="' . $id . '-err"' : '') . '>';
    return '<div class="field"><label for="' . $id . '">' . e($label) . ($req ? '' : ' <span class="muted">(optional)</span>') . '</label>' . $control
        . ($e ? '<p class="error" id="' . $id . '-err">' . e($e) . '</p>' : '') . '</div>';
}; ?>
<div class="container">
  <div class="contact-grid<?= $s['show_form'] ? '' : ' no-form' ?>">
    <div>
      <<?= $h ?>><?= e($s['heading']) ?></<?= $h ?>>
      <?php if ($s['intro'] !== ''): ?><p class="lede"><?= e($s['intro']) ?></p><?php endif ?>
      <?php if ($s['show_details']): ?>
        <ul class="event-facts contact-facts">
          <?php if ($d['address'] !== ''): ?><li><?= icon('pin') ?><div><strong>Address</strong><?= e($d['address']) ?></div></li><?php endif ?>
          <?php if ($d['phone'] !== ''): ?><li><?= icon('phone') ?><div><strong>Phone</strong><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $d['phone'])) ?>"><?= e($d['phone']) ?></a></div></li><?php endif ?>
          <?php if ($d['email'] !== ''): ?><li><?= icon('mail') ?><div><strong>Email</strong><a href="mailto:<?= e($d['email']) ?>"><?= e($d['email']) ?></a></div></li><?php endif ?>
        </ul>
      <?php endif ?>
      <?php if ($d['map_src'] !== ''): ?>
        <div class="embed ratio-4x3 contact-map"><iframe src="<?= e($d['map_src']) ?>" title="Map: <?= e($s['map_query'] !== '' ? $s['map_query'] : $d['address']) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe></div>
      <?php endif ?>
    </div>
    <?php if ($s['show_form']): ?>
      <div class="form-card">
        <?php if ($d['sent']): ?>
          <div class="flash success" role="status"><?= icon('check') ?><span>Thank you, your message has been sent. We will reply by email.</span></div>
        <?php endif ?>
        <?php if (isset($err['form'])): ?><div class="flash error" role="alert"><?= icon('alert') ?><span><?= e($err['form']) ?></span></div><?php endif ?>
        <form class="form" method="post" action="/contact" novalidate>
          <?= csrf_field() ?>
          <?= form_guard_fields('contact') ?>
          <input type="hidden" name="return" value="<?= e(request_path()) ?>">
          <?php if ($page): ?><input type="hidden" name="page_id" value="<?= (int) $page['id'] ?>"><?php endif ?>
          <div class="form-row"><?= $field('name', 'Your name', 'text', true, 'name') ?><?= $field('email', 'Email', 'email', true, 'email') ?></div>
          <div class="form-row"><?= $field('phone', 'Phone', 'tel', false, 'tel') ?><?= $field('subject', 'Subject', 'text', false) ?></div>
          <?= $field('message', 'Message', 'textarea') ?>
          <p><button class="btn accent" type="submit"><?= icon('mail') ?> Send message</button></p>
          <p class="hint muted">We use your details only to reply to you.</p>
        </form>
      </div>
    <?php endif ?>
  </div>
</div>
