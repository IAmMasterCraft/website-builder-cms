<?php /** Status badge for a payment record. @var array $p */
$reversed = ($p['reversal_id'] ?? null) !== null;
$cls = ['pending' => 'warn', 'needs_info' => 'warn', 'approved' => 'ok', 'rejected' => 'bad', 'withdrawn' => 'muted'][$p['status']] ?? '';
if ($p['entry_type'] === 'reversal'): ?><span class="badge pay-badge bad">Reversal</span><?php
elseif ($reversed): ?><span class="badge pay-badge muted">Reversed</span><?php
else: ?><span class="badge pay-badge <?= e($cls) ?>"><?= e(PAYMENT_STATUSES[$p['status']] ?? $p['status']) ?></span><?php endif;
