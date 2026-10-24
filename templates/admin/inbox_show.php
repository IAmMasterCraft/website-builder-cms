<?php /** @var array $m @var array $replies @var array $values @var array $errors @var array $user */
$err = static fn (string $k): ?string => $errors[$k] ?? null;
$subject = $values['subject'] ?? ('Re: ' . preg_replace('/^(Re:\s*)+/i', '', (string) $m['subject']));
?>
<div class="page-head">
  <div><p class="crumbs"><a href="/admin/inbox">Inbox</a></p><h1><?= e($m['subject']) ?></h1>
    <p>From <strong><?= e($m['name']) ?></strong> &lt;<a href="mailto:<?= e($m['email']) ?>"><?= e($m['email']) ?></a>&gt;<?= $m['phone'] !== '' ? ' · ' . e($m['phone']) : '' ?> · <time datetime="<?= e(iso_date($m['created_at'])) ?>"><?= e(fmt_datetime($m['created_at'])) ?></time></p></div>
  <div class="actions">
    <?php if ($m['read_at'] === null): ?>
      <form method="post" action="/admin/inbox/<?= (int) $m['id'] ?>/read" data-auto-read><?= csrf_field() ?><button class="btn ghost" type="submit"><?= icon('check') ?> Mark as read</button></form>
    <?php else: ?>
      <form method="post" action="/admin/inbox/<?= (int) $m['id'] ?>/read"><?= csrf_field() ?><input type="hidden" name="unread" value="1"><button class="btn ghost" type="submit">Mark as unread</button></form>
    <?php endif ?>
    <form method="post" action="/admin/inbox/<?= (int) $m['id'] ?>/delete" data-confirm="Delete this message and its replies?"><?= csrf_field() ?><button class="btn danger-ghost" type="submit"><?= icon('trash') ?> Delete</button></form>
  </div>
</div>
<div class="thread">
  <article class="panel message"><div class="panel-body"><p class="message-body"><?= nl2br(e($m['body'])) ?></p></div></article>
  <?php foreach ($replies as $r): ?>
    <article class="panel message reply"><div class="panel-body">
      <p class="muted small-text"><strong><?= e($r['display_name'] ?? 'Former admin') ?></strong> replied <time datetime="<?= e(iso_date($r['created_at'])) ?>"><?= e(fmt_datetime($r['created_at'])) ?></time><?= (int) $r['sent_ok'] ? '' : ' · <span class="badge warn">queued for retry</span>' ?></p>
      <p class="message-body"><?= nl2br(e($r['body'])) ?></p>
    </div></article>
  <?php endforeach ?>
  <form class="panel" id="reply" method="post" action="/admin/inbox/<?= (int) $m['id'] ?>/reply" novalidate>
    <?= csrf_field() ?>
    <div class="panel-head"><h2>Reply by email</h2></div>
    <div class="panel-body fields">
      <?= field_html('subject', 'Subject', 'text', $subject, $err('subject'), ['max' => 200]) ?>
      <?= field_html('body', 'Your reply', 'textarea', $values['body'] ?? '', $err('body'), ['rows' => 8, 'max' => 10000, 'hint' => 'Sent to ' . $m['email'] . ' with your name and their original message (template “Reply from the inbox”). Replies to it go to the organisation’s contact address.']) ?>
      <div class="form-actions"><button class="btn accent" type="submit"><?= icon('mail') ?> Send reply</button></div>
    </div>
  </form>
</div>
