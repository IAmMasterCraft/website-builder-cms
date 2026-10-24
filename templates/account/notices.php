<?php /** @var array $notices */
$unread = count(array_filter($notices, static fn (array $n): bool => $n['read_at'] === null));
?>
<?php if (!$notices): ?>
  <?= view('partials/empty', ['message' => 'No notices yet. Messages from the committee will appear here.']) ?>
<?php else: ?>
  <div class="dash-card-head"><p class="muted" data-unread-summary aria-live="polite"><?= $unread ? e(pluralise($unread, 'unread notice')) : 'You are all caught up.' ?></p>
    <?php if ($unread): ?><form method="post" action="/account/notices/read"><?= csrf_field() ?><button class="btn small ghost" type="submit"><?= icon('check') ?> Mark all as read</button></form><?php endif ?></div>
  <ul class="notice-list">
    <?php foreach ($notices as $n): $isUnread = $n['read_at'] === null; ?>
      <li id="notice-<?= (int) $n['id'] ?>">
        <article class="dash-card notice-card<?= $isUnread ? ' is-unread' : '' ?>"<?= $isUnread ? ' data-unread-notice="' . (int) $n['id'] . '"' : '' ?>>
          <header><h2 class="h3"><?php if ($isUnread): ?><span class="unread-dot" aria-hidden="true"></span><span class="sr-only">Unread: </span><?php endif ?><?= e($n['title']) ?></h2>
            <time class="muted small-text tabular" datetime="<?= e(iso_date($n['published_at'])) ?>"><?= e(fmt_date($n['published_at'], 'j F Y')) ?></time></header>
          <div class="prose"><?= rich($n['body']) ?></div>
          <?php if ($isUnread): ?><form method="post" action="/account/notices/read" class="notice-read-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $n['id'] ?>"><button class="btn small ghost" type="submit">Mark as read<span class="sr-only">: <?= e($n['title']) ?></span></button></form><?php endif ?>
        </article>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>
