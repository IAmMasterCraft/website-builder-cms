<?php
/** @var array $chrome @var string $content @var string $title */
$c = $chrome;
$u = $c['user'];
// B1: the most specific matching link is current; its group is the "active parent".
[$activeHref, $activeGroup] = $c['nav_active'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($title ?? '') !== '' ? $title . ' · ' : '') ?>Admin · <?= e($c['site_name']) ?></title>
<meta name="robots" content="noindex">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<?= favicon_head_tags() ?>
<link rel="stylesheet" href="<?= e(asset('assets/css/admin.css')) ?>">
<?php foreach ($extra_css ?? [] as $css): ?><link rel="stylesheet" href="<?= e(asset($css)) ?>">
<?php endforeach ?>
<script src="<?= e(asset('assets/js/admin-boot.js')) ?>"></script>
<script type="module" src="<?= e(asset('assets/js/admin.js')) ?>"></script>
</head>
<body class="admin<?= !empty($body_class) ? ' ' . e($body_class) : '' ?>">
<a class="skip-link" href="#main">Skip to content</a>
<div class="shell">
  <aside class="sidebar" id="sidebar" aria-label="Admin navigation">
    <div class="sidebar-head">
      <a class="logo" href="/admin" title="<?= e($c['site_name']) ?> admin"><?php if ($c['tile_url'] !== null): ?><img class="logo-mark logo-img" src="<?= e($c['tile_url']) ?>" alt="" width="34" height="34"><?php else: ?><span class="logo-mark" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($c['site_name'], 0, 1))) ?></span><?php endif ?><span class="logo-text"><?= e($c['site_name']) ?></span></a>
    </div>
    <nav class="side-nav" aria-label="Admin sections">
      <div class="nav-tools" data-nav-tools hidden>
        <button type="button" class="nav-all" data-nav-all aria-controls="<?= e(implode(' ', array_map(static fn (array $g): string => 'nav-g-' . $g['key'], $c['nav']))) ?>"><?= icon('chevron-down', 'icon when-collapsed') ?><?= icon('chevron-up', 'icon when-expanded') ?><span data-nav-all-label>Expand all</span></button>
      </div>
      <ul class="nav-groups">
        <?php foreach ($c['nav'] as $g): $isActive = $g['key'] === $activeGroup; $gid = 'nav-g-' . $g['key']; ?>
          <li class="nav-group<?= $isActive ? ' is-active-parent' : '' ?>" data-group="<?= e($g['key']) ?>">
            <p class="nav-section" id="<?= e($gid) ?>-label"><?= e($g['label']) ?></p>
            <button type="button" class="nav-group-toggle" aria-expanded="<?= $isActive ? 'true' : 'false' ?>" aria-controls="<?= e($gid) ?>"><?= icon($g['icon'], 'icon group-icon') ?><span class="nav-group-label"><?= e($g['label']) ?><?= $isActive ? '<span class="sr-only"> (current section)</span>' : '' ?></span><?= icon('chevron-down', 'icon chev') ?></button>
            <ul class="nav-items" id="<?= e($gid) ?>" aria-labelledby="<?= e($gid) ?>-label" data-label="<?= e($g['label']) ?>">
              <?php foreach ($g['links'] as [$href, $label, $iconName]): $active = $href === $activeHref; ?>
                <li><a href="<?= e($href) ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= icon($iconName) ?><span><?= e($label) ?></span></a></li>
              <?php endforeach ?>
            </ul>
          </li>
        <?php endforeach ?>
      </ul>
      <ul class="nav-extra">
        <li><a href="/admin/help"<?= str_starts_with($c['path'], '/admin/help') ? ' aria-current="page"' : '' ?>><?= icon('book-open') ?><span>Help centre</span></a></li>
      </ul>
    </nav>
  </aside>
  <div class="main-col">
    <header class="topbar">
      <button type="button" class="icon-btn" data-sidebar-toggle aria-controls="sidebar" aria-expanded="true"><?= icon('sidebar') ?><span class="sr-only">Toggle sidebar</span></button>
      <span class="site-title"><?= e($c['site_name']) ?></span>
      <form class="top-search" role="search" method="get" action="/admin/search">
        <label for="admin-q" class="sr-only">Search content</label>
        <?= icon('search') ?><input type="search" id="admin-q" name="q" placeholder="Search content…" maxlength="100" value="<?= e(request_path() === '/admin/search' ? query_param('q') : '') ?>">
      </form>
      <div class="top-actions">
        <a class="btn ghost small" href="/" target="_blank" rel="noopener" aria-label="View site (opens in a new tab)"><?= icon('external') ?><span class="hide-sm">View site</span></a>
        <a class="icon-btn" href="/admin/messages" data-dm-badge-link><?= icon('message') ?><span class="dot tabular" data-dm-badge data-dm-src="/admin/messages/unread"<?= $c['messages_unread'] > 0 ? '' : ' hidden' ?>><?= (int) $c['messages_unread'] ?></span><span class="sr-only" data-dm-badge-label><?= $c['messages_unread'] > 0 ? e(pluralise((int) $c['messages_unread'], 'unread message')) : 'Messages' ?></span></a>
        <a class="icon-btn" href="/admin#needs-attention"><?= icon('bell') ?><?php if ($c['notifications'] > 0): ?><span class="dot tabular"><?= (int) $c['notifications'] ?></span><?php endif ?><span class="sr-only"><?= (int) $c['notifications'] ?> items need attention</span></a>
        <button type="button" class="icon-btn" data-theme-toggle><?= icon('moon', 'icon when-light') ?><?= icon('sun', 'icon when-dark') ?><span class="sr-only">Switch light or dark mode</span></button>
        <details class="profile">
          <summary aria-label="Account menu: <?= e($u['display_name']) ?>"><span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $u['display_name'], 0, 1))) ?></span><span class="hide-sm"><?= e($u['display_name']) ?></span></summary>
          <div class="menu">
            <p><strong><?= e($u['display_name']) ?></strong><br><span class="muted"><?= e($u['email']) ?></span><br><span class="badge"><?= e(str_replace('_', ' ', $u['role'])) ?></span></p>
            <div class="btn-row"><a class="btn ghost small" href="/admin/profile"><?= icon('user') ?> My profile</a>
            <form method="post" action="/logout"><?= csrf_field() ?><button type="submit" class="btn ghost small"><?= icon('logout') ?> Log out</button></form></div>
          </div>
        </details>
      </div>
    </header>
    <main id="main" tabindex="-1">
      <?php if ($c['flash']): ?><div class="flash <?= e($c['flash']['type']) ?>" role="status"><?= icon($c['flash']['type'] === 'error' ? 'alert' : 'check') ?><span><?= e($c['flash']['message']) ?></span></div><?php endif ?>
      <?= $content ?>
    </main>
    <footer class="admin-footer"><p>Powered by <a href="/admin/help">Gather</a></p></footer>
  </div>
</div>
</body>
</html>
