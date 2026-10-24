<?php /** @var array $s @var array $d @var string $h @var array $ctx */
$layout = (string) $s['layout'];
$slides = $d['slides'];
$first = $h === 'h1';
$video = $d['video'] ?? null;
$avatars = $d['avatars'] ?? [];
$proofText = trim((string) ($s['proof_text'] ?? ''));
$proofCount = trim((string) ($s['proof_count'] ?? ''));
$proof = function () use ($avatars, $proofText, $proofCount): string {
    if ($proofText === '' && !$avatars) {
        return '';
    }
    $html = '<div class="proof" data-reveal>';
    if ($avatars || $proofCount !== '') {
        $html .= '<ul class="avatar-stack" aria-hidden="true">';
        foreach ($avatars as $m) {
            $html .= '<li>' . img_html($m, '3rem', '', '', true, 400) . '</li>';
        }
        $html .= $proofCount !== '' ? '<li class="avatar-more">' . e($proofCount) . '</li>' : '';
        $html .= '</ul>';
    }
    return $html . ($proofText !== '' ? '<p>' . e($proofText) . '</p>' : '') . '</div>';
};
?>
<?php if ($layout === 'fullscreen'):
    if ($first) {
        page_flag('overlay_header', true);
        if ($slides && !$video) {
            preload_image($slides[0]['media'], '100vw');
        }
    }
    $multi = count($slides) > 1 && !$video;
    $floatTitle = trim((string) ($s['float_title'] ?? ''));
    $floatUrl = safe_url((string) ($s['float_url'] ?? ''));
    $floatIcon = array_key_exists((string) ($s['float_icon'] ?? ''), icon_options()) ? (string) $s['float_icon'] : 'calendar';
    ?>
  <div class="hero-stage<?= !empty($s['kenburns']) ? ' kenburns' : '' ?>" data-hero-stage<?= !empty($s['autoplay']) ? ' data-autoplay' : '' ?> aria-hidden="true">
    <div class="hero-parallax">
      <?php if ($video): ?>
        <video class="hero-video" muted loop playsinline preload="none"<?= $slides ? ' poster="' . e(media_url($slides[0]['media'], 1600)) . '"' : '' ?>><source src="<?= e(media_original_url($video)) ?>" type="<?= e($video['mime']) ?>"></video>
      <?php else: ?>
        <div class="hero-slides">
          <?php foreach ($slides as $i => $sl): ?>
            <figure class="hero-slide<?= $i === 0 ? ' is-current' : '' ?>"><?= img_html($sl['media'], '100vw', '', '', !($first && $i === 0), 1600) ?></figure>
          <?php endforeach ?>
        </div>
      <?php endif ?>
    </div>
  </div>
  <div class="container hero-inner">
    <div class="hero-copy" data-reveal-group>
      <?php if ($s['eyebrow'] !== ''): ?><span class="eyebrow" data-reveal><?= e($s['eyebrow']) ?></span><?php endif ?>
      <<?= $h ?> class="hero-title" data-reveal><?= e($s['heading']) ?></<?= $h ?>>
      <?php if ($s['text'] !== ''): ?><p class="lede" data-reveal><?= e($s['text']) ?></p><?php endif ?>
      <?= view('sections/_buttons', ['s' => $s, 'first' => 'highlight large', 'second' => 'on-dark large']) ?>
      <?= $proof() ?>
    </div>
    <?php if (($floatTitle !== '' && $floatUrl !== '') || $multi): ?>
    <div class="hero-aside">
      <?php if ($floatTitle !== '' && $floatUrl !== ''): ?>
        <a class="hero-float" href="<?= e($floatUrl) ?>" data-reveal><span class="icon-tile" aria-hidden="true"><?= icon($floatIcon) ?></span><span><strong><?= e($floatTitle) ?></strong><?php if (trim((string) $s['float_text']) !== ''): ?><span class="small-text"><?= e($s['float_text']) ?></span><?php endif ?></span><?= icon('arrow-up-right') ?></a>
      <?php endif ?>
      <?php if ($multi): ?>
      <div class="hero-ui" hidden>
        <button type="button" class="round-btn" data-prev><?= icon('chevron-left') ?><span class="sr-only">Previous photo</span></button>
        <ul class="hero-dots"><?php foreach ($slides as $i => $sl): ?><li><button type="button" data-go="<?= (int) $i ?>" aria-current="<?= $i === 0 ? 'true' : 'false' ?>"><span class="sr-only">Show photo <?= $i + 1 ?><?= $sl['caption'] !== '' ? ': ' . e($sl['caption']) : '' ?></span></button></li><?php endforeach ?></ul>
        <button type="button" class="round-btn" data-next><?= icon('chevron-right') ?><span class="sr-only">Next photo</span></button>
        <button type="button" class="round-btn" data-pause aria-pressed="false"><?= icon('pause', 'icon when-playing') ?><?= icon('play', 'icon when-paused') ?><span class="sr-only">Pause slideshow</span></button>
        <span class="sr-only" aria-live="polite" data-live></span>
      </div>
      <?php endif ?>
    </div>
    <?php endif ?>
  </div>
  <?php if ($s['height'] === 'full'): ?><span class="scroll-cue" aria-hidden="true"></span><?php endif ?>
<?php else:
    $centered = $layout === 'centered';
    if ($first && $slides) {
        preload_image($slides[0]['media'], $centered ? '100vw' : '(min-width: 56rem) 40rem, 100vw');
    }
    ?>
<?php if ($centered && $slides): ?>
  <div class="blk-bg hero-backdrop" aria-hidden="<?= count($slides) > 1 ? 'false' : 'true' ?>"><?= view('sections/_slider', ['slides' => $slides, 'autoplay' => (bool) $s['autoplay'], 'sizes' => '100vw', 'eager' => $first]) ?></div>
<?php endif ?>
<div class="container hero-grid<?= $centered ? ' hero-centered' : '' ?><?= !$centered && $slides ? ' has-media' : '' ?>">
  <div class="hero-copy" data-reveal-group>
    <?php if ($s['eyebrow'] !== ''): ?><span class="eyebrow" data-reveal><?= e($s['eyebrow']) ?></span><?php endif ?>
    <<?= $h ?> class="hero-title" data-reveal><?= e($s['heading']) ?></<?= $h ?>>
    <?php if ($s['text'] !== ''): ?><p class="lede" data-reveal><?= e($s['text']) ?></p><?php endif ?>
    <?= view('sections/_buttons', ['s' => $s, 'first' => 'highlight large', 'second' => 'on-dark large']) ?>
    <?= $proof() ?>
  </div>
  <?php if (!$centered && $slides): ?>
    <div class="hero-media" data-reveal="zoom"><?= view('sections/_slider', ['slides' => $slides, 'autoplay' => (bool) $s['autoplay'], 'sizes' => '(min-width: 56rem) 40rem, 100vw', 'eager' => $first]) ?></div>
  <?php endif ?>
</div>
<?php endif ?>
