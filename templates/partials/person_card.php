<?php /** @var array $leader @var string $groupSlug @var ?string $headingTag @var ?bool $past */
$h = $headingTag ?? 'h3';
$photo = media_find($leader['photo_media_id'] ? (int) $leader['photo_media_id'] : null);
$social = json_decode((string) ($leader['social'] ?? ''), true);
$social = is_array($social) ? $social : [];
$labels = leader_social_networks();
$icons = ['website' => 'globe', 'linkedin' => 'linkedin', 'x' => 'x', 'facebook' => 'facebook', 'instagram' => 'instagram']; ?>
<article class="person-card" data-reveal>
  <?= $photo ? img_html($photo, '(min-width: 64rem) 20rem, (min-width: 40rem) 45vw, 100vw', '', '', true, 800) : '<span class="avatar-fallback" aria-hidden="true">' . e(mb_substr($leader['name'], 0, 1)) . '</span>' ?>
  <?php if ($social): ?>
  <ul class="person-social">
    <?php foreach ($social as $net => $url): if (!isset($labels[$net]) || !is_string($url) || !preg_match('#^https://#i', $url)) { continue; } ?>
      <li><a href="<?= e($url) ?>" target="_blank" rel="noopener"><?= icon($icons[$net] ?? 'link') ?><span class="sr-only"><?= e($leader['name']) ?> on <?= e($labels[$net]) ?> (opens in a new tab)</span></a></li>
    <?php endforeach ?>
  </ul>
  <?php endif ?>
  <div class="person-info">
    <<?= $h ?>><a href="/leaders/<?= e($groupSlug) ?>/<?= e($leader['slug']) ?>"><?= e($leader['name']) ?></a></<?= $h ?>>
    <span class="person-role"><?= e($leader['role_title']) ?></span>
    <?php if (!empty($past) && $leader['term_end']): ?><span class="person-role"> · until <?= e((new DateTimeImmutable($leader['term_end']))->format('M Y')) ?></span><?php endif ?>
  </div>
</article>
