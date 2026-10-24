<?php /** @var array $s @var array $d @var string $h */
$slider = $s['layout'] === 'slider' && count($s['items']) > 1;
$card = static function (array $it, ?array $m): string {
    $rating = (int) ($it['rating'] ?? 0);
    $stars = '';
    if ($rating > 0) {
        $stars = '<span class="stars" role="img" aria-label="Rated ' . $rating . ' out of 5">';
        for ($i = 1; $i <= 5; $i++) {
            $stars .= icon('star', 'icon' . ($i > $rating ? ' off' : ''));
        }
        $stars .= '</span>';
    }
    $avatar = $m ? img_html($m, '3.5rem', '', '', true, 400) : e(mb_strtoupper(mb_substr((string) $it['name'], 0, 1)));
    return '<figure><span class="tst-quote-icon" aria-hidden="true">' . icon('quote') . '</span><blockquote><p>' . e($it['quote']) . '</p></blockquote>'
        . '<figcaption><span class="tst-avatar" aria-hidden="true">' . $avatar . '</span><span class="tst-who"><strong>' . e($it['name']) . '</strong>'
        . (($it['role'] ?? '') !== '' ? '<span>' . e($it['role']) . '</span>' : '') . '</span>' . $stars . '</figcaption></figure>';
}; ?>
<div class="container">
  <?= view('sections/_head', ['s' => $s, 'h' => $h]) ?>
</div>
<?php if ($slider): ?>
<div class="tst" data-carousel data-item-label="Quote"<?= !empty($s['autoplay']) ? ' data-autoplay' : '' ?>>
  <ul class="tst-track" data-track tabindex="0" aria-label="Testimonials, swipe or use the arrow keys">
    <?php foreach ($s['items'] as $i => $it): ?><li class="tst-card<?= $i === 0 ? ' is-current' : '' ?>"><?= $card($it, $d['media'][$i] ?? null) ?></li><?php endforeach ?>
  </ul>
  <div class="carousel-ui" hidden>
    <button type="button" class="neu-btn" data-prev><?= icon('chevron-left') ?><span class="sr-only">Previous quote</span></button>
    <ul class="carousel-dots"></ul>
    <button type="button" class="neu-btn" data-next><?= icon('chevron-right') ?><span class="sr-only">Next quote</span></button>
  </div>
</div>
<?php else: ?>
<div class="container">
  <ul class="tst-grid" data-reveal-group>
    <?php foreach ($s['items'] as $i => $it): ?><li class="tst-card" data-reveal><?= $card($it, $d['media'][$i] ?? null) ?></li><?php endforeach ?>
  </ul>
</div>
<?php endif ?>
