<?php /** @var array $ctx @var array $rows */ ?>
<?php if ($ctx['admin']): ?><div class="page-head"><div><p class="crumbs"><a href="/admin/messages">Messages</a></p><h1>Blocked people</h1></div></div>
<?php else: ?><p class="dm-back"><a href="<?= e($ctx['base']) ?>"><?= icon('chevron-left') ?> All messages</a></p><?php endif ?>
<div class="dm<?= $ctx['admin'] ? ' panel' : '' ?> dm-blocked">
  <p>People you blocked cannot message you or add you to a group, and your 1-to-1 conversation with them is hidden. They are not told.</p>
  <?php if ($rows === []): ?><p class="muted">You have not blocked anyone.</p><?php else: ?>
  <ul class="dm-people">
    <?php foreach ($rows as $a): ?>
      <li><span><?= e(msg_account_name($a)) ?></span>
        <form method="post" action="<?= e($ctx['base'] . '/unblock/' . (int) $a['id']) ?>"><?= csrf_field() ?><button class="btn ghost small" type="submit">Unblock<span class="sr-only"> <?= e(msg_account_name($a)) ?></span></button></form></li>
    <?php endforeach ?>
  </ul>
  <?php endif ?>
</div>
