<?php /** @var array $message @var array $messages @var array $reports @var array $participants @var ?array $sender @var int $sender_member_id @var bool $open @var array $user */
$kind = ['direct' => '1-to-1 conversation', 'group' => 'group conversation', 'announcement' => 'announcement group', 'team' => 'Team inbox thread'][$message['kind']] ?? 'conversation';
?>
<div class="page-head">
  <div><p class="crumbs"><a href="/admin/messages/reports">Reported messages</a></p><h1>Reported message</h1>
    <p>From <strong><?= e(msg_account_name($sender)) ?></strong> in a <?= e($kind) ?> with <?= e(pluralise(count($participants), 'person', 'people')) ?>.<?php if ($sender_member_id > 0 && user_can($user, 'members')): ?> <a href="/admin/members/<?= $sender_member_id ?>">Member record</a><?php endif ?></p></div>
</div>
<p class="notice-box"><?= icon('eye') ?> You are reading part of a private conversation because it was reported. Only the reported message and up to <?= MSG_REPORT_CONTEXT ?> messages before and after it are shown, and this view is recorded in the audit log.</p>
<div class="panel"><div class="panel-head"><h2>Reports</h2></div><div class="panel-body">
  <ul class="dm-reasons">
    <?php foreach ($reports as $r): ?><li><strong><?= e($r['reporter']) ?></strong>, <?= e(fmt_datetime($r['created_at'])) ?>: <?= $r['reason'] !== '' ? '“' . e($r['reason']) . '”' : '<span class="muted">no reason given</span>' ?><?php if ($r['resolved_at'] !== null): ?> <span class="badge <?= $r['outcome'] === 'removed' ? 'bad' : 'ok' ?>"><?= e($r['outcome']) ?> by <?= e($r['resolver']) ?></span><?php endif ?></li><?php endforeach ?>
  </ul>
</div></div>
<div class="panel dm"><div class="panel-head"><h2>The message in context</h2></div>
  <ol class="dm-list dm-context">
    <?php foreach ($messages as $m): ?><?= view('messages/_message', ['m' => $m, 'base' => '/admin/messages/reports', 'conv_id' => (int) $message['id'], 'act' => '', 'act_id' => 0, 'values' => [], 'errors' => []]) ?><?php endforeach ?>
  </ol>
</div>
<?php if ($open): ?>
<div class="panel"><div class="panel-head"><h2>Decide</h2></div><div class="panel-body">
  <p>Keep the message if it is acceptable, or remove it: everyone in the conversation then sees “Message removed by a moderator”. To stop someone messaging, suspend their account from the members list.</p>
  <div class="btn-row">
    <form method="post" action="/admin/messages/reports/<?= (int) $message['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="keep"><button class="btn ghost" type="submit"><?= icon('check') ?> Keep the message</button></form>
    <form method="post" action="/admin/messages/reports/<?= (int) $message['id'] ?>" data-confirm="Remove this message for everyone?"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><button class="btn danger" type="submit"><?= icon('trash') ?> Remove the message</button></form>
  </div>
</div></div>
<?php endif ?>
