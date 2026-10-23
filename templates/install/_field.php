<?php /** @var string $name @var string $label @var string $type @var array $old @var array $errors @var string $hint @var string $auto */
$id = 'i-' . $name; $err = $errors[$name] ?? null; $desc = trim((($hint ?? '') !== '' ? $id . '-hint ' : '') . ($err ? $id . '-err' : '')); ?>
<div class="field"><label for="<?= e($id) ?>"><?= e($label) ?></label>
  <input type="<?= e($type ?? 'text') ?>" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= ($type ?? 'text') === 'password' ? '' : e((string) ($old[$name] ?? '')) ?>"<?= isset($auto) ? ' autocomplete="' . e($auto) . '"' : '' ?><?= $desc !== '' ? ' aria-describedby="' . e($desc) . '"' : '' ?><?= $err ? ' aria-invalid="true"' : '' ?>>
  <?php if (($hint ?? '') !== ''): ?><p class="hint" id="<?= e($id) ?>-hint"><?= e($hint) ?></p><?php endif ?>
  <?php if ($err): ?><p class="error" id="<?= e($id) ?>-err"><?= e($err) ?></p><?php endif ?>
</div>
