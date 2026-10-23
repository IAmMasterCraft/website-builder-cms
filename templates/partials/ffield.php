<?php
/**
 * Public form field. @var string $name @var string $label @var string $type text|email|tel|password|date|select|textarea|checkbox
 * @var array $values @var array $errors @var bool $required @var ?string $hint @var array $options (select: value => label)
 * @var string $auto autocomplete @var string $extra extra attributes (code only)
 */
$type ??= 'text';
$required ??= false;
$id = 'j-' . preg_replace('/[^a-z0-9_-]/i', '-', $name);
$val = (string) ($values[$name] ?? '');
$err = $errors[$name] ?? null;
$desc = trim((isset($hint) && $hint !== '' ? "$id-hint " : '') . ($err ? "$id-err" : ''));
$aria = ($desc !== '' ? ' aria-describedby="' . e($desc) . '"' : '') . ($err ? ' aria-invalid="true"' : '') . ($required ? ' required' : '') . (isset($auto) && $auto !== '' ? ' autocomplete="' . e($auto) . '"' : '') . ($extra ?? '');
$opt = $required ? '' : ' <span class="muted">(optional)</span>';
?>
<?php if ($type === 'checkbox'): ?>
  <div class="field"><label class="check-line"><input type="checkbox" id="<?= e($id) ?>" name="<?= e($name) ?>" value="1"<?= $val === '1' ? ' checked' : '' ?><?= $aria ?>> <span><?= $label /* label HTML is built from escaped parts by the caller */ ?></span></label>
<?php else: ?>
  <div class="field"><label for="<?= e($id) ?>"><?= e($label) ?><?= $opt ?></label>
  <?php if ($type === 'select'): ?>
    <select id="<?= e($id) ?>" name="<?= e($name) ?>"<?= $aria ?>><option value=""><?= $required ? 'Choose…' : '—' ?></option><?php foreach ($options as $ov => $ol): ?><option value="<?= e((string) $ov) ?>"<?= (string) $ov === $val ? ' selected' : '' ?>><?= e($ol) ?></option><?php endforeach ?></select>
  <?php elseif ($type === 'textarea'): ?>
    <textarea id="<?= e($id) ?>" name="<?= e($name) ?>" rows="3"<?= $aria ?>><?= e($val) ?></textarea>
  <?php else: ?>
    <input type="<?= e($type) ?>" id="<?= e($id) ?>" name="<?= e($name) ?>"<?= $type !== 'password' ? ' value="' . e($val) . '"' : '' ?><?= $aria ?>>
  <?php endif ?>
<?php endif ?>
  <?php if (isset($hint) && $hint !== ''): ?><p class="hint" id="<?= e($id) ?>-hint"><?= e($hint) ?></p><?php endif ?>
  <?php if ($err): ?><p class="error" id="<?= e($id) ?>-err"><?= e($err) ?></p><?php endif ?>
  </div>
