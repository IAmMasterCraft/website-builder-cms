<?php /** Duplicate warnings. @var list<array> $dups */
foreach ($dups as $d): ?><p class="pay-row-warn"><?= icon('warning') ?> Possible duplicate of <a href="/admin/payments/<?= (int) $d['id'] ?>">#<?= (int) $d['id'] ?></a> (<?= e($d['why']) ?>)</p><?php endforeach;
