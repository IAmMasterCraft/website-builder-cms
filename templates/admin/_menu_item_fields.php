<?php /** @var array $values @var array $errors @var array $pages @var array $choices @var array $parents @var string $prefix */
$v = static fn (string $k): string => (string) ($values[$k] ?? '');
$err = static fn (string $k): string => isset($errors[$k]) ? '<p class="error" id="' . e($prefix . $k) . '-err">' . e($errors[$k]) . '</p>' : '';
$type = $v('target_type') ?: 'page';
?>
<div class="field"><label for="<?= e($prefix) ?>label">Label</label><input type="text" id="<?= e($prefix) ?>label" name="label" value="<?= e($v('label')) ?>" maxlength="120" aria-describedby="<?= e($prefix) ?>label-hint"><p class="hint" id="<?= e($prefix) ?>label-hint">Leave empty to use the page or item title.</p><?= $err('label') ?></div>
<fieldset class="target-choice" data-target-choice>
  <legend>Links to</legend>
  <div class="radio-row">
    <?php foreach (['page' => 'A page', 'list' => 'A content list', 'item' => 'A content item', 'url' => 'A web address'] as $k => $l): ?>
      <label class="check"><input type="radio" name="target_type" value="<?= e($k) ?>"<?= $type === $k ? ' checked' : '' ?>> <?= e($l) ?></label>
    <?php endforeach ?>
  </div>
  <?= $err('target_type') ?>
  <div class="field" data-target-panel="page"><label for="<?= e($prefix) ?>page">Page</label><select id="<?= e($prefix) ?>page" name="page_id"><option value="">Choose a page…</option><?php foreach ($pages as $pg): ?><option value="<?= (int) $pg['id'] ?>"<?= $v('page_id') === (string) $pg['id'] ? ' selected' : '' ?>><?= e($pg['title']) ?><?= $pg['status'] !== 'published' ? ' (' . e($pg['status']) . ')' : '' ?></option><?php endforeach ?></select><?= $err('page_id') ?></div>
  <div class="field" data-target-panel="list"><label for="<?= e($prefix) ?>list">Content list</label><select id="<?= e($prefix) ?>list" name="list_key"><option value="">Choose a list…</option><?php foreach (menu_list_targets() as $k => [$l, $href]): ?><option value="<?= e($k) ?>"<?= $v('list_key') === $k ? ' selected' : '' ?>><?= e($l) ?> (<?= e($href) ?>)</option><?php endforeach ?></select><?= $err('list_key') ?></div>
  <div class="field" data-target-panel="item"><label for="<?= e($prefix) ?>item">Content item</label><select id="<?= e($prefix) ?>item" name="item_ref"><option value="">Choose an item…</option>
    <?php foreach ($choices as $group => $opts): ?><optgroup label="<?= e(ucfirst(str_replace('_', ' ', $group))) ?>"><?php foreach ($opts as $ref => $l): ?><option value="<?= e($ref) ?>"<?= $v('item_ref') === $ref ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></optgroup><?php endforeach ?>
  </select><?= $err('item_ref') ?></div>
  <div class="field" data-target-panel="url"><label for="<?= e($prefix) ?>url">Web address</label><input type="text" id="<?= e($prefix) ?>url" name="url" value="<?= e($v('url')) ?>" placeholder="https://… or /path" maxlength="500"><?= $err('url') ?></div>
</fieldset>
<div class="field"><label for="<?= e($prefix) ?>parent">Parent</label><select id="<?= e($prefix) ?>parent" name="parent_id"><option value="">None (top level)</option><?php foreach ($parents as $pa): ?><option value="<?= (int) $pa['id'] ?>"<?= $v('parent_id') === (string) $pa['id'] ? ' selected' : '' ?>><?= e($pa['label']) ?></option><?php endforeach ?></select><?= $err('parent_id') ?></div>
<label class="check"><input type="checkbox" name="open_new_tab" value="1"<?= $v('open_new_tab') === '1' ? ' checked' : '' ?>> Open in a new tab</label>
<label class="check"><input type="checkbox" name="is_hidden" value="1"<?= $v('is_hidden') === '1' ? ' checked' : '' ?>> Hidden (keep it, but do not show it)</label>
