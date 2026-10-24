<?php
/** @var array $chrome @var string $content @var string $title */
$c = $chrome;
$u = $c['user'];
// Highlight only the most specific matching link (e.g. /admin/radio/playlists, not /admin/radio too).
$activeHref = '';
foreach ($c['nav'] as $links) {
    foreach ($links as [$href]) {
        $match = $href === '/admin' ? $c['path'] === '/admin' : ($c['path'] === $href || str_starts_with($c['path'], $href . '/'));
        if ($match && strlen($href) > strlen($activeHref)) {
            $activeHref = $href;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($title ?? '') !== '' ? $title . ' · ' : '') ?>Admin · <?= e($c['site_name']) ?></title>
<meta name="robots" content="noindex">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<link rel="icon" href="<?= e(asset('assets/img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('assets/css/admin.css')) ?>">
<script src="<?= e(asset('assets/js/admin-boot.js')) ?>"></script>
<script type="module" src="<?= e(asset('assets/js/admin.js')) ?>"></script>
</head>
<body class="admin<?= !empty($body_class) ? ' ' . e($body_class) : '' ?>">
<a class="skip-link" href="#main">Skip to content</a>
<div class="shell">
  <aside class="sidebar" id="sidebar" aria-label="Admin navigation">
    <div class="sidebar-head">
      <a class="logo" href="/admin"><span class="logo-mark" aria-hidden="true">G</span><span class="logo-text">Gather</span></a>
    </div>
    <nav>
      <?php foreach ($c['nav'] as $section => $links): ?>
        <p class="nav-section"><?= e($section) ?></p>
        <ul>
          <?php foreach ($links as [$href, $label, $iconName]): $active = $href === $activeHref; ?>
            <li><a href="<?= e($href) ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= icon($iconName) ?><span><?= e($label) ?></span></a></li>
          <?php endforeach ?>
        </ul>
      <?php endforeach ?>
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
        <a class="icon-btn" href="/admin#needs-attention"><?= icon('bell') ?><?php if ($c['notifications'] > 0): ?><span class="dot tabular"><?= (int) $c['notifications'] ?></span><?php endif ?><span class="sr-only"><?= (int) $c['notifications'] ?> items need attention</span></a>
        <button type="button" class="icon-btn" data-theme-toggle><?= icon('moon', 'icon when-light') ?><?= icon('sun', 'icon when-dark') ?><span class="sr-only">Switch light or dark mode</span></button>
        <details class="profile">
          <summary aria-label="Account menu: <?= e($u['display_name']) ?>"><span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $u['display_name'], 0, 1))) ?></span><span class="hide-sm"><?= e($u['display_name']) ?></span></summary>
          <div class="menu">
            <p><strong><?= e($u['display_name']) ?></strong><br><span class="muted"><?= e($u['email']) ?></span><br><span class="badge"><?= e(str_replace('_', ' ', $u['role'])) ?></span></p>
            <form method="post" action="/logout"><?= csrf_field() ?><button type="submit" class="btn ghost small"><?= icon('logout') ?> Log out</button></form>
          </div>
        </details>
      </div>
    </header>
    <main id="main" tabindex="-1">
      <?php if ($c['flash']): ?><div class="flash <?= e($c['flash']['type']) ?>" role="status"><?= icon($c['flash']['type'] === 'error' ? 'alert' : 'check') ?><span><?= e($c['flash']['message']) ?></span></div><?php endif ?>
      <?= $content ?>
    </main>
  </div>
</div>
</body>
</html>
