<?php /** @var string $show @var string $q @var array $rows @var int $unread_count @var bool $enabled */ ?>
<div class="page-head"><div><h1>Team inbox</h1><p>Messages members sent to the organisation. Any admin with the Messages permission can answer; replies show your name with “Admin”. Reading a thread marks it read for the whole team.</p></div></div>
<?php if (!$enabled): ?><p class="notice-box">The Team inbox is switched off in Message settings: members cannot start new team conversations, but existing threads can still be answered.</p><?php endif ?>
<div class="panel dm">
  <?= tabs_html('/admin/messages/team', 'show', $show, ['' => ['All', null], 'unread' => ['Unread', $unread_count]], ['q' => $q]) ?>
  <form class="toolbar" method="get" action="/admin/messages/team" role="search">
    <?php if ($show !== ''): ?><input type="hidden" name="show" value="<?= e($show) ?>"><?php endif ?>
    <label for="tq" class="sr-only">Search the Team inbox</label><input type="search" id="tq" name="q" value="<?= e($q) ?>" placeholder="Name, member number or text" maxlength="100">
    <button class="btn small ghost" type="submit">Search</button>
  </form>
  <?php if ($rows === []): ?><p class="dm-empty muted">No conversations here.</p><?php else: ?>
  <ul class="dm-convs">
    <?php foreach ($rows as $r): $unread = (int) $r['unread'] > 0; ?>
      <li class="<?= $unread ? 'is-unread' : '' ?>"><a href="/admin/messages/team/<?= (int) $r['id'] ?>#latest">
        <span class="dm-conv-top"><span class="dm-conv-title"><?php if ($unread): ?><span class="unread-dot" aria-hidden="true"></span><?php endif ?><?= e($r['display_title']) ?></span>
          <time class="dm-conv-time tabular" datetime="<?= e(iso_date($r['last_message_at'])) ?>"><?= e(fmt_date($r['last_message_at'], 'j M, H:i')) ?></time></span>
        <span class="dm-conv-bottom"><span class="dm-preview"><?= e($r['preview']) ?></span><?php if ($unread): ?><span class="count-pill tabular"><?= (int) $r['unread'] ?><span class="sr-only"> unread</span></span><?php endif ?></span>
      </a></li>
    <?php endforeach ?>
  </ul>
  <?php endif ?>
</div>
