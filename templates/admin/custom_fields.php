<?php /** @var array $rows */ ?>
<div class="page-head"><div><h1>Custom fields</h1><p>Extra questions on the sign-up form and member profiles, for example “District of origin”.</p></div>
  <div class="actions"><a class="btn accent" href="/admin/custom-fields/new"><?= icon('plus') ?> New field</a></div></div>
<div class="panel"><div class="table-wrap"><table class="data">
  <thead><tr><th scope="col">Label</th><th scope="col">Type</th><th scope="col">For</th><th scope="col">Rules</th><th scope="col">Answers</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="6" class="empty-row">No custom fields yet. <a href="/admin/custom-fields/new">Add the first one</a>.</td></tr><?php endif ?>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="title-cell"><a href="/admin/custom-fields/<?= (int) $r['id'] ?>"><?= e($r['label']) ?></a><br><code class="muted">cf_<?= e($r['field_key']) ?></code></td>
      <td><?= e(\Admin\CustomFields::TYPES[$r['type']] ?? $r['type']) ?></td>
      <td><?= $r['applies_to'] === 'group' ? 'Groups' : 'Members' ?></td>
      <td><?= (int) $r['is_required'] ? '<span class="badge">required</span> ' : '' ?><?= (int) $r['show_on_card'] ? '<span class="badge members">on card</span> ' : '' ?><?= (int) $r['visible_to_members'] ? '<span class="badge ok">members see it</span>' : '<span class="badge warn">admins only</span>' ?></td>
      <td class="tabular"><?= (int) $r['used'] ?></td>
      <td class="actions-cell"><a class="btn tiny ghost" href="/admin/custom-fields/<?= (int) $r['id'] ?>">Edit<span class="sr-only"> <?= e($r['label']) ?></span></a></td>
    </tr>
  <?php endforeach ?>
  </tbody></table></div></div>
