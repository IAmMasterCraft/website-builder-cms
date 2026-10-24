<?php /** @var array $s @var array $d @var string $h */
$split = ($s['layout'] ?? 'split') === 'split';
$list = '';
foreach ($d['faqs'] as $f) {
    $list .= '<details class="faq" data-reveal><summary>' . e($f['question']) . ' <span class="faq-icon" aria-hidden="true"></span></summary><div class="faq-body"><div class="prose">' . rich($f['answer']) . '</div></div></details>';
} ?>
<div class="container<?= $split ? ' faq-split' : ' narrow-wide' ?>">
  <?= view('sections/_head', ['s' => $s, 'h' => $h]) ?>
  <?php if ($d['faqs']): ?><div class="faq-list" data-reveal-group><?= $list ?></div>
  <?php else: ?><?= view('partials/empty', ['message' => 'No questions yet.']) ?><?php endif ?>
</div>
