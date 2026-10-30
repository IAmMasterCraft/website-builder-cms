<?php
/**
 * One message (C3). messages.js builds the same markup from the
 * <template id="dm-msg-tpl"> in thread.php for messages that arrive later.
 * @var array $m presented by msg_present() @var string $base @var int $conv_id
 * @var string $act @var int $act_id @var array $values @var array $errors
 */
$id = (int) $m['id'];
$url = $base . '/' . $conv_id;
$open = ($act ?? '') !== '' && (int) ($act_id ?? 0) === $id;
?>
<?php if ($m['note']): ?>
<li class="dm-note" id="m-<?= $id ?>" data-id="<?= $id ?>"><p><span class="dm-note-text"><?= e($m['body']) ?></span> <time class="dm-time" datetime="<?= e($m['created_iso']) ?>"><?= e($m['time_label']) ?></time></p></li>
<?php else: ?>
<li class="dm-msg<?= $m['mine'] ? ' is-mine' : '' ?><?= !empty($m['reported']) ? ' is-reported' : '' ?>" id="m-<?= $id ?>" data-id="<?= $id ?>">
  <div class="dm-bubble">
    <p class="dm-meta"><strong class="dm-sender"><?= $m['mine'] ? 'You' : e($m['sender']) ?></strong><?php if ($m['staff']): ?> <span class="dm-role">Admin</span><?php endif ?>
      <time class="dm-time" datetime="<?= e($m['created_iso']) ?>"><?= e($m['time_label']) ?></time><?php if ($m['edited']): ?> <span class="dm-edited">· edited</span><?php endif ?><?php if (!empty($m['reported'])): ?> <span class="badge warn">Reported</span><?php endif ?></p>
    <?php if ($m['deleted']): ?>
      <p class="dm-body dm-deleted"><em><?= e($m['deleted_label']) ?></em></p>
    <?php elseif ($open && $act === 'edit' && $m['can_edit']): ?>
      <form class="dm-inline-form" method="post" action="<?= e($url . '/m/' . $id . '/edit') ?>">
        <?= csrf_field() ?>
        <div class="field"><label for="dm-edit-<?= $id ?>">Edit your message</label>
          <textarea id="dm-edit-<?= $id ?>" name="body" rows="3" maxlength="<?= MSG_MAX ?>"><?= e($m['body']) ?></textarea></div>
        <p class="dm-inline-actions"><button class="btn accent small" type="submit"><?= icon('check') ?> Save</button> <a class="btn ghost small" href="<?= e($url . '#m-' . $id) ?>">Cancel</a></p>
      </form>
    <?php else: ?>
      <?php if ($m['body'] !== ''): ?><p class="dm-body"><?= msg_body_html($m['body']) ?></p><?php endif ?>
    <?php endif ?>
    <?php if ($m['attachment'] !== null): $a = $m['attachment']; ?>
      <p class="dm-att"><a href="<?= e($a['url']) ?>" target="_blank" rel="noopener"><?php if ($a['thumb'] !== null): ?><img src="<?= e($a['thumb']) ?>" alt="Photo: <?= e($a['name']) ?> (opens in a new tab)" loading="lazy" width="200"><?php else: ?><?= icon('file') ?> <span><?= e($a['name']) ?></span><span class="sr-only"> (opens in a new tab)</span><?php endif ?></a></p>
    <?php endif ?>
    <?php if ($open && $act === 'delete' && $m['can_delete']): ?>
      <form class="dm-inline-form" method="post" action="<?= e($url . '/m/' . $id . '/delete') ?>">
        <?= csrf_field() ?>
        <p>Delete this message? Everyone will see “Message deleted” instead.</p>
        <p class="dm-inline-actions"><button class="btn danger small" type="submit"><?= icon('trash') ?> Delete</button> <a class="btn ghost small" href="<?= e($url . '#m-' . $id) ?>">Cancel</a></p>
      </form>
    <?php elseif ($open && $act === 'report' && $m['can_report']): ?>
      <form class="dm-inline-form" method="post" action="<?= e($url . '/m/' . $id . '/report') ?>">
        <?= csrf_field() ?>
        <div class="field"><label for="dm-reason-<?= $id ?>">Why are you reporting this message? (optional)</label>
          <textarea id="dm-reason-<?= $id ?>" name="reason" rows="2" maxlength="500"></textarea>
          <p class="hint">Admins who handle reports will read this message and the few messages before and after it, nothing else.</p></div>
        <p class="dm-inline-actions"><button class="btn accent small" type="submit"><?= icon('flag') ?> Report</button> <a class="btn ghost small" href="<?= e($url . '#m-' . $id) ?>">Cancel</a></p>
      </form>
    <?php elseif ($m['can_edit'] || $m['can_delete'] || $m['can_report']): ?>
      <p class="dm-actions">
        <?php if ($m['can_edit']): ?><a href="<?= e($url . '?act=edit&m=' . $id . '#m-' . $id) ?>">Edit<span class="sr-only"> your message from <?= e($m['time_label']) ?></span></a><?php endif ?>
        <?php if ($m['can_delete']): ?><a href="<?= e($url . '?act=delete&m=' . $id . '#m-' . $id) ?>">Delete<span class="sr-only"> your message from <?= e($m['time_label']) ?></span></a><?php endif ?>
        <?php if ($m['can_report']): ?><a href="<?= e($url . '?act=report&m=' . $id . '#m-' . $id) ?>">Report<span class="sr-only"> message from <?= e($m['sender']) ?>, <?= e($m['time_label']) ?></span></a><?php endif ?>
      </p>
    <?php endif ?>
  </div>
</li>
<?php endif ?>
