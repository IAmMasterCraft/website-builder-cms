<?php /** @var list<array{media:array,caption:string}> $slides @var bool $autoplay @var string $sizes @var bool $eager */ ?>
<?php if (count($slides) === 1): $sl = $slides[0]; ?>
  <figure class="slide-single"><?= img_html($sl['media'], $sizes, null, '', !$eager, 1600) ?><?php if ($sl['caption'] !== ''): ?><figcaption><?= e($sl['caption']) ?></figcaption><?php endif ?></figure>
<?php else: ?>
  <div class="slider" data-slider<?= $autoplay ? ' data-autoplay' : '' ?>>
    <ul class="slider-track" tabindex="0" aria-label="Slideshow, <?= count($slides) ?> images">
      <?php foreach ($slides as $i => $sl): ?>
        <li class="slide" aria-label="<?= $i + 1 ?> of <?= count($slides) ?>">
          <figure><?= img_html($sl['media'], $sizes, null, '', !($eager && $i === 0), 1600) ?><?php if ($sl['caption'] !== ''): ?><figcaption><?= e($sl['caption']) ?></figcaption><?php endif ?></figure>
        </li>
      <?php endforeach ?>
    </ul>
    <div class="slider-controls" hidden>
      <button type="button" class="slider-btn" data-dir="-1"><?= icon('chevron-left') ?><span class="sr-only">Previous slide</span></button>
      <span class="slider-count tabular" aria-live="polite">1 / <?= count($slides) ?></span>
      <button type="button" class="slider-btn" data-dir="1"><?= icon('chevron-right') ?><span class="sr-only">Next slide</span></button>
      <?php if ($autoplay): ?><button type="button" class="slider-btn" data-toggle aria-pressed="false"><span class="sr-only">Pause slideshow</span><?= icon('clock') ?></button><?php endif ?>
    </div>
  </div>
<?php endif ?>
