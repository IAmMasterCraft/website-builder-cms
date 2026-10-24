<?php
/**
 * Live radio player (§6.2). Server-rendered with the current state so it
 * reads correctly without JavaScript; radio.js syncs playback to the
 * station clock. @var string $size full|mini @var string $heading @var string $headingTag
 */
$np = radio_now_playing();
$m = $np['media'];
$on = $np['status'] !== 'offline';
$h = $headingTag ?? 'h2';
$uid = 'rp' . bin2hex(random_bytes(3));
?>
<div class="radio-player size-<?= e($size) ?><?= $on ? '' : ' is-offline' ?>" data-radio-player data-api="/api/radio/now-playing" aria-labelledby="<?= $uid ?>-h">
  <div class="rp-stage">
    <div class="rp-cover">
      <img data-rp-cover src="<?= e((string) ($m['cover_image'] ?? '')) ?>" alt=""<?= empty($m['cover_image']) ? ' hidden' : '' ?> width="400" height="400">
      <span class="rp-cover-fallback" data-rp-fallback<?= empty($m['cover_image']) ? '' : ' hidden' ?> aria-hidden="true"><?= icon('radio') ?></span>
      <video data-rp-video playsinline preload="none" hidden aria-label="Live video"></video>
    </div>
    <button type="button" class="rp-tune" data-rp-tune hidden><?= icon('radio') ?><span>Tune in</span></button>
  </div>
  <div class="rp-body">
    <p class="rp-eyebrow"><span class="rp-dot" aria-hidden="true"></span><span data-rp-status><?= $on ? 'On air now' : 'Off air' ?></span><span class="sr-only"> · </span><span class="rp-heading" id="<?= $uid ?>-h"><?= e($heading) ?></span></p>
    <<?= $h ?> class="rp-title" data-rp-title><?= e($m['title'] ?? ($on ? '' : 'We are off air')) ?></<?= $h ?>>
    <p class="rp-artist" data-rp-artist><?= e($m['artist'] ?? ($np['next'] ? '' : 'Check back soon for our next broadcast.')) ?></p>
    <?php if ($size === 'full'): ?><p class="rp-desc" data-rp-desc><?= e(excerpt($m['description'] ?? '', 220)) ?></p><?php endif ?>
    <div class="rp-progress" data-rp-progress-wrap<?= $on ? '' : ' hidden' ?>>
      <progress max="1" value="<?= $m && $m['duration'] ? e((string) round(($np['offset'] ?? 0) / $m['duration'], 4)) : '0' ?>" data-rp-progress aria-label="Programme progress"></progress>
      <span class="rp-time tabular"><span data-rp-elapsed><?= e(radio_fmt_duration((float) ($np['offset'] ?? 0))) ?></span> / <span data-rp-duration><?= e(radio_fmt_duration(isset($m['duration']) ? (float) $m['duration'] : null)) ?></span></span>
    </div>
    <div class="rp-controls" data-rp-controls hidden>
      <button type="button" class="rp-play" data-rp-play aria-pressed="false"><span class="rp-icon-play" aria-hidden="true"></span><span class="rp-play-label">Play</span></button>
      <label class="rp-volume"><span class="sr-only">Volume</span><?= icon('radio', 'icon rp-vol-icon') ?><input type="range" min="0" max="1" step="0.05" value="0.9" data-rp-volume></label>
      <span class="rp-live-note muted small-text" data-rp-drift></span>
    </div>
    <p class="rp-next" data-rp-next<?= $np['next'] ? '' : ' hidden' ?>><span class="rp-next-label"><?= $on ? 'Up next' : 'Next on air' ?></span> <strong data-rp-next-title><?= e($np['next']['title'] ?? '') ?></strong><span data-rp-next-artist><?= !empty($np['next']['artist']) ? ' · ' . e($np['next']['artist']) : '' ?></span> <span class="rp-countdown tabular" data-rp-countdown data-starts-in="<?= e((string) ($np['next']['starts_in'] ?? '')) ?>"></span></p>
    <p class="rp-message" role="status" aria-live="polite" data-rp-message></p>
    <noscript><p class="muted small-text">The live player needs JavaScript. You can still play programmes from the library<?= request_path() === '/radio' ? ' below' : ' on the <a href="/radio">radio page</a>' ?>.</p></noscript>
  </div>
  <audio data-rp-audio preload="none"></audio>
</div>
