<?php /** @var array $fields @var array $values (cf_<key> => value) @var array $errors */
foreach ($fields as $f):
    $name = 'cf_' . $f['field_key'];
    $req = (int) $f['is_required'] === 1;
    if ($f['type'] === 'checkbox'): ?>
      <?= view('partials/ffield', ['name' => $name, 'label' => e($f['label']), 'type' => 'checkbox', 'values' => $values, 'errors' => $errors, 'required' => $req]) ?>
    <?php elseif ($f['type'] === 'select'): ?>
      <?= view('partials/ffield', ['name' => $name, 'label' => $f['label'], 'type' => 'select', 'options' => array_combine($f['options'], $f['options']) ?: [], 'values' => $values, 'errors' => $errors, 'required' => $req]) ?>
    <?php else: ?>
      <?= view('partials/ffield', ['name' => $name, 'label' => $f['label'], 'type' => $f['type'] === 'date' ? 'date' : 'text', 'values' => $values, 'errors' => $errors, 'required' => $req, 'extra' => $f['type'] === 'text' ? ' maxlength="200"' : '']) ?>
    <?php endif;
endforeach;
