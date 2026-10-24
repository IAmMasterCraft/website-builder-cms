<?php /** @var array $rows @var string $tab @var array $counts @var array $reports */
$tabs = [];
foreach (\Admin\Comments::TABS as $k => $l) { $tabs[$k] = [$l, $counts[$k] ?? 0]; }
?>
<div class="page-head"><div><h1>Comments</h1><p>Members’ comments appear straight away. Reported comments wait here for you to keep or hide them; a comment reported by <?= COMMENT_AUTO_HIDE_REPORTS ?> different members is hidden automatically until you decide.</p></div></div>
<div class="panel">
  <?= tabs_html('/admin/comments', 'tab', $tab, $tabs) ?>
  <?php if (!$rows): ?><p class="empty-row"><?= $tab === 'reported' ? 'No open reports.' : 'Nothing here.' ?></p><?php endif ?>
  <ul class="mod-list">
    <?php foreach ($rows as $c): $url = $c['item_slug'] ? '/' . $c['item_base'] . '/' . $c['item_slug'] . '#comment-' . (int) $c['id'] : null; ?>
      <li>
        <div class="mod-head">
          <p class="small-text"><strong><?= e(comment_author_name($c)) ?></strong> on <?= $url ? '<a href="' . e($url) . '" target="_blank" rel="noopener">' . e($c['item_title']) . '</a>' : e((string) ($c['item_title'] ?? 'a deleted item')) ?>
            · <time datetime="<?= e(iso_date($c['created_at'])) ?>"><?= e(fmt_date($c['created_at'], 'j M, H:i')) ?></time><?= $c['edited_at'] ? ' · edited' : '' ?><?= $c['parent_id'] ? ' · reply' : '' ?></p>
          <?php if ($c['status'] === 'hidden'): ?>
            <span class="badge bad"><?= $c['hidden_reason'] === 'reports' ? 'hidden after reports' : 'hidden' ?></span>
          <?php else: ?><span class="badge published">published</span><?php endif ?>
        </div>
        <?php if ($c['parent_body']): ?><blockquote class="quote small-text">In reply to: <?= e(excerpt($c['parent_body'], 140)) ?></blockquote><?php endif ?>
        <p class="message-body"><?= nl2br(e($c['body'])) ?></p>
        <?php if (isset($reports[(int) $c['id']])): ?>
          <div class="report-box"><p><strong><?= icon('flag') ?> <?= e(pluralise(count($reports[(int) $c['id']]), 'report')) ?></strong></p>
            <ul><?php foreach ($reports[(int) $c['id']] as $r): ?><li><?= e($r['display_name'] ?? 'Member') ?>: <?= e($r['reason'] !== '' ? $r['reason'] : '(no reason)') ?></li><?php endforeach ?></ul></div>
        <?php endif ?>
        <form class="btn-row" method="post" action="/admin/comments/<?= (int) $c['id'] ?>">
          <?= csrf_field() ?><input type="hidden" name="tab" value="<?= e($tab) ?>">
          <?php if (isset($reports[(int) $c['id']])): ?><button class="btn small accent" type="submit" name="action" value="keep"><?= icon('check') ?> Keep<?= $c['status'] === 'hidden' && $c['hidden_reason'] === 'reports' ? ' and show again' : '' ?></button><?php endif ?>
          <?php if ($c['status'] === 'approved'): ?><button class="btn small danger-ghost" type="submit" name="action" value="hide">Hide</button>
          <?php elseif (!isset($reports[(int) $c['id']])): ?><button class="btn small ghost" type="submit" name="action" value="unhide">Show again</button><?php endif ?>
          <button class="btn small ghost" type="submit" name="action" value="delete"><?= icon('trash') ?> Delete</button>
        </form>
      </li>
    <?php endforeach ?>
  </ul>
  <p class="panel-body hint muted">Comments are plain text, members only, with one level of replies. Authors can edit for <?= COMMENT_EDIT_MINUTES ?> minutes. Switch comments on or off per post in the post editor.</p>
</div>
