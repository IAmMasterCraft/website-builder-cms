<?php /** @var array $s @var array $d @var string $h */
$sub = $h === 'h1' ? 'h2' : 'h3';
$style = in_array($s['style'] ?? 'cards', ['cards', 'reveal', 'minimal'], true) ? $s['style'] : 'cards'; ?>
<div class="container">
  <?= view('sections/_head', ['s' => $s, 'h' => $h]) ?>
  <ul class="features cols-<?= e($s['columns']) ?> is-<?= e($style) ?>" data-reveal-group>
    <?php foreach ($s['cards'] as $n => $card): $url = safe_url($card['link_url'] ?? ''); $ic = array_key_exists($card['icon'] ?? '', icon_options()) ? $card['icon'] : 'heart'; $m = $d['media'][$n] ?? null; ?>
      <?php if ($style === 'reveal'): ?>
        <li class="feature reveal-card" data-reveal>
          <?php if ($m): ?><figure class="rc-media" aria-hidden="true"><?= img_html($m, '(min-width: 64rem) 24rem, (min-width: 40rem) 45vw, 100vw', '', '', true, 800) ?></figure><?php endif ?>
          <span class="icon-tile" aria-hidden="true"><?= icon($ic) ?></span>
          <div class="rc-body">
            <<?= $sub ?>><?= e($card['title']) ?></<?= $sub ?>>
            <?php if (($card['text'] ?? '') !== ''): ?><p><?= e($card['text']) ?></p><?php endif ?>
            <?php if (($card['link_label'] ?? '') !== '' && $url !== ''): ?><a class="link-arrow" href="<?= e($url) ?>"><?= e($card['link_label']) ?> <?= icon('arrow-right') ?></a><?php endif ?>
          </div>
        </li>
      <?php else: ?>
        <li class="feature" data-reveal>
          <span class="icon-tile" aria-hidden="true"><?= icon($ic) ?></span>
          <<?= $sub ?>><?= e($card['title']) ?></<?= $sub ?>>
          <?php if (($card['text'] ?? '') !== ''): ?><p><?= e($card['text']) ?></p><?php endif ?>
          <?php if (($card['link_label'] ?? '') !== '' && $url !== ''): ?><a class="link-arrow" href="<?= e($url) ?>"><?= e($card['link_label']) ?> <?= icon('arrow-right') ?></a><?php endif ?>
        </li>
      <?php endif ?>
    <?php endforeach ?>
  </ul>
</div>
