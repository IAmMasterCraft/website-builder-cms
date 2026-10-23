<?php /** @var string $url @var string $text */
$u = rawurlencode($url);
$t = rawurlencode($text);
?>
<div class="share">
  <span class="share-label" id="share-label">Share</span>
  <a href="https://www.facebook.com/sharer/sharer.php?u=<?= e($u) ?>" target="_blank" rel="noopener"><?= icon('facebook') ?> Facebook<span class="sr-only"> (opens in a new tab)</span></a>
  <a href="https://x.com/intent/post?url=<?= e($u) ?>&amp;text=<?= e($t) ?>" target="_blank" rel="noopener"><?= icon('x') ?> X<span class="sr-only"> (opens in a new tab)</span></a>
  <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= e($u) ?>" target="_blank" rel="noopener"><?= icon('linkedin') ?> LinkedIn<span class="sr-only"> (opens in a new tab)</span></a>
  <a href="https://wa.me/?text=<?= e($t . '%20' . $u) ?>" target="_blank" rel="noopener"><?= icon('whatsapp') ?> WhatsApp<span class="sr-only"> (opens in a new tab)</span></a>
  <button type="button" data-copy="<?= e($url) ?>" hidden><?= icon('link') ?> <span>Copy link</span></button>
</div>
