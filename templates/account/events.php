<?php /** @var array $rows */ ?>
<?php if (!$rows): ?>
  <?= view('partials/empty', ['message' => 'You have not registered for any events yet.']) ?>
  <p><a class="btn" href="/events">Browse events</a></p>
<?php else: ?>
  <div class="table-card">
    <table class="simple-table">
      <caption class="sr-only">Your event registrations</caption>
      <thead><tr><th scope="col">Event</th><th scope="col">When</th><th scope="col">Places</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><a href="/events/<?= e($r['slug']) ?>"><?= e($r['title']) ?></a><?= $r['ticket_name'] ? '<br><span class="muted">' . e($r['ticket_name']) . '</span>' : '' ?></td>
          <td class="tabular"><?= e(fmt_datetime($r['start_at'])) ?></td>
          <td class="tabular"><?= (int) $r['quantity'] ?></td>
          <td><span class="badge<?= $r['status'] === 'cancelled' ? ' muted' : '' ?>"><?= e(ucfirst($r['status'])) ?></span></td>
          <td><?php if ($r['is_future'] && $r['status'] === 'registered'): ?>
            <form method="post" action="/account/events/<?= (int) $r['id'] ?>/cancel" data-confirm="Cancel your place at <?= e($r['title']) ?>?"><?= csrf_field() ?><button class="btn small ghost" type="submit">Cancel<span class="sr-only"> registration for <?= e($r['title']) ?></span></button></form>
          <?php endif ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
<?php endif ?>
