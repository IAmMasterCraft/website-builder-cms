<?php /** @var array $s @var array $d @var string $h @var array $section */
$sub = $h === 'h1' ? 'h2' : 'h3';
$uid = 'sc-' . substr(md5(json_encode($s['items']) . ($s['heading'] ?? '')), 0, 6); ?>
<div class="container sc-grid" data-showcase>
  <div class="sc-side">
    <div class="sc-sticky" data-reveal>
      <?php if ($s['eyebrow'] !== ''): ?><span class="eyebrow"><?= e($s['eyebrow']) ?></span><?php endif ?>
      <?php if ($s['heading'] !== ''): ?><<?= $h ?>><?= e($s['heading']) ?></<?= $h ?>><?php endif ?>
      <?php if ($s['intro'] !== ''): ?><p><?= e($s['intro']) ?></p><?php endif ?>
      <?= view('sections/_button', ['s' => $s, 'cls' => 'ghost']) ?>
      <ol class="sc-index">
        <?php foreach ($s['items'] as $i => $it): ?><li><a href="#<?= e($uid . '-' . ($i + 1)) ?>" aria-current="<?= $i === 0 ? 'true' : 'false' ?>"><?= e($it['title']) ?></a></li><?php endforeach ?>
      </ol>
    </div>
  </div>
  <ol class="sc-cards">
    <?php foreach ($s['items'] as $i => $it): $m = $d['media'][$i] ?? null; $ic = (string) ($it['icon'] ?? ''); $url = safe_url((string) ($it['link_url'] ?? '')); ?>
      <li class="sc-card" id="<?= e($uid . '-' . ($i + 1)) ?>">
        <?= $m ? img_html($m, '(min-width: 64rem) 48rem, 100vw', '', '', true, 1600) : '' ?>
        <div class="sc-top">
          <?php if ($ic !== '' && array_key_exists($ic, icon_options())): ?><span class="icon-tile light" aria-hidden="true"><?= icon($ic) ?></span><?php else: ?><span></span><?php endif ?>
          <span class="sc-num" aria-hidden="true"><?= sprintf('%02d', $i + 1) ?></span>
        </div>
        <div class="sc-body">
          <<?= $sub ?>><?= e($it['title']) ?></<?= $sub ?>>
          <?php if (($it['text'] ?? '') !== ''): ?><p><?= e($it['text']) ?></p><?php endif ?>
          <?php if (($it['link_label'] ?? '') !== '' && $url !== ''): ?><a class="link-arrow" href="<?= e($url) ?>"><?= e($it['link_label']) ?> <?= icon('arrow-right') ?></a><?php endif ?>
        </div>
      </li>
    <?php endforeach ?>
  </ol>
</div>
