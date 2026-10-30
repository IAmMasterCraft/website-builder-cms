<?php
/**
 * A conversation (C3): members inside the account shell, staff in the admin
 * (own conversations and Team inbox threads).
 * @var array $ctx @var array $conv @var bool $team_view @var string $base @var array $messages @var bool $has_earlier
 * @var array $participants @var ?array $other @var int $last_id @var bool $has_unread @var ?string $post_problem
 * @var string $act @var int $act_id @var array $values @var array $errors @var bool $manage @var string $add_q
 * @var array $add_results @var bool $i_blocked @var string $title_text @var string $poll_url @var string $read_url @var string $since
 */
$convId = (int) $conv['id'];
$url = $base . '/' . $convId;
$kindLabel = ['direct' => 'Conversation', 'group' => 'Group conversation', 'announcement' => 'Announcement group (only admins post)', 'team' => $team_view ? 'Team inbox' : 'Message to the team: any admin can answer'][$conv['kind']] ?? 'Conversation';
$listUrl = $team_view ? '/admin/messages/team' : $ctx['base'];
$isGroup = in_array($conv['kind'], ['group', 'announcement'], true);
$firstId = $messages !== [] ? (int) $messages[0]['id'] : 0;
?>
<?php if ($ctx['admin']): ?>
<div class="page-head">
  <div><p class="crumbs"><a href="<?= e($listUrl) ?>"><?= $team_view ? 'Team inbox' : 'Messages' ?></a></p><h1><?= e($title_text) ?></h1>
    <p><?= e($kindLabel) ?><?php if ($isGroup): ?> · <?= e(pluralise(count($participants), 'person', 'people')) ?><?php endif ?><?php if (!empty($team_member_url)): ?> · <a href="<?= e($team_member_url) ?>">Member record</a><?php endif ?></p></div>
</div>
<?php else: ?>
<p class="dm-back"><a href="<?= e($listUrl) ?>"><?= icon('chevron-left') ?> All messages</a></p>
<div class="dm-thread-title"><h2><?= e($title_text) ?></h2><p class="muted"><?= e($kindLabel) ?><?php if ($isGroup): ?> · <?= e(pluralise(count($participants), 'person', 'people')) ?><?php endif ?></p></div>
<?php endif ?>
<div class="dm dm-thread-page" data-dm-thread data-poll="<?= e($poll_url) ?>" data-read="<?= e($read_url) ?>" data-last="<?= (int) $last_id ?>" data-since="<?= e($since) ?>" data-base="<?= e($base) ?>" data-conv="<?= $convId ?>" data-readonly="<?= $ctx['readonly'] ? '1' : '0' ?>" data-can-act="<?= !$ctx['readonly'] && !$team_view ? '1' : '0' ?>">
  <?php if ($ctx['readonly']): ?><p class="notice warn dm-readonly" role="status"><?= icon('eye') ?> You are viewing this member’s messages read-only. This view is recorded in the audit log.</p><?php endif ?>
  <?php if ($has_earlier && $firstId > 0): ?><p class="dm-earlier"><a href="<?= e($url . '?before=' . $firstId) ?>">Show earlier messages</a></p><?php endif ?>
  <?php if (input_int('before', 0, true) > 0): ?><p class="dm-earlier"><a href="<?= e($url . '#latest') ?>">Back to the newest messages</a></p><?php endif ?>
  <div class="dm-log" role="log" aria-label="Messages">
    <ol class="dm-list" data-dm-list>
      <?php foreach ($messages as $m): ?><?= view('messages/_message', ['m' => $m, 'base' => $base, 'conv_id' => $convId, 'act' => $act, 'act_id' => $act_id, 'values' => $values, 'errors' => $errors]) ?><?php endforeach ?>
    </ol>
    <?php if ($messages === []): ?><p class="muted" data-dm-empty>No messages yet.</p><?php endif ?>
  </div>
  <span id="latest" tabindex="-1"></span>
  <?php if ($has_unread && !$ctx['readonly']): ?>
    <form class="dm-read-form" method="post" action="<?= e($read_url) ?>" data-dm-read-form><?= csrf_field() ?><input type="hidden" name="up_to" value="<?= (int) $last_id ?>"><button class="btn ghost small" type="submit"><?= icon('check') ?> Mark as read</button></form>
  <?php endif ?>

  <?php if ($post_problem === null): ?>
    <form id="composer" class="dm-composer" method="post" action="<?= e($url) ?>" enctype="multipart/form-data" data-dm-composer>
      <?= csrf_field() ?>
      <div class="field">
        <label for="dm-body"><?= $team_view ? 'Reply as the team' : 'Your message' ?></label>
        <textarea id="dm-body" name="body" rows="3" maxlength="<?= MSG_MAX ?>"<?= isset($errors['body']) ? ' aria-invalid="true" aria-describedby="dm-body-error"' : '' ?>><?= e((string) ($values['body'] ?? '')) ?></textarea>
        <?php if (isset($errors['body'])): ?><p class="error" id="dm-body-error"><?= e($errors['body']) ?></p><?php endif ?>
      </div>
      <div class="dm-composer-row">
        <div class="field dm-attach"><label for="dm-file">Attach a photo or PDF (optional)</label><input type="file" id="dm-file" name="attachment" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf"></div>
        <button class="btn accent" type="submit" data-dm-send><?= icon('send') ?> Send</button>
      </div>
      <p class="hint muted">Up to <?= number_format(MSG_MAX) ?> characters; web addresses become links. Photos and PDFs up to <?= MSG_ATTACH_MAX_BYTES / 1048576 ?> MB. Ctrl + Enter sends.</p>
      <p class="dm-status" role="status" data-dm-status></p>
    </form>
  <?php else: ?>
    <p class="notice dm-cannot" id="composer"><?= e($post_problem) ?></p>
  <?php endif ?>

  <?php if (!$team_view && !$ctx['readonly']): ?>
  <section class="dm-tools" id="people" aria-labelledby="dm-tools-h">
    <h2 id="dm-tools-h" class="dm-h"><?= $isGroup ? 'People and settings' : 'Settings' ?></h2>
    <?php if ($isGroup): ?>
      <ul class="dm-people">
        <?php foreach ($participants as $pp): $a = $pp['account']; ?>
          <li><span><?= e(msg_account_name($a)) ?><?php if ($a !== null && msg_is_staff_role((string) $a['role'])): ?> <span class="dm-role">Admin</span><?php endif ?><?php if ($pp['part_role'] === 'owner'): ?> <span class="muted">(created the group)</span><?php endif ?><?php if ((int) $pp['user_id'] === (int) $ctx['user']['id']): ?> <span class="muted">(you)</span><?php endif ?></span>
            <?php if ($manage && (int) $pp['user_id'] !== (int) $ctx['user']['id']): ?>
              <form method="post" action="<?= e($url . '/people/' . (int) $pp['user_id'] . '/remove') ?>" data-confirm="Remove <?= e(msg_account_name($a)) ?> from this conversation?"><?= csrf_field() ?><button class="btn ghost small" type="submit">Remove<span class="sr-only"> <?= e(msg_account_name($a)) ?></span></button></form>
            <?php endif ?></li>
        <?php endforeach ?>
      </ul>
      <?php if ($manage): ?>
        <form class="dm-add-search" method="get" action="<?= e($url) ?>#people" role="search">
          <div class="field"><label for="dm-add-q">Add people: search by name or member number</label>
            <div class="dm-search-row"><input type="search" id="dm-add-q" name="add_q" value="<?= e($add_q) ?>" maxlength="80"><button class="btn ghost small" type="submit"><?= icon('search') ?> Search</button></div></div>
        </form>
        <?php if ($add_q !== ''): ?>
          <form method="post" action="<?= e($url . '/people') ?>">
            <?= csrf_field() ?>
            <?php if ($add_results === []): ?><p class="muted">Nobody found that you can add.</p><?php else: ?>
              <fieldset class="dm-results"><legend>Choose who to add</legend>
                <?php foreach ($add_results as $r): ?><label class="check-line"><input type="checkbox" name="to[]" value="<?= (int) $r['id'] ?>"> <span><?= e($r['name']) ?><?= $r['number'] !== '' ? ' <span class="muted">' . e($r['number']) . '</span>' : '' ?><?= $r['kind'] === 'staff' ? ' <span class="dm-role">Admin</span>' : '' ?></span></label><?php endforeach ?>
              </fieldset>
              <p><button class="btn accent small" type="submit"><?= icon('plus') ?> Add to the conversation</button></p>
            <?php endif ?>
          </form>
        <?php endif ?>
      <?php endif ?>
    <?php endif ?>
    <div class="dm-tool-buttons">
      <form method="post" action="<?= e($url . '/mute') ?>"><?= csrf_field() ?><input type="hidden" name="muted" value="<?= (int) $conv['p_muted'] ? '0' : '1' ?>"><button class="btn ghost small" type="submit"><?= icon('bell') ?> <?= (int) $conv['p_muted'] ? 'Unmute' : 'Mute (no badge or emails)' ?></button></form>
      <?php if ($isGroup): ?>
        <form method="post" action="<?= e($url . '/leave') ?>" data-confirm="Leave this conversation? You will no longer see it."><?= csrf_field() ?><button class="btn ghost small" type="submit"><?= icon('logout') ?> Leave conversation</button></form>
      <?php elseif ($other !== null && (int) $other['id'] > 0 && !msg_is_staff_role((string) $other['role'])): ?>
        <?php if ($i_blocked): ?>
          <form method="post" action="<?= e($ctx['base'] . '/unblock/' . (int) $other['id']) ?>"><?= csrf_field() ?><button class="btn ghost small" type="submit">Unblock <?= e(msg_account_name($other)) ?></button></form>
        <?php else: ?>
          <form method="post" action="<?= e($ctx['base'] . '/block/' . (int) $other['id']) ?>" data-confirm="Block <?= e(msg_account_name($other)) ?>? They will not be able to message you and this conversation will be hidden."><?= csrf_field() ?><input type="hidden" name="back" value="<?= e($url) ?>"><button class="btn ghost small" type="submit"><?= icon('eye-off') ?> Block <?= e(msg_account_name($other)) ?></button></form>
        <?php endif ?>
      <?php endif ?>
    </div>
  </section>
  <?php endif ?>

  <template id="dm-msg-tpl">
    <li class="dm-msg"><div class="dm-bubble">
      <p class="dm-meta"><strong class="dm-sender"></strong> <span class="dm-role" hidden>Admin</span> <time class="dm-time"></time> <span class="dm-edited" hidden>· edited</span></p>
      <p class="dm-body"></p>
      <p class="dm-att" hidden><a target="_blank" rel="noopener"></a></p>
      <p class="dm-actions" hidden><a data-act="edit" hidden>Edit</a> <a data-act="delete" hidden>Delete</a> <a data-act="report" hidden>Report</a></p>
    </div></li>
  </template>
  <template id="dm-note-tpl"><li class="dm-note"><p><span class="dm-note-text"></span> <time class="dm-time"></time></p></li></template>
</div>
