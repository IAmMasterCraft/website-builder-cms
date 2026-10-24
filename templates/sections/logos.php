<?php /** @var array $s @var array $d @var string $h */
$item = static function (array $it, ?array $m): string {
    $ic = (string) ($it['icon'] ?? '');
    $inner = $m ? img_html($m, '10rem', (string) $it['name'], '', true, 400)
        : (($ic !== '' && array_key_exists($ic, icon_options()) ? icon($ic) : '') . '<span>' . e($it['name']) . '</span>');
    $url = safe_url((string) ($it['url'] ?? ''));
    return '<li class="logo-item">' . ($url !== '' ? '<a href="' . e($url) . '" target="_blank" rel="noopener">' . $inner . '<span class="sr-only"> (opens in a new tab)</span></a>' : '<span>' . $inner . '</span>') . '</li>';
};
$list = '';
foreach ($s['items'] as $i => $it) {
    $list .= $item($it, $d['media'][$i] ?? null);
}
$head = ['eyebrow' => $s['eyebrow'], 'heading' => $s['heading']]; ?>
<div class="container">
  <?= view('sections/_head', ['s' => $head, 'h' => $h, 'layout' => 'center']) ?>
</div>
<?php if ($s['style'] === 'grid'): ?>
  <div class="container"><ul class="logo-grid" aria-label="Partners"><?= $list ?></ul></div>
<?php else: ?>
  <div class="marquee speed-<?= e($s['speed']) ?>">
    <ul class="marquee-track" aria-label="Partners"><?= $list ?></ul>
    <ul class="marquee-track" aria-hidden="true" inert><?= $list ?></ul>
  </div>
<?php endif ?>
