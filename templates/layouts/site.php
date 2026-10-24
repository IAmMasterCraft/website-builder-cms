<?php
/** @var array $chrome @var string $content @var string $title */
$c = $chrome;
$pageTitle = ($title ?? '') !== '' ? $title . ' · ' . $c['site_name'] : $c['site_name'] . ($c['tagline'] !== '' ? ' · ' . $c['tagline'] : '');
$description = $meta_description ?? $c['tagline'];
$socialLabels = ['facebook' => 'Facebook', 'x' => 'X', 'instagram' => 'Instagram', 'youtube' => 'YouTube', 'linkedin' => 'LinkedIn'];
$initial = mb_strtoupper(mb_substr($c['site_name'], 0, 1));
?>
<!doctype html>
<html lang="en" class="no-js">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="<?= e(excerpt((string) $description, 300)) ?>">
<?php if (!empty($canonical)): ?><link rel="canonical" href="<?= e(base_url($canonical)) ?>"><?php endif ?>
<meta property="og:title" content="<?= e(($title ?? '') !== '' ? $title : $c['site_name']) ?>">
<meta property="og:site_name" content="<?= e($c['site_name']) ?>">
<?php if (!empty($og_image)): ?><meta property="og:image" content="<?= e(base_url($og_image)) ?>"><?php endif ?>
<?php if (!empty($noindex)): ?><meta name="robots" content="noindex"><?php endif ?>
<meta name="theme-color" content="<?= e(design_effective()['brand']) ?>">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<link rel="icon" href="<?= e(asset('assets/img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="alternate" type="application/rss+xml" title="<?= e($c['site_name']) ?> news" href="/feed.xml">
<link rel="stylesheet" href="<?= e($c['theme_css']) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/site.css')) ?>">
<?php foreach ($extra_css ?? [] as $css): ?><link rel="stylesheet" href="<?= e(asset($css)) ?>">
<?php endforeach ?><script src="<?= e(asset('assets/js/boot.js')) ?>"></script>
<script type="module" src="<?= e(asset('assets/js/site.js')) ?>"></script>
<?php if ($c['analytics'] !== null): ?><script type="module" src="<?= e(asset('assets/js/analytics.js')) ?>"></script>
<meta name="analytics" content="<?= e(json_attr(['provider' => $c['analytics']['provider'], 'id' => $c['analytics']['id'], 'script' => $c['analytics']['script'], 'consent' => $c['analytics']['cookies']])) ?>">
<?php endif ?>
</head>
<body class="<?= e($body_class ?? '') ?>">
<a class="skip-link" href="#main">Skip to content</a>
<?php if ($c['topbar']): ?>
<div class="topbar" role="region" aria-label="Contact details and account links">
  <div class="container">
    <ul class="topbar-info">
      <?php if ($c['phone'] !== ''): ?><li><?= icon('phone') ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $c['phone'])) ?>"><?= e($c['phone']) ?></a></li><?php endif ?>
      <?php if ($c['email'] !== ''): ?><li><?= icon('mail') ?><a href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a></li><?php endif ?>
      <?php if ($c['charity_number'] !== ''): ?><li>Registered charity <?= e($c['charity_number']) ?></li><?php endif ?>
    </ul>
    <ul class="topbar-links">
      <?php foreach ($c['topbar_menu'] as $item): ?>
        <li><a href="<?= e($item['href']) ?>"<?= $item['new_tab'] ? ' target="_blank" rel="noopener"' : '' ?>><?= e($item['label']) ?></a></li>
      <?php endforeach ?>
      <?php if ($c['show_auth']): ?>
        <?php if ($c['user'] !== null): ?>
          <li><a href="<?= e(home_for($c['user'])) ?>"><?= is_staff($c['user']) ? 'Admin' : 'My account' ?></a></li>
        <?php else: ?>
          <li><a href="/login">Log in</a></li>
        <?php endif ?>
      <?php endif ?>
      <?php if ($c['social']): ?>
      <li><ul class="social" aria-label="Social media">
        <?php foreach ($c['social'] as $network => $url): ?>
          <li><a href="<?= e($url) ?>" rel="noopener" target="_blank"><?= icon($network) ?><span class="sr-only"><?= e($socialLabels[$network] ?? $network) ?> (opens in a new tab)</span></a></li>
        <?php endforeach ?>
      </ul></li>
      <?php endif ?>
    </ul>
  </div>
</div>
<?php endif ?>
<header class="site-header<?= $c['sticky'] ? ' sticky' : '' ?>">
  <div class="container">
    <a class="brand" href="/">
      <?php if ($c['logo']): ?>
        <?= img_html($c['logo'], '200px', $c['site_name'], '', false, 400) ?>
      <?php else: ?>
        <span class="brand-mark" aria-hidden="true"><?= e($initial) ?></span>
        <span><?= e($c['site_name']) ?></span>
      <?php endif ?>
    </a>
    <nav class="site-nav" id="site-nav" aria-label="Main">
      <ul>
        <?php foreach ($c['main_menu'] as $i => $item): ?>
          <?php if ($item['children']): ?>
            <li class="has-sub<?= $item['active'] ? ' is-active' : '' ?>">
              <button type="button" class="sub-toggle" aria-expanded="false" aria-controls="sub-<?= (int) $i ?>"><?= e($item['label']) ?> <?= icon('chevron-down') ?></button>
              <ul class="submenu" id="sub-<?= (int) $i ?>">
                <?php foreach ($item['children'] as $child): ?>
                  <li><a href="<?= e($child['href']) ?>"<?= $child['active'] ? ' aria-current="page"' : '' ?><?= $child['new_tab'] ? ' target="_blank" rel="noopener"' : '' ?>><?= e($child['label']) ?></a></li>
                <?php endforeach ?>
              </ul>
            </li>
          <?php else: ?>
            <li<?= $item['active'] ? ' class="is-active"' : '' ?>><a href="<?= e($item['href']) ?>"<?= $item['active'] ? ' aria-current="page"' : '' ?><?= $item['new_tab'] ? ' target="_blank" rel="noopener"' : '' ?>><?= e($item['label']) ?></a></li>
          <?php endif ?>
        <?php endforeach ?>
      </ul>
    </nav>
    <div class="header-actions">
      <?php if ($c['listen_live']): ?><a class="btn small ghost listen-live" href="/radio"><?= icon('radio') ?> Listen live</a><?php endif ?>
      <a class="search-link" href="/search"><?= icon('search') ?><span class="sr-only">Search</span></a>
      <?php if ($c['show_auth'] && $c['user'] === null): ?><a class="btn small accent header-cta" href="/join">Join us</a><?php endif ?>
      <button type="button" class="nav-toggle" aria-expanded="false" aria-controls="site-nav"><?= icon('menu') ?> Menu</button>
    </div>
  </div>
</header>
<?php if ($c['flash']): ?>
  <div class="container"><div class="flash <?= e($c['flash']['type']) ?>" role="status"><?= icon($c['flash']['type'] === 'error' ? 'alert' : 'check') ?><?= e($c['flash']['message']) ?></div></div>
<?php endif ?>
<main id="main" tabindex="-1">
<?= $content ?>
</main>
<footer class="site-footer">
  <div class="container">
    <div class="footer-grid">
      <?php foreach ($c['footer_blocks'] as $i => $b): $headingId = 'footer-col-' . $i; ?>
        <?php if ($b['widget'] === 'about'): ?>
          <div class="footer-about">
            <a class="brand" href="/"><span class="brand-mark" aria-hidden="true"><?= e($initial) ?></span><span><?= e($c['site_name']) ?></span></a>
            <?php if ($b['text'] !== ''): ?><p><?= e($b['text']) ?></p><?php endif ?>
            <?php if ($c['social']): ?>
            <ul class="social" aria-label="Social media">
              <?php foreach ($c['social'] as $network => $url): ?>
                <li><a href="<?= e($url) ?>" rel="noopener" target="_blank"><?= icon($network) ?><span class="sr-only"><?= e($socialLabels[$network] ?? $network) ?> (opens in a new tab)</span></a></li>
              <?php endforeach ?>
            </ul>
            <?php endif ?>
          </div>
        <?php elseif ($b['widget'] === 'menu'): ?>
          <nav aria-labelledby="<?= $headingId ?>">
            <h2 id="<?= $headingId ?>"><?= e($b['title']) ?></h2>
            <ul><?php foreach ($b['items'] as $item): ?><li><a href="<?= e($item['href']) ?>"<?= $item['new_tab'] ? ' target="_blank" rel="noopener"' : '' ?>><?= e($item['label']) ?></a></li><?php endforeach ?></ul>
          </nav>
        <?php else: ?>
          <div>
            <h2><?= e($b['title']) ?></h2>
            <?php if ($b['widget'] === 'latest_posts'): ?>
              <ul><?php foreach ($b['posts'] as $p): ?><li><a href="<?= e(post_url($p)) ?>"><?= e($p['title']) ?></a></li><?php endforeach ?></ul>
            <?php elseif ($b['widget'] === 'contact'): ?>
              <ul>
                <?php if ($c['address'] !== ''): ?><li><?= e($c['address']) ?></li><?php endif ?>
                <?php if ($c['phone'] !== ''): ?><li><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $c['phone'])) ?>"><?= e($c['phone']) ?></a></li><?php endif ?>
                <?php if ($c['email'] !== ''): ?><li><a href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a></li><?php endif ?>
              </ul>
            <?php elseif ($b['widget'] === 'social'): ?>
              <ul class="footer-social"><?php foreach ($c['social'] as $network => $url): ?><li><a href="<?= e($url) ?>" rel="noopener" target="_blank"><?= icon($network) ?> <?= e($socialLabels[$network] ?? $network) ?><span class="sr-only"> (opens in a new tab)</span></a></li><?php endforeach ?></ul>
            <?php elseif ($b['widget'] === 'donate'): ?>
              <p><?= e($b['text']) ?></p>
              <?php foreach ($b['links'] as $l): ?><p><a class="btn small highlight" href="<?= e($l['url']) ?>" target="_blank" rel="noopener"><?= icon('heart') ?> <?= e($l['label']) ?><span class="sr-only"> (opens in a new tab)</span></a></p><?php endforeach ?>
            <?php elseif ($b['widget'] === 'join'): ?>
              <p><?= e($b['text']) ?></p>
              <p><a class="btn small highlight" href="/join">Join us</a></p>
            <?php endif ?>
          </div>
        <?php endif ?>
      <?php endforeach ?>
    </div>
    <div class="footer-bottom">
      <p>&copy; <?= e($c['year']) ?> <?= e($c['site_name']) ?><?= $c['charity_number'] !== '' ? ' · Registered charity ' . e($c['charity_number']) : '' ?></p>
      <ul>
        <li><a href="/feed.xml">RSS</a></li>
        <li><a href="/sitemap.xml">Sitemap</a></li>
        <li><a href="/search">Search</a></li>
        <?php if ($c['analytics'] !== null && $c['analytics']['cookies']): ?><li><button type="button" class="link-button" data-cookie-settings hidden>Cookie settings</button></li><?php endif ?>
      </ul>
    </div>
  </div>
</footer>
<?php if ($c['analytics'] !== null && $c['analytics']['cookies']): ?>
<section class="cookie-banner" data-cookie-banner hidden aria-labelledby="cookie-title">
  <div class="container cookie-inner">
    <div><h2 id="cookie-title" class="cookie-title">Cookies</h2><p><?= e($c['analytics']['banner']) ?></p></div>
    <div class="btn-row"><button type="button" class="btn accent small" data-consent="yes">Accept analytics</button><button type="button" class="btn small" data-consent="no">No thanks</button></div>
  </div>
</section>
<?php endif ?>
</body>
</html>
