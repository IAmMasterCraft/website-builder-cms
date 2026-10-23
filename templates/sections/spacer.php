<?php /** @var array $s */ ?>
<div class="container spacer spacer-<?= e($s['size']) ?>"><?php if ($s['divider'] === 'line'): ?><hr><?php elseif ($s['divider'] === 'dots'): ?><p class="dots" aria-hidden="true"><span></span><span></span><span></span></p><?php endif ?></div>
