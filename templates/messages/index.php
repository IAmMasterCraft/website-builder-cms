<?php
/** @var array $ctx @var string $q @var array $rows @var int $blocked_count */
$base = $ctx['base'];
$kinds = ['group' => 'Group', 'announcement' => 'Announcement', 'team' => 'Team'];
?>
<?php if ($ctx['admin']): ?>
<div class="page-head">
  <div><h1>My messages</h1><p>Your own conversations with members, groups and other admins.</p></div>
  <div class="actions"><a class="btn accent" href="<?= e($base . '/new') ?>"><?= icon('plus') ?> New message</a></div>
</div>
<?php if (($team_unread ?? null) !== null): ?>
  <p class="dm-links"><a class="btn ghost small" href="/admin/messages/team"><?= icon('inbox') ?> Team inbox<?= $team_unread > 0 ? ' <span class="badge warn">' . (int) $team_unread . ' unread</span>' : '' ?></a>
    <a class="btn ghost small" href="/admin/messages/reports"><?= icon('flag') ?> Reported messages<?= $reports_open > 0 ? ' <span class="badge warn">' . (int) $reports_open . ' open</span>' : '' ?></a></p>
<?php endif ?>
<?php else: ?>
<div class="dm-actions-bar">
  <a class="btn accent" href="<?= e($base . '/new') ?>"><?= icon('plus') ?> New message</a>
  <?php if (msg_settings()['team_inbox']): ?><a class="btn ghost" href="<?= e($base . '/new?team=1') ?>"><?= icon('users') ?> Message the team</a><?php endif ?>
</div>
<?php endif ?>
<div class="dm dm-index<?= $ctx['admin'] ? ' panel' : '' ?>">
  <form class="dm-search" method="get" action="<?= e($base) ?>" role="search">
    <label for="dm-q" class="sr-only">Search messages</label>
    <div class="dm-search-row"><input type="search" id="dm-q" name="q" value="<?= e($q) ?>" placeholder="Search names and messages" maxlength="100"><button class="btn ghost small" type="submit"><?= icon('search') ?> Search</button></div>
  </form>
  <?php if ($rows === []): ?>
    <p class="dm-empty muted"><?= $q !== '' ? 'No conversations match “' . e($q) . '”.' : 'No messages yet. Start one with “New message”.' ?></p>
  <?php else: ?>
    <ul class="dm-convs">
      <?php foreach ($rows as $r): $unread = (int) $r['unread'] > 0 && !(int) $r['p_muted']; ?>
        <li class="<?= $unread ? 'is-unread' : '' ?>">
          <a href="<?= e($base . '/' . (int) $r['id'] . '#latest') ?>">
            <span class="dm-conv-top"><span class="dm-conv-title"><?php if ($unread): ?><span class="unread-dot" aria-hidden="true"></span><?php endif ?><?= e($r['display_title']) ?></span>
              <?php if (isset($kinds[$r['kind']])): ?><span class="badge muted"><?= e($kinds[$r['kind']]) ?></span><?php endif ?>
              <?php if ((int) $r['p_muted']): ?><span class="badge muted">Muted</span><?php endif ?>
              <time class="dm-conv-time tabular" datetime="<?= e(iso_date($r['last_message_at'])) ?>"><?= e(fmt_date($r['last_message_at'], 'j M, H:i')) ?></time></span>
            <span class="dm-conv-bottom"><span class="dm-preview"><?= e($r['preview']) ?></span>
              <?php if ((int) $r['unread'] > 0): ?><span class="count-pill tabular"><?= (int) $r['unread'] ?><span class="sr-only"> unread</span></span><?php endif ?></span>
          </a>
        </li>
      <?php endforeach ?>
    </ul>
  <?php endif ?>
  <p class="dm-footer-links"><a href="<?= e($base . '/blocked') ?>">Blocked people<?= $blocked_count > 0 ? ' (' . (int) $blocked_count . ')' : '' ?></a><?php if (!$ctx['admin']): ?> · <a href="/account/preferences">Message notification emails</a><?php endif ?></p>
</div>
<?php if ($ctx['admin']): ?>
<form class="panel dm-prefs" method="post" action="/admin/messages/preferences">
  <?= csrf_field() ?>
  <div class="panel-body">
    <label class="check-line"><input type="checkbox" name="message_emails" value="1"<?= !empty($message_emails) ? ' checked' : '' ?>> <span><strong>Email me about unread messages</strong><br><span class="muted">One email per conversation when a message has waited unread for 15 minutes<?= ($team_unread ?? null) !== null ? ', including new Team inbox messages' : '' ?>.</span></span></label>
    <p><button class="btn ghost small" type="submit">Save</button></p>
  </div>
</form>
<?php endif ?>
