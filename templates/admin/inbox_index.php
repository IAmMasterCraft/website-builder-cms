<?php /** @var array $rows @var string $filter @var string $q @var int $total @var array $counts @var int $page @var int $per */
$pages = (int) ceil($total / $per);
?>
<div class="page-head"><div><h1>Inbox</h1><p>Messages sent with the website contact form.</p></div></div>
<div class="panel">
  <?= tabs_html('/admin/inbox', 'show', $filter, ['' => ['All', (int) $counts['total']], 'unread' => ['Unread', (int) $counts['unread']], 'replied' => ['Replied', (int) $counts['replied']]], ['q' => $q]) ?>
  <form class="toolbar" method="get" action="/admin/inbox" role="search">
    <?php if ($filter !== ''): ?><input type="hidden" name="show" value="<?= e($filter) ?>"><?php endif ?>
    <label for="iq" class="sr-only">Search messages</label><input type="search" id="iq" name="q" value="<?= e($q) ?>" placeholder="Name, email or text" maxlength="100">
    <button class="btn small ghost" type="submit">Search</button>
  </form>
  <ul class="inbox-list">
    <?php if (!$rows): ?><li class="empty-row">No messages here.</li><?php endif ?>
    <?php foreach ($rows as $m): ?>
      <li class="<?= $m['read_at'] === null ? 'unread' : '' ?>">
        <a href="/admin/inbox/<?= (int) $m['id'] ?>">
          <span class="inbox-from"><?php if ($m['read_at'] === null): ?><span class="unread-dot" aria-hidden="true"></span><span class="sr-only">Unread: </span><?php endif ?><?= e($m['name']) ?></span>
          <span class="inbox-subject"><?= e($m['subject']) ?> <span class="muted">— <?= e(excerpt($m['body'], 90)) ?></span></span>
          <span class="inbox-meta"><?= $m['replied_at'] ? '<span class="badge ok">replied</span> ' : '' ?><time class="tabular" datetime="<?= e(iso_date($m['created_at'])) ?>"><?= e(fmt_date($m['created_at'], 'j M, H:i')) ?></time></span>
        </a>
      </li>
    <?php endforeach ?>
  </ul>
  <?php if ($pages > 1): ?><div class="pager"><span class="tabular">Page <?= (int) $page ?> of <?= $pages ?></span><span class="btn-row">
    <?php if ($page > 1): ?><a class="btn small ghost" href="<?= e(url_with('/admin/inbox', ['show' => $filter, 'q' => $q], ['page' => $page - 1])) ?>">Previous</a><?php endif ?>
    <?php if ($page < $pages): ?><a class="btn small ghost" href="<?= e(url_with('/admin/inbox', ['show' => $filter, 'q' => $q], ['page' => $page + 1])) ?>">Next</a><?php endif ?>
  </span></div><?php endif ?>
</div>
