<?php /** @var array $rows @var array $filters @var int $total @var int $page @var int $per @var array $settings @var array $fields @var array $counts */
$query = array_filter($filters, static fn ($v) => $v !== '');
$pages = (int) ceil($total / $per);
?>
<div class="page-head">
  <div><h1>Members</h1><p class="tabular"><?= e(pluralise($counts['active'], 'active member')) ?> · <?= e((string) $counts['unverified']) ?> awaiting email verification<?= $counts['deletion'] ? ' · <a href="/admin/members?status=deletion">' . e(pluralise($counts['deletion'], 'deletion request')) . '</a>' : '' ?></p></div>
  <div class="actions">
    <a class="btn ghost" href="<?= e(url_with('/admin/members.csv', $query, [])) ?>"><?= icon('download') ?> Export CSV</a>
    <a class="btn ghost" href="/admin/members/import"><?= icon('upload') ?> Import CSV</a>
  </div>
</div>
<div class="panel">
  <form class="toolbar" method="get" action="/admin/members" role="search">
    <label for="mq" class="sr-only">Search members</label>
    <input type="search" id="mq" name="q" value="<?= e($filters['q']) ?>" placeholder="Name, email, number or phone" maxlength="100">
    <label for="ms" class="sr-only">Status</label>
    <select id="ms" name="status"><option value="">All statuses</option><?php foreach (['active' => 'Active', 'unverified' => 'Awaiting verification', 'suspended' => 'Suspended', 'expired' => 'Card expired', 'deletion' => 'Deletion requested'] as $k => $l): ?><option value="<?= $k ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select>
    <label for="mt" class="sr-only">Membership type</label>
    <select id="mt" name="type"><option value="">All types</option><?php foreach ($settings['types'] as $t): ?><option<?= $filters['type'] === $t ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach ?></select>
    <label for="mr" class="sr-only">Region</label>
    <select id="mr" name="region"><option value="">All regions</option><?php foreach ($settings['regions'] as $t): ?><option<?= $filters['region'] === $t ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach ?></select>
    <?php if ($fields): ?>
      <label for="mf" class="sr-only">Custom field</label>
      <select id="mf" name="field"><option value="">Custom field…</option><?php foreach ($fields as $f): ?><option value="<?= (int) $f['id'] ?>"<?= $filters['field'] === (string) $f['id'] ? ' selected' : '' ?>><?= e($f['label']) ?></option><?php endforeach ?></select>
      <label for="mv" class="sr-only">Field value</label>
      <input type="search" id="mv" name="value" value="<?= e($filters['value']) ?>" placeholder="equals…" maxlength="100">
    <?php endif ?>
    <button class="btn small ghost" type="submit">Filter</button>
    <?php if ($query): ?><a class="btn small ghost" href="/admin/members">Clear</a><?php endif ?>
  </form>
  <form method="get" action="/admin/members/cards" target="_blank">
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th scope="col"><span class="sr-only">Select</span></th><th scope="col">Member</th><th scope="col">Number</th><th scope="col">Status</th><th scope="col">Type</th><th scope="col">Region</th><th scope="col">Joined</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="7" class="empty-row">No members found.</td></tr><?php endif ?>
        <?php foreach ($rows as $m): $photo = media_find($m['photo_media_id'] ? (int) $m['photo_media_id'] : null); ?>
          <tr>
            <td><?php if ($m['member_number'] && $m['status'] === 'active'): ?><input type="checkbox" name="ids[]" value="<?= (int) $m['id'] ?>" aria-label="Select <?= e(member_full_name($m)) ?> for printing"><?php endif ?></td>
            <td class="title-cell"><div class="member-cell"><?= $photo ? '<img class="thumb round" src="' . e(media_url($photo, 400)) . '" alt="" loading="lazy">' : '<span class="thumb round initial" aria-hidden="true">' . e(mb_substr($m['first_name'], 0, 1)) . '</span>' ?><span><a href="/admin/members/<?= (int) $m['id'] ?>"><?= e(member_full_name($m)) ?></a><br><span class="muted"><?= e($m['email']) ?></span></span></div></td>
            <td class="tabular"><?= e($m['member_number'] ?? '—') ?></td>
            <td><span class="badge <?= e($m['status']) ?>"><?= e($m['status'] === 'unverified' ? 'unverified' : $m['status']) ?></span><?= $m['deletion_requested_at'] ? ' <span class="badge bad">deletion requested</span>' : '' ?><?= $m['card_valid_until'] && $m['card_valid_until'] < date('Y-m-d') ? ' <span class="badge warn">expired</span>' : '' ?></td>
            <td><?= e($m['membership_type']) ?></td>
            <td><?= e($m['region'] ?? '—') ?></td>
            <td class="tabular"><?= e(fmt_date((string) $m['joined'])) ?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <div class="pager">
      <span class="tabular"><?= e(pluralise($total, 'member')) ?><?= $pages > 1 ? ' · page ' . $page . ' of ' . $pages : '' ?></span>
      <span class="btn-row">
        <button class="btn small ghost" type="submit"><?= icon('file') ?> Print selected cards</button>
        <a class="btn small ghost" href="<?= e(url_with('/admin/members/cards', $query, [])) ?>" target="_blank" rel="noopener">Print all filtered cards</a>
        <?php if ($page > 1): ?><a class="btn small ghost" href="<?= e(url_with('/admin/members', $query, ['page' => $page - 1])) ?>">Previous</a><?php endif ?>
        <?php if ($page < $pages): ?><a class="btn small ghost" href="<?= e(url_with('/admin/members', $query, ['page' => $page + 1])) ?>">Next</a><?php endif ?>
      </span>
    </div>
  </form>
</div>
