<?php /** @var array $rows @var string $tab @var array $counts @var array $reports @var string $mode */
$tabs = [];
foreach (\Admin\Comments::TABS as $k => $l) { $tabs[$k] = [$l, $counts[$k] ?? 0]; }
?>
<div class="page-head"><div><h1>Comments</h1><p>Members’ comments on posts. <?= $mode === 'pre' ? 'New comments wait for approval before anyone else sees them.' : 'New comments appear straight away; check reports here.' ?></p></div></div>
<div class="dash-grid">
  <div class="panel">
    <?= tabs_html('/admin/comments', 'tab', $tab, $tabs) ?>
    <?php if (!$rows): ?><p class="empty-row">Nothing to moderate.</p><?php endif ?>
    <ul class="mod-list">
      <?php foreach ($rows as $c): $url = $c['item_slug'] ? '/' . $c['item_base'] . '/' . $c['item_slug'] . '#comment-' . (int) $c['id'] : null; ?>
        <li>
          <div class="mod-head">
            <p class="small-text"><strong><?= e(comment_author_name($c)) ?></strong> on <?= $url ? '<a href="' . e($url) . '" target="_blank" rel="noopener">' . e($c['item_title']) . '</a>' : e((string) ($c['item_title'] ?? 'a deleted item')) ?>
              · <time datetime="<?= e(iso_date($c['created_at'])) ?>"><?= e(fmt_date($c['created_at'], 'j M, H:i')) ?></time><?= $c['edited_at'] ? ' · edited' : '' ?><?= $c['parent_id'] ? ' · reply' : '' ?></p>
            <span class="badge <?= e(['approved' => 'published', 'pending' => 'scheduled', 'rejected' => 'bad'][$c['status']] ?? '') ?>"><?= e($c['status'] === 'approved' ? 'published' : $c['status']) ?></span>
          </div>
          <?php if ($c['parent_body']): ?><blockquote class="quote small-text">In reply to: <?= e(excerpt($c['parent_body'], 140)) ?></blockquote><?php endif ?>
          <p class="message-body"><?= nl2br(e($c['body'])) ?></p>
          <?php if (isset($reports[(int) $c['id']])): ?>
            <div class="report-box"><p><strong><?= icon('flag') ?> <?= e(pluralise(count($reports[(int) $c['id']]), 'report')) ?></strong></p>
              <ul><?php foreach ($reports[(int) $c['id']] as $r): ?><li><?= e($r['display_name'] ?? 'Member') ?>: <?= e($r['reason'] !== '' ? $r['reason'] : '(no reason)') ?></li><?php endforeach ?></ul></div>
          <?php endif ?>
          <form class="btn-row" method="post" action="/admin/comments/<?= (int) $c['id'] ?>">
            <?= csrf_field() ?><input type="hidden" name="tab" value="<?= e($tab) ?>">
            <?php if ($c['status'] !== 'approved' || isset($reports[(int) $c['id']])): ?><button class="btn small accent" type="submit" name="action" value="approve"><?= icon('check') ?> <?= $c['status'] === 'approved' ? 'Keep' : 'Approve' ?></button><?php endif ?>
            <?php if ($c['status'] !== 'rejected'): ?><button class="btn small danger-ghost" type="submit" name="action" value="reject">Reject</button><?php endif ?>
            <button class="btn small ghost" type="submit" name="action" value="delete"><?= icon('trash') ?> Delete</button>
          </form>
        </li>
      <?php endforeach ?>
    </ul>
  </div>
  <form class="panel" method="post" action="/admin/comments/settings">
    <?= csrf_field() ?>
    <div class="panel-head"><h2>Moderation</h2></div>
    <div class="panel-body fields">
      <fieldset class="field"><legend>New comments</legend>
        <label class="check-line"><input type="radio" name="moderation" value="pre"<?= $mode === 'pre' ? ' checked' : '' ?>> <span><strong>Approve first</strong><br><span class="muted">Comments appear after an admin approves them (recommended).</span></span></label>
        <label class="check-line"><input type="radio" name="moderation" value="post"<?= $mode === 'post' ? ' checked' : '' ?>> <span><strong>Publish straight away</strong><br><span class="muted">Remove problems afterwards, using reports.</span></span></label>
      </fieldset>
      <p class="hint muted">Comments are plain text, members only, with one level of replies. Authors can edit for <?= COMMENT_EDIT_MINUTES ?> minutes. Switch comments on or off per post in the post editor.</p>
      <button class="btn ghost" type="submit"><?= icon('check') ?> Save</button>
    </div>
  </form>
</div>
