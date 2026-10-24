<?php /** @var array $s @var array $d @var string $h */ $photos = $d['photos']; ?>
<div class="container">
  <?= view('sections/_head', ['s' => $s, 'h' => $h]) ?>
  <?php if (!$photos): ?>
    <?= view('partials/empty', ['message' => 'No photos yet.']) ?>
  <?php else:
      $layout = in_array($s['layout'], ['grid', 'mosaic', 'masonry', 'strip'], true) ? $s['layout'] : 'grid';
      $list = ['masonry' => 'photo-masonry', 'strip' => 'photo-strip slider-track', 'mosaic' => 'photo-mosaic', 'grid' => 'photo-grid'][$layout]; ?>
    <?php if ($layout === 'strip'): ?><div class="slider" data-slider><?php endif ?>
    <ul class="<?= $list ?>" data-lightbox<?= $layout === 'strip' ? ' tabindex="0" aria-label="Photos, swipe or scroll sideways"' : ' data-reveal-group' ?>>
      <?php foreach ($photos as $ph): $m = media_find((int) $ph['media_id']); if ($m === null) { continue; } ?>
        <li<?= $layout === 'strip' ? ' class="slide"' : ' data-reveal' ?>><a href="<?= e(media_url($m, 1600)) ?>" data-caption="<?= e($ph['caption']) ?>">
          <?= img_html($m, $layout === 'mosaic' ? '(min-width: 48rem) 40vw, 50vw' : '(min-width: 72rem) 18rem, (min-width: 40rem) 30vw, 50vw', $ph['alt'] !== '' ? $ph['alt'] : ($ph['caption'] !== '' ? $ph['caption'] : $m['alt']), '', true, in_array($layout, ['masonry', 'mosaic'], true) ? 800 : 400) ?>
        </a></li>
      <?php endforeach ?>
    </ul>
    <?php if ($layout === 'strip'): ?>
      <div class="slider-controls" hidden>
        <button type="button" class="slider-btn" data-dir="-1"><?= icon('chevron-left') ?><span class="sr-only">Previous photos</span></button>
        <span class="slider-count tabular" aria-live="polite"></span>
        <button type="button" class="slider-btn" data-dir="1"><?= icon('chevron-right') ?><span class="sr-only">Next photos</span></button>
      </div></div>
    <?php endif ?>
  <?php endif ?>
</div>
