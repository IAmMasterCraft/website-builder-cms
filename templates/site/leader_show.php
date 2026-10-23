<?php $labels = leader_social_networks(); ?>
<?= view('partials/page_hero', ['heading' => $leader['name'], 'intro' => $leader['role_title'], 'crumbs' => ['Leaders' => '/leaders', $group['name'] => '/leaders/' . $group['slug'], $leader['name'] => null]]) ?>
<section class="section">
  <div class="container profile">
    <div class="profile-photo"><?= $photo ? img_html($photo, '(min-width: 48rem) 18rem, 100vw', 'Photo of ' . $leader['name'], '', false, 800) : '<span class="avatar-fallback" aria-hidden="true">' . e(mb_substr($leader['name'], 0, 1)) . '</span>' ?></div>
    <div>
      <p class="person-role"><?= e($leader['role_title']) ?> · <?= e($group['name']) ?></p>
      <?php if ($leader['term_start'] || $leader['term_end']): ?>
        <p class="muted">Term: <?= e($leader['term_start'] ? (new DateTimeImmutable($leader['term_start']))->format('M Y') : '…') ?> – <?= e($leader['term_end'] ? (new DateTimeImmutable($leader['term_end']))->format('M Y') : 'present') ?></p>
      <?php endif ?>
      <?php if ((int) $leader['show_bio'] === 1 && $leader['bio']): ?><div class="prose"><?= rich($leader['bio']) ?></div><?php endif ?>
      <ul class="event-facts">
        <?php if ((int) $leader['show_email'] === 1 && $leader['email']): ?><li><?= icon('mail') ?><div><strong>Email</strong><a href="mailto:<?= e($leader['email']) ?>"><?= e($leader['email']) ?></a></div></li><?php endif ?>
        <?php if ((int) $leader['show_phone'] === 1 && $leader['phone']): ?><li><?= icon('phone') ?><div><strong>Phone</strong><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $leader['phone'])) ?>"><?= e($leader['phone']) ?></a></div></li><?php endif ?>
        <?php foreach ($social as $net => $url): ?><li><?= icon($net === 'website' ? 'globe' : $net) ?><div><strong><?= e($labels[$net] ?? $net) ?></strong><a href="<?= e($url) ?>" target="_blank" rel="noopener"><?= e(preg_replace('#^https://(www\.)?#', '', $url)) ?><span class="sr-only"> (opens in a new tab)</span></a></div></li><?php endforeach ?>
      </ul>
    </div>
  </div>
</section>
