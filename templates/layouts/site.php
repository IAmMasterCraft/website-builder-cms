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
<?= favicon_head_tags() ?>
<link rel="alternate" type="application/rss+xml" title="<?= e($c['site_name']) ?> news" href="/feed.xml">
<?php foreach (design_preload_fonts() as $font): ?><link rel="preload" href="<?= e($font) ?>" as="font" type="font/woff2" crossorigin>
<?php endforeach ?><?php foreach (head_preloads() as $tag): ?><?= $tag ?>
<?php endforeach ?><link rel="stylesheet" href="<?= e($c['theme_css']) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/site.css')) ?>">
<?php foreach ($extra_css ?? [] as $css): ?><link rel="stylesheet" href="<?= e(asset($css)) ?>">
<?php endforeach ?><script src="<?= e(asset('assets/js/boot.js')) ?>"></script>
<script type="module" src="<?= e(asset('assets/js/site.js')) ?>"></script>
<?php if ($c['analytics'] !== null): ?><script type="module" src="<?= e(asset('assets/js/analytics.js')) ?>"></script>
<meta name="analytics" content="<?= e(json_attr(['provider' => $c['analytics']['provider'], 'id' => $c['analytics']['id'], 'script' => $c['analytics']['script'], 'consent' => $c['analytics']['cookies']])) ?>">
<?php endif ?>
</head>
<?php $overlay = (bool) page_flag('overlay_header') && !$c['flash']; $bodyClasses = trim(($body_class ?? '') . ($c['topbar'] ? ' has-topbar' : '') . ($overlay ? ' has-overlay-header' : '') . (is_impersonating() ? ' is-impersonating' : '')); ?>
<body class="<?= e($bodyClasses) ?>">
<a class="skip-link" href="#main">Skip to content</a>
<?= view('partials/impersonation_banner') ?>
<?php if ($c['topbar']): ?>
<div class="topbar" role="region" aria-label="Contact details and account links">
  <div class="container">
    <ul class="topbar-info">
      <?php if ($c['phone'] !== ''): ?><li><?= icon('phone') ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $c['phone'])) ?>"><?= e($c['phone']) ?></a></li><?php endif ?>
      <?php if ($c['email'] !== ''): ?><li class="hide-sm"><?= icon('mail') ?><a href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a></li><?php endif ?>
      <?php if ($c['charity_number'] !== ''): ?><li class="hide-sm"><?= icon('shield-check') ?>Registered charity <?= e($c['charity_number']) ?></li><?php endif ?>
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
<header class="site-header<?= $c['sticky'] ? ' sticky' : '' ?><?= $overlay ? ' is-overlay' : '' ?>">
  <div class="container">
    <a class="brand" href="/">
      <?php if ($c['logo']): ?>
        <?= img_html($c['logo'], '200px', $c['site_name'], '', false, 400) ?>
      <?php else: ?>
        <span class="brand-mark" aria-hidden="true"><?= e($initial) ?></span>
        <span class="brand-name"><?= e($c['site_name']) ?></span>
      <?php endif ?>
    </a>
    <nav class="site-nav" id="site-nav" aria-label="Main">
      <ul>
        <?php foreach ($c['main_menu'] as $i => $item): ?>
          <?php if ($item['children']): ?>
            <li class="has-sub<?= $item['active'] ? ' is-active' : '' ?>">
              <button type="button" class="sub-toggle" aria-expanded="false" aria-controls="sub-<?= (int) $i ?>"><?= e($item['label']) ?> <?= icon('chevron-down') ?></button>
              <ul class="submenu<?= count($item['children']) > 5 ? ' is-mega' : '' ?>" id="sub-<?= (int) $i ?>">
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
      <div class="nav-extra">
        <?php if ($c['show_auth'] && $c['user'] === null): ?><a class="btn accent large block" href="/join">Join us <?= icon('arrow-right') ?></a><a class="btn ghost block" href="/login">Log in</a>
        <?php elseif ($c['user'] !== null): ?><a class="btn ghost block" href="<?= e(home_for($c['user'])) ?>"><?= is_staff($c['user']) ? 'Admin' : 'My account' ?></a><?php endif ?>
        <?php if ($c['phone'] !== '' || $c['email'] !== ''): ?><p class="muted small-text"><?= $c['phone'] !== '' ? e($c['phone']) : '' ?><?= $c['phone'] !== '' && $c['email'] !== '' ? ' · ' : '' ?><?= $c['email'] !== '' ? e($c['email']) : '' ?></p><?php endif ?>
      </div>
    </nav>
    <div class="header-actions">
      <?php if ($c['listen_live']): ?><a class="btn small ghost listen-live" href="/radio"><?= icon('radio') ?> <span class="ll-text">Listen live</span></a><?php endif ?>
      <a class="search-link" href="/search"><?= icon('search') ?><span class="sr-only">Search</span></a>
      <?php if ($c['show_auth'] && $c['user'] === null): ?><a class="btn small accent header-cta" href="/join">Join us <?= icon('arrow-right') ?></a><?php endif ?>
      <button type="button" class="nav-toggle" aria-expanded="false" aria-controls="site-nav"><?= icon('menu', 'icon when-closed') ?><?= icon('close', 'icon when-open') ?> <span class="nav-toggle-label">Menu</span></button>
    </div>
  </div>
</header>
<?php if ($c['flash']): ?>
  <div class="container"><div class="flash <?= e($c['flash']['type']) ?>" role="status"><?= icon($c['flash']['type'] === 'error' ? 'alert' : 'check') ?><?= e($c['flash']['message']) ?></div></div>
<?php endif ?>
<main id="main" tabindex="-1">
<?= $content ?>
</main>
<?php $fc = $c['footer_cta']; $word = mb_strlen($c['site_name']) > 14 ? (preg_split('/\s+/', $c['site_name'])[0] ?? $c['site_name']) : $c['site_name']; ?>
<footer class="site-footer">
  <div class="container">
    <?php if ($fc['footer_cta'] && $fc['footer_cta_heading'] !== ''): ?>
    <div class="footer-cta">
      <div>
        <h2><?= e($fc['footer_cta_heading']) ?></h2>
        <?php if ($fc['footer_cta_text'] !== ''): ?><p><?= e($fc['footer_cta_text']) ?></p><?php endif ?>
      </div>
      <div class="btn-row">
        <?php if ($c['show_auth'] && $c['user'] === null): ?><a class="btn highlight large" href="/join">Become a member <?= icon('arrow-right') ?></a><?php endif ?>
        <?php if ($c['donate_links']): $dl = $c['donate_links'][0]; ?><a class="btn on-dark large" href="<?= e($dl['url']) ?>" target="_blank" rel="noopener"><?= icon('heart') ?> <?= e($dl['label']) ?><span class="sr-only"> (opens in a new tab)</span></a>
        <?php else: ?><a class="btn on-dark large" href="/events">See what’s on</a><?php endif ?>
      </div>
    </div>
    <?php endif ?>
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
              <ul class="footer-contact">
                <?php if ($c['address'] !== ''): ?><li><?= icon('pin') ?><span><?= e($c['address']) ?></span></li><?php endif ?>
                <?php if ($c['phone'] !== ''): ?><li><?= icon('phone') ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $c['phone'])) ?>"><?= e($c['phone']) ?></a></li><?php endif ?>
                <?php if ($c['email'] !== ''): ?><li><?= icon('mail') ?><a href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a></li><?php endif ?>
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
        <?php if (help_member_guide_access() === 'public' || $c['user'] !== null): ?><li><a href="/help">Help</a></li><?php endif ?>
        <?php if ($c['analytics'] !== null && $c['analytics']['cookies']): ?><li><button type="button" class="link-button" data-cookie-settings hidden>Cookie settings</button></li><?php endif ?>
        <li><a class="to-top" href="#main" data-to-top>Back to top <?= icon('arrow-up') ?></a></li>
      </ul>
    </div>
  </div>
  <?php if ($fc['footer_word']): ?><p class="footer-word" aria-hidden="true"><?= e($word) ?></p><?php endif ?>
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
