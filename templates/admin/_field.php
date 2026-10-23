<?php
/** @var array $f field definition @var array $values @var array $errors @var array $options @var array $datalists */
$name = $f['name'];
$id = 'f-' . $name;
$val = $values[$name] ?? '';
$val = is_array($val) ? '' : (string) $val;
$err = $errors[$name] ?? null;
$req = !empty($f['required']);
$describedBy = trim((isset($f['help']) ? $id . '-hint ' : '') . ($err ? $id . '-err' : ''));
$aria = ($describedBy !== '' ? ' aria-describedby="' . e($describedBy) . '"' : '') . ($err ? ' aria-invalid="true"' : '');
$label = '<label for="' . e($id) . '">' . e($f['label']) . ($req ? ' <span class="req" aria-hidden="true">*</span>' : '') . '</label>';
$hint = isset($f['help']) ? '<p class="hint" id="' . e($id) . '-hint">' . e($f['help']) . '</p>' : '';
$errHtml = $err ? '<p class="error" id="' . e($id) . '-err">' . e($err) . '</p>' : '';
?>
<?php switch ($f['type']):
case 'checkbox': ?>
  <div class="field"><label class="check"><input type="checkbox" id="<?= e($id) ?>" name="<?= e($name) ?>" value="1"<?= $val === '1' || $val === 1 ? ' checked' : '' ?><?= $aria ?>> <?= e($f['label']) ?></label><?= $hint ?><?= $errHtml ?></div>
<?php break; case 'textarea': ?>
  <div class="field"><?= $label ?><textarea id="<?= e($id) ?>" name="<?= e($name) ?>" rows="3"<?= isset($f['max']) ? ' maxlength="' . (int) $f['max'] . '"' : '' ?><?= $req ? ' required' : '' ?><?= $aria ?>><?= e($val) ?></textarea><?= $hint ?><?= $errHtml ?></div>
<?php break; case 'richtext': ?>
  <div class="field"><?= $label ?>
    <div class="rt" data-rt>
      <div class="rt-editor" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="<?= e($id) ?>-lbl" hidden><?= \Core\Sanitizer::clean($val) /* sanitised for editing */ ?></div>
      <textarea id="<?= e($id) ?>" name="<?= e($name) ?>" data-richtext rows="12"<?= $aria ?>><?= e($val) ?></textarea>
    </div>
    <span id="<?= e($id) ?>-lbl" class="sr-only"><?= e($f['label']) ?> editor</span>
    <?= $hint ?><?= $errHtml ?></div>
<?php break; case 'select': ?>
  <div class="field"><?= $label ?><select id="<?= e($id) ?>" name="<?= e($name) ?>"<?= $req ? ' required' : '' ?><?= $aria ?>>
    <?php if ($req && $val === ''): ?><option value="">Choose…</option><?php endif ?>
    <?php foreach ($options[$name] ?? [] as $ov => $ol): ?><option value="<?= e((string) $ov) ?>"<?= (string) $ov === $val ? ' selected' : '' ?>><?= e($ol) ?></option><?php endforeach ?>
  </select><?= $hint ?><?= $errHtml ?></div>
<?php break; case 'media': case 'file':
  $m = $val !== '' && ctype_digit($val) ? media_find((int) $val) : null; ?>
  <div class="field media-field" data-media-field>
    <span class="label" id="<?= e($id) ?>-label"><?= e($f['label']) ?><?= $req ? ' <span class="req" aria-hidden="true">*</span>' : '' ?></span>
    <div class="media-preview<?= $f['type'] === 'file' ? ' file' : '' ?>" data-media-preview>
      <?php if ($m && $m['kind'] === 'image'): ?><?= img_html($m, '20rem', '', '', true, 400) ?>
      <?php elseif ($m): ?><span><?= icon('file') ?> <a href="<?= e(media_original_url($m)) ?>" target="_blank" rel="noopener"><?= e($m['original_name']) ?></a></span>
      <?php else: ?><span>No <?= $f['type'] === 'media' ? 'image' : 'file' ?> chosen</span><?php endif ?>
    </div>
    <input type="hidden" name="<?= e($name) ?>" value="<?= e($val) ?>" data-media-input>
    <?php if ($f['type'] === 'media'): ?><button type="button" class="btn small ghost" data-media-pick hidden><?= icon('image') ?> Choose from library</button><?php endif ?>
    <label for="<?= e($id) ?>-up" class="hint">Upload <?= $m ? 'a replacement' : 'a new ' . ($f['type'] === 'media' ? 'image' : 'file') ?></label>
    <input type="file" id="<?= e($id) ?>-up" name="<?= e($name) ?>_upload" accept="<?= e($f['type'] === 'media' ? media_accept('image') : media_accept()) ?>"<?= $aria ?>>
    <?php if ($m && !$req): ?><label class="check"><input type="checkbox" name="<?= e($name) ?>_remove" value="1"> Remove</label><?php endif ?>
    <?= $hint ?><?= $errHtml ?>
  </div>
<?php break; case 'tags': ?>
  <div class="field"><?= $label ?><input type="text" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e($val) ?>" maxlength="500"<?= $aria ?>><?= $hint ?><?= $errHtml ?></div>
<?php break; case 'tickets':
  $rows = [];
  if (isset($values['ticket_name']) && is_array($values['ticket_name'])) {
      foreach ($values['ticket_name'] as $i => $n) {
          $rows[] = ['id' => $values['ticket_id'][$i] ?? '', 'name' => $n, 'price_label' => $values['ticket_price'][$i] ?? ''];
      }
  } else {
      $rows = $values['ticket_rows'] ?? [];
  }
  $rows[] = ['id' => '', 'name' => '', 'price_label' => ''];
  $rows[] = ['id' => '', 'name' => '', 'price_label' => '']; ?>
  <fieldset class="field"><legend><?= e($f['label']) ?></legend>
    <div class="ticket-rows" data-ticket-rows>
      <?php foreach ($rows as $i => $t): ?>
        <div class="ticket-row">
          <input type="hidden" name="ticket_id[]" value="<?= e((string) $t['id']) ?>">
          <label class="sr-only" for="tn-<?= $i ?>">Ticket name</label><input type="text" id="tn-<?= $i ?>" name="ticket_name[]" value="<?= e((string) $t['name']) ?>" placeholder="Ticket name, e.g. Adult" maxlength="120">
          <label class="sr-only" for="tp-<?= $i ?>">Price label</label><input type="text" id="tp-<?= $i ?>" name="ticket_price[]" value="<?= e((string) $t['price_label']) ?>" placeholder="Price, e.g. £5" maxlength="60">
          <span></span>
        </div>
      <?php endforeach ?>
    </div>
    <p><button type="button" class="btn small ghost" data-add-ticket hidden><?= icon('plus') ?> Add ticket option</button></p>
    <?= $hint ?><?= $errHtml ?>
  </fieldset>
<?php break; case 'social': ?>
  <fieldset class="field"><legend><?= e($f['label']) ?></legend>
    <div class="field-row">
      <?php foreach (leader_social_networks() as $sk => $sl): ?>
        <div class="field"><label for="soc-<?= e($sk) ?>"><?= e($sl) ?></label><input type="url" id="soc-<?= e($sk) ?>" name="social_<?= e($sk) ?>" value="<?= e((string) ($values['social_' . $sk] ?? '')) ?>" placeholder="https://" maxlength="300"></div>
      <?php endforeach ?>
    </div><?= $errHtml ?>
  </fieldset>
<?php break; default:
  $inputType = match ($f['type']) { 'email' => 'email', 'url' => 'url', 'number' => 'number', 'date' => 'date', 'datetime' => 'datetime-local', default => 'text' };
  $list = isset($datalists[$name]) ? $id . '-list' : null; ?>
  <div class="field"><?= $label ?><input type="<?= $inputType ?>" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e($val) ?>"<?= isset($f['max']) && $inputType !== 'number' ? ' maxlength="' . (int) $f['max'] . '"' : '' ?><?= $inputType === 'number' && isset($f['min']) ? ' min="' . (int) $f['min'] . '" max="' . (int) $f['max'] . '"' : '' ?><?= $req ? ' required' : '' ?><?= $list ? ' list="' . e($list) . '"' : '' ?><?= $f['type'] === 'slug' ? ' pattern="[a-z0-9-]*" spellcheck="false"' : '' ?><?= $aria ?>>
  <?php if ($list): ?><datalist id="<?= e($list) ?>"><?php foreach ($datalists[$name] as $dv): ?><option value="<?= e($dv) ?>"></option><?php endforeach ?></datalist><?php endif ?>
  <?= $hint ?><?= $errHtml ?></div>
<?php endswitch ?>
