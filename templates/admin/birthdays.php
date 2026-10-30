<?php /** @var int $month @var array $rows @var string $today */
$months = [];
for ($i = 1; $i <= 12; $i++) { $months[$i] = date('F', mktime(0, 0, 0, $i, 1)); }
?>
<div class="page-head">
  <div><h1>Birthdays</h1><p>Members’ birthdays by month: name, member number and day. The birth year and age are never shown.</p></div>
  <div class="actions">
    <a class="btn ghost" href="/admin/members/birthdays.csv?month=<?= (int) $month ?>"><?= icon('download') ?> Export CSV</a>
    <?php if (user_can(current_user(), 'super_admin')): ?><a class="btn ghost" href="/admin/settings/birthdays"><?= icon('settings') ?> Notice settings</a><?php endif ?>
  </div>
</div>
<div class="panel">
  <form class="toolbar" method="get" action="/admin/members/birthdays">
    <label for="bm">Month</label>
    <select id="bm" name="month"><?php foreach ($months as $n => $label): ?><option value="<?= $n ?>"<?= $n === $month ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select>
    <button class="btn small ghost" type="submit">Show</button>
  </form>
  <div class="table-wrap">
    <table class="data">
      <caption class="sr-only">Birthdays in <?= e($months[$month]) ?></caption>
      <thead><tr><th scope="col">Day</th><th scope="col">Member</th><th scope="col">Number</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="4" class="empty-row">No birthdays in <?= e($months[$month]) ?>.</td></tr><?php endif ?>
      <?php foreach ($rows as $b): ?>
        <tr<?= $b['celebrated_on'] === $today ? ' class="is-today"' : '' ?>>
          <td class="tabular"><?= e(birthday_day_label($b)) ?><?= $b['celebrated_on'] === $today ? ' <span class="badge ok">today</span>' : '' ?></td>
          <td><a href="/admin/members/<?= (int) $b['id'] ?>"><?= e(member_full_name($b)) ?></a></td>
          <td class="tabular"><?= e($b['member_number'] ?? '—') ?></td>
          <td class="actions-cell"><a class="btn small ghost" href="/admin/messages/new?to=<?= (int) $b['user_id'] ?>"><?= icon('message') ?><span>Send a message</span><span class="sr-only"> to <?= e(member_full_name($b)) ?></span></a></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <p class="pager"><span class="tabular"><?= e(pluralise(count($rows), 'birthday')) ?> in <?= e($months[$month]) ?></span></p>
</div>
