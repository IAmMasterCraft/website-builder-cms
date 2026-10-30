<?php /** @var string $cc @var array $admin1 @var array $counts @var array $custom @var array $labels @var array $builtin @var array $override @var int $members_using @var string $state */
$name = geo_country_name($cc);
?>
<div class="page-head"><div><h1>Location lists</h1><p>The countries, states and local areas offered on the sign-up form, profiles, filters and audiences come from GeoNames. Rename the levels for a country or add a local area that is missing.</p></div></div>
<div class="panel">
  <form class="toolbar" method="get" action="/admin/locations">
    <label for="loc-country">Country</label>
    <select id="loc-country" name="country"><?php foreach (geo_countries() as $code => $label): ?><option value="<?= e($code) ?>"<?= $code === $cc ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select>
    <button class="btn small ghost" type="submit">Show</button>
    <span class="muted tabular"><?= e(pluralise(count($admin1), 'first-level division')) ?> · <?= e(pluralise(array_sum($counts), 'local area')) ?> bundled · <?= e(pluralise($members_using, 'member')) ?> with a location here</span>
  </form>
</div>
<div class="form-layout">
  <div>
    <section class="panel"><div class="panel-head"><h2><?= e($name) ?>: <?= e($labels[0]) ?> and <?= e($labels[1]) ?></h2></div>
      <?php if (!$admin1): ?>
        <div class="panel-body"><p class="muted"><?= e($name) ?> has no divisions in the bundled lists: forms ask for a typed region and area instead.</p></div>
      <?php else: ?>
      <div class="table-wrap" tabindex="0" role="region" aria-label="<?= e($labels[0]) ?> list"><table class="data">
        <thead><tr><th scope="col"><?= e($labels[0]) ?></th><th scope="col">Code</th><th scope="col"><?= e($labels[1]) ?> (bundled)</th></tr></thead>
        <tbody>
        <?php foreach ($admin1 as $code => $label): ?>
          <tr><td><?= e($label) ?></td><td><code><?= e((string) $code) ?></code></td><td class="tabular"><?= (int) $counts[$code] ?></td></tr>
        <?php endforeach ?>
        </tbody>
      </table></div>
      <?php endif ?>
    </section>
  </div>
  <div class="side">
    <form class="panel" method="post" action="/admin/locations/labels">
      <?= csrf_field() ?><input type="hidden" name="country" value="<?= e($cc) ?>">
      <div class="panel-head"><h2>Level names for <?= e($name) ?></h2></div>
      <div class="panel-body fields">
        <div class="field"><label for="loc-l1">First level</label><input type="text" id="loc-l1" name="l1_label" value="<?= e((string) ($override[0] ?? '')) ?>" maxlength="60" placeholder="<?= e($builtin[0]) ?>" aria-describedby="loc-l-hint"></div>
        <div class="field"><label for="loc-l2">Second level</label><input type="text" id="loc-l2" name="l2_label" value="<?= e((string) ($override[1] ?? '')) ?>" maxlength="60" placeholder="<?= e($builtin[1]) ?>" aria-describedby="loc-l-hint"></div>
        <p class="hint" id="loc-l-hint">Leave empty for the default (<?= e($builtin[0]) ?> / <?= e($builtin[1]) ?>). The origin section can have its own labels under Membership settings.</p>
        <div class="form-actions"><button class="btn accent small" type="submit"><?= icon('check') ?> Save names</button></div>
      </div>
    </form>
    <?php if ($admin1): ?>
    <form class="panel" method="post" action="/admin/locations/areas">
      <?= csrf_field() ?><input type="hidden" name="country" value="<?= e($cc) ?>">
      <div class="panel-head"><h2>Add a missing <?= e(lcfirst_label($labels[1])) ?></h2></div>
      <div class="panel-body fields">
        <div class="field"><label for="loc-state"><?= e($labels[0]) ?></label><select id="loc-state" name="state" required><option value="">Choose…</option><?php foreach ($admin1 as $code => $label): ?><option value="<?= e((string) $code) ?>"<?= (string) $code === $state ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></div>
        <div class="field"><label for="loc-name">Name</label><input type="text" id="loc-name" name="name" maxlength="120" required></div>
        <div class="form-actions"><button class="btn small" type="submit"><?= icon('plus') ?> Add</button></div>
      </div>
    </form>
    <section class="panel"><div class="panel-head"><h2>Added by your team</h2></div><div class="panel-body">
      <?php if (!$custom): ?><p class="muted">None for <?= e($name) ?> yet.</p><?php else: ?>
      <ul class="area-list">
        <?php foreach ($custom as $c): ?>
          <li><strong><?= e($c['name']) ?></strong> <span class="muted">· <?= e($admin1[$c['admin1_code']] ?? $c['admin1_code']) ?> · <?= e(pluralise((int) $c['used'], 'member')) ?></span>
            <?php if ((int) $c['used'] === 0): ?><form class="inline-form" method="post" action="/admin/locations/areas/<?= (int) $c['id'] ?>/delete" data-confirm="Remove “<?= e($c['name']) ?>” from the list?"><?= csrf_field() ?><button class="btn tiny danger-ghost" type="submit">Remove<span class="sr-only"> <?= e($c['name']) ?></span></button></form><?php endif ?>
          </li>
        <?php endforeach ?>
      </ul>
      <?php endif ?>
    </div></section>
    <?php endif ?>
    <p class="hint">Place names: <a href="https://www.geonames.org/" rel="noopener" target="_blank">GeoNames</a> (CC BY 4.0).</p>
  </div>
</div>
