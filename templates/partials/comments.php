<?php
/** @var string $type @var int $id @var ?array $viewer @var string $return @var ?array $error @var array $thread @var int $count */
$isMember = viewer_is_member($viewer);
$renderComment = static function (array $c, bool $isReply) use ($viewer, $isMember, $return, $type, $id, $error): string {
    if ($c['deleted_at'] !== null) {
        return '<article class="comment is-deleted" id="comment-' . (int) $c['id'] . '"><p class="comment-body muted">[deleted]</p></article>';
    }
    ob_start(); ?>
    <article class="comment<?= $c['status'] === 'hidden' ? ' is-pending' : '' ?>" id="comment-<?= (int) $c['id'] ?>">
      <header class="comment-head">
        <span class="comment-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($c['author'], 0, 1))) ?></span>
        <span><strong><?= e($c['author']) ?></strong><?= in_array($c['role'] ?? '', ['admin', 'super_admin'], true) ? ' <span class="badge brand">Team</span>' : '' ?><br>
          <time class="muted small-text" datetime="<?= e(iso_date($c['created_at'])) ?>"><?= e(fmt_date($c['created_at'], 'j M Y, H:i')) ?></time><?= $c['edited_at'] ? ' <span class="muted small-text">· edited</span>' : '' ?>
          <?= $c['status'] === 'hidden' ? ' <span class="badge warn">' . ($c['hidden_reason'] === 'reports' ? 'Hidden while a moderator reviews reports' : 'Hidden by a moderator') . ': only you can see this</span>' : '' ?></span>
      </header>
      <p class="comment-body"><?= nl2br(e($c['body'])) ?></p>
      <?php if ($isMember): ?>
      <div class="comment-actions">
        <?php if (!$isReply && $c['status'] === 'approved'): ?>
          <details class="comment-reply"<?= ($error['parent'] ?? 0) === (int) $c['id'] ? ' open' : '' ?>><summary>Reply</summary>
            <form method="post" action="/comments" class="comment-form">
              <?= csrf_field() ?><input type="hidden" name="item_type" value="<?= e($type) ?>"><input type="hidden" name="item_id" value="<?= (int) $id ?>"><input type="hidden" name="parent_id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="return" value="<?= e($return) ?>">
              <label for="reply-<?= (int) $c['id'] ?>" class="sr-only">Reply to <?= e($c['author']) ?></label>
              <textarea id="reply-<?= (int) $c['id'] ?>" name="body" rows="3" maxlength="<?= COMMENT_MAX ?>" required><?= ($error['parent'] ?? 0) === (int) $c['id'] ? e($error['body']) : '' ?></textarea>
              <?php if (($error['parent'] ?? 0) === (int) $c['id']): ?><p class="error"><?= e($error['message']) ?></p><?php endif ?>
              <button class="btn small" type="submit">Post reply</button>
            </form>
          </details>
        <?php endif ?>
        <?php if (comment_editable($c, $viewer)): ?>
          <details class="comment-edit"><summary>Edit</summary>
            <form method="post" action="/comments/<?= (int) $c['id'] ?>/edit" class="comment-form">
              <?= csrf_field() ?><input type="hidden" name="return" value="<?= e($return) ?>">
              <label for="edit-<?= (int) $c['id'] ?>" class="sr-only">Edit your comment</label>
              <textarea id="edit-<?= (int) $c['id'] ?>" name="body" rows="3" maxlength="<?= COMMENT_MAX ?>" required><?= e($c['body']) ?></textarea>
              <p class="hint muted small-text">You can edit for <?= COMMENT_EDIT_MINUTES ?> minutes after posting.</p>
              <button class="btn small" type="submit">Save</button>
            </form>
          </details>
        <?php endif ?>
        <?php if ((int) ($c['user_id'] ?? 0) === (int) $viewer['id']): ?>
          <form method="post" action="/comments/<?= (int) $c['id'] ?>/delete" data-confirm="Delete your comment?"><?= csrf_field() ?><input type="hidden" name="return" value="<?= e($return) ?>"><button class="link-btn" type="submit">Delete</button></form>
        <?php elseif ($c['status'] === 'approved'): ?>
          <?php if ((int) $c['reported_by_me'] > 0): ?><span class="muted small-text"><?= icon('flag') ?> Reported</span>
          <?php else: ?>
          <details class="comment-report"><summary>Report</summary>
            <form method="post" action="/comments/<?= (int) $c['id'] ?>/report" class="comment-form">
              <?= csrf_field() ?><input type="hidden" name="return" value="<?= e($return) ?>">
              <label for="report-<?= (int) $c['id'] ?>">Why should a moderator look at this?</label>
              <input type="text" id="report-<?= (int) $c['id'] ?>" name="reason" maxlength="500">
              <button class="btn small ghost" type="submit">Send report</button>
            </form>
          </details>
          <?php endif ?>
        <?php endif ?>
      </div>
      <?php endif ?>
    </article>
    <?php return (string) ob_get_clean();
};
?>
<section class="comments" id="comments" aria-labelledby="comments-title">
  <h2 id="comments-title"><?= icon('help') ?> Comments <span class="muted tabular">(<?= (int) $count ?>)</span></h2>
  <?php if (!$isMember): ?>
    <div class="notice"><p><?= $count > 0 ? 'Members are talking about this. ' : '' ?>Comments are for members.</p>
      <?php if ($viewer === null): ?><p class="btn-row"><a class="btn small" href="/login?next=<?= e(rawurlencode($return . '#comments')) ?>">Log in</a> <a class="btn small ghost" href="/join">Become a member</a></p><?php else: ?><p>Verify your email to join the conversation.</p><?php endif ?></div>
  <?php else: ?>
    <?php if ($thread): ?>
      <ol class="comment-list">
        <?php foreach ($thread as $c): ?>
          <li><?= $renderComment($c, false) ?>
            <?php if ($c['replies']): ?><ol class="reply-list"><?php foreach ($c['replies'] as $r): ?><li><?= $renderComment($r, true) ?></li><?php endforeach ?></ol><?php endif ?>
          </li>
        <?php endforeach ?>
      </ol>
    <?php else: ?><p class="muted">No comments yet. Start the conversation.</p><?php endif ?>
    <form method="post" action="/comments" class="comment-form new-comment">
      <?= csrf_field() ?><input type="hidden" name="item_type" value="<?= e($type) ?>"><input type="hidden" name="item_id" value="<?= (int) $id ?>"><input type="hidden" name="return" value="<?= e($return) ?>">
      <label for="new-comment">Add a comment</label>
      <textarea id="new-comment" name="body" rows="4" maxlength="<?= COMMENT_MAX ?>" required<?= ($error && !$error['parent']) ? ' aria-invalid="true" aria-describedby="new-comment-err"' : '' ?>><?= ($error && !$error['parent']) ? e($error['body']) : '' ?></textarea>
      <?php if ($error && !$error['parent']): ?><p class="error" id="new-comment-err"><?= e($error['message']) ?></p><?php endif ?>
      <p class="hint muted small-text">Plain text, be kind. Your comment appears straight away; anyone can report a comment to the moderators.</p>
      <button class="btn" type="submit">Post comment</button>
    </form>
  <?php endif ?>
</section>
