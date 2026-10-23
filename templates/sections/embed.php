<?php /** @var array $s @var array $d @var string $h @var array $ctx */ $em = $d['embed']; ?>
<?php if ($em === null && empty($ctx['preview'])) { return; } ?>
<div class="container narrow-wide">
  <?php if ($s['heading'] !== ''): ?><div class="blk-head"><div><<?= $h ?>><?= e($s['heading']) ?></<?= $h ?>></div></div><?php endif ?>
  <figure class="embed-figure">
    <?php if ($em === null): ?>
      <p class="blk-empty">Paste a YouTube, Vimeo, Google Maps, Facebook page or X profile link in this section’s settings.</p>
    <?php elseif ($em['kind'] === 'iframe'): ?>
      <div class="embed ratio-<?= e($s['aspect']) ?>"><iframe src="<?= e($em['src']) ?>" title="<?= e($s['title']) ?>" loading="lazy" allow="encrypted-media; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin" sandbox="allow-scripts allow-same-origin allow-popups allow-presentation"></iframe></div>
    <?php else: ?>
      <a class="embed-link" href="<?= e($em['url']) ?>" target="_blank" rel="noopener"><?= icon('x') ?><span><strong><?= e($em['handle'] ?? $em['provider']) ?></strong><br>See the latest posts on <?= e($em['provider']) ?><span class="sr-only"> (opens in a new tab)</span></span><?= icon('external') ?></a>
    <?php endif ?>
    <?php if ($s['caption'] !== ''): ?><figcaption class="muted"><?= e($s['caption']) ?></figcaption><?php endif ?>
  </figure>
</div>
