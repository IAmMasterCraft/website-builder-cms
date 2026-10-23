<?php /** @var list<array> $cards @var string $mode cr80|a4 */ ?>
<div class="print-toolbar">
  <p><?= $mode === 'a4' ? 'A4 sheets with 8 cards each: fronts first, then backs arranged for double-sided printing (flip on the long edge). Print at 100% scale.' : 'Your card at actual size (85.6 × 54 mm): front, then back. Choose “Save as PDF” in the print dialog to keep a copy.' ?></p>
  <button type="button" class="btn accent" data-print hidden>Print / Save as PDF</button>
</div>
<div class="print-pages">
<?php if ($mode === 'a4'):
    foreach (array_chunk($cards, 8) as $n => $sheet): ?>
      <div class="a4-sheet"><h2>Sheet <?= $n + 1 ?> · fronts</h2><div class="a4-grid">
        <?php for ($i = 0; $i < 8; $i++): ?><?= isset($sheet[$i]) ? view('partials/id_card', ['card' => $sheet[$i], 'side' => 'front']) : '<div class="slot-empty"></div>' ?><?php endfor ?>
      </div></div>
      <div class="a4-sheet"><h2>Sheet <?= $n + 1 ?> · backs (columns swapped for duplex)</h2><div class="a4-grid">
        <?php for ($row = 0; $row < 4; $row++): foreach ([1, 0] as $col): $i = $row * 2 + $col; ?><?= isset($sheet[$i]) ? view('partials/id_card', ['card' => $sheet[$i], 'side' => 'back']) : '<div class="slot-empty"></div>' ?><?php endforeach; endfor ?>
      </div></div>
    <?php endforeach;
else:
    foreach ($cards as $card): ?>
      <div class="cr80-page"><?= view('partials/id_card', ['card' => $card, 'side' => 'front']) ?></div>
      <div class="cr80-page"><?= view('partials/id_card', ['card' => $card, 'side' => 'back']) ?></div>
    <?php endforeach;
endif ?>
</div>
