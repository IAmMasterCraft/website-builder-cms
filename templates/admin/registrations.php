<div class="page-head">
  <div><p class="crumbs"><a href="/admin/events">Events</a> / <a href="/admin/events/<?= (int) $event['id'] ?>/edit"><?= e($event['title']) ?></a> /</p><h1>Registrations</h1>
    <p class="tabular"><?= (int) $totals['registered'] ?> registered · <?= (int) $totals['attended'] ?> checked in · <?= (int) $totals['cancelled'] ?> cancelled<?= $event['capacity'] !== null ? ' · capacity ' . (int) $event['capacity'] : '' ?></p></div>
  <div class="actions"><a class="btn ghost" href="/admin/events/<?= (int) $event['id'] ?>/registrations.csv"><?= icon('download') ?> Export CSV</a></div>
</div>
<div class="panel"><div class="table-wrap">
  <table class="data">
    <thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Ticket</th><th scope="col">Places</th><th scope="col">Status</th><th scope="col">Registered</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="7" class="empty-row">No registrations yet.</td></tr><?php endif ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="title-cell"><?= e($r['name']) ?><?= $r['user_id'] ? ' <span class="badge members">member</span>' : '' ?></td>
        <td><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a><?= $r['phone'] !== '' ? '<br><span class="muted">' . e($r['phone']) . '</span>' : '' ?></td>
        <td><?= e($r['ticket_name'] ?? '—') ?></td>
        <td class="tabular"><?= (int) $r['quantity'] ?></td>
        <td><span class="badge <?= e($r['status']) ?>" data-status-badge><?= e($r['status']) ?></span></td>
        <td class="tabular"><?= e(fmt_date($r['created_at'], 'j M, H:i')) ?></td>
        <td class="actions-cell">
          <form class="inline-form" method="post" action="/admin/events/<?= (int) $event['id'] ?>/registrations/<?= (int) $r['id'] ?>" data-checkin>
            <?= csrf_field() ?>
            <?php if ($r['status'] === 'registered'): ?>
              <input type="hidden" name="status" value="attended"><button class="btn tiny" type="submit"><?= icon('check') ?> Check in<span class="sr-only"> <?= e($r['name']) ?></span></button>
            <?php elseif ($r['status'] === 'attended'): ?>
              <input type="hidden" name="status" value="registered"><button class="btn tiny ghost" type="submit">Undo check-in<span class="sr-only"> for <?= e($r['name']) ?></span></button>
            <?php else: ?>
              <input type="hidden" name="status" value="registered"><button class="btn tiny ghost" type="submit">Restore<span class="sr-only"> <?= e($r['name']) ?></span></button>
            <?php endif ?>
          </form>
          <?php if ($r['status'] !== 'cancelled'): ?>
          <form class="inline-form" method="post" action="/admin/events/<?= (int) $event['id'] ?>/registrations/<?= (int) $r['id'] ?>" data-confirm="Cancel this registration?">
            <?= csrf_field() ?><input type="hidden" name="status" value="cancelled"><button class="btn tiny danger-ghost" type="submit">Cancel<span class="sr-only"> registration for <?= e($r['name']) ?></span></button>
          </form>
          <?php endif ?>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</div></div>
