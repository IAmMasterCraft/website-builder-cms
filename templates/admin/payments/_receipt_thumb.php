<?php /** Receipt preview in admin lists. @var array $p */
if ($p['receipt_media_id'] === null): ?><span class="muted small-text">No receipt</span><?php
elseif ($p['receipt_kind'] === 'image'): ?><a class="pay-thumb-link" href="/media/<?= (int) $p['receipt_media_id'] ?>/receipt" target="_blank" rel="noopener"><img class="pay-thumb" src="/media/<?= (int) $p['receipt_media_id'] ?>/w/400" alt="Receipt for payment #<?= (int) $p['id'] ?> (opens full size in a new tab)" loading="lazy" width="72" height="72"></a><?php
else: ?><a class="pay-thumb-link" href="/media/<?= (int) $p['receipt_media_id'] ?>/receipt.pdf" target="_blank" rel="noopener"><?= icon('file') ?><span>PDF<span class="sr-only"> receipt for payment #<?= (int) $p['id'] ?> (opens in a new tab)</span></span></a><?php endif;
