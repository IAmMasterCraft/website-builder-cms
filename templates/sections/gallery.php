<?php /** @var array $s @var array $d @var string $h */ $photos = $d['photos']; ?>
<div class="container">
  <?= view('sections/_head', ['s' => $s, 'h' => $h]) ?>
  <?php if (!$photos): ?>
    <?= view('partials/empty', ['message' => 'No photos yet.']) ?>
  <?php else:
      $list = $s['layout'] === 'masonry' ? 'photo-masonry' : ($s['layout'] === 'strip' ? 'photo-strip slider-track' : 'photo-grid'); ?>
    <?php if ($s['layout'] === 'strip'): ?><div class="slider" data-slider><?php endif ?>
    <ul class="<?= $list ?>" data-lightbox<?= $s['layout'] === 'strip' ? ' tabindex="0" aria-label="Photos, swipe or scroll sideways"' : '' ?>>
      <?php foreach ($photos as $ph): $m = media_find((int) $ph['media_id']); if ($m === null) { continue; } ?>
        <li<?= $s['layout'] === 'strip' ? ' class="slide"' : '' ?>><a href="<?= e(media_url($m, 1600)) ?>" data-caption="<?= e($ph['caption']) ?>">
          <?= img_html($m, '(min-width: 72rem) 18rem, (min-width: 40rem) 30vw, 50vw', $ph['alt'] !== '' ? $ph['alt'] : ($ph['caption'] !== '' ? $ph['caption'] : $m['alt']), '', true, $s['layout'] === 'masonry' ? 800 : 400) ?>
        </a></li>
      <?php endforeach ?>
    </ul>
    <?php if ($s['layout'] === 'strip'): ?>
      <div class="slider-controls" hidden>
        <button type="button" class="slider-btn" data-dir="-1"><?= icon('chevron-left') ?><span class="sr-only">Previous photos</span></button>
        <span class="slider-count tabular" aria-live="polite"></span>
        <button type="button" class="slider-btn" data-dir="1"><?= icon('chevron-right') ?><span class="sr-only">Next photos</span></button>
      </div></div>
    <?php endif ?>
  <?php endif ?>
</div>
