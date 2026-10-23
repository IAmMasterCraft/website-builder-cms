<?php /** @var array $s @var array $d @var string $h */ ?>
<div class="container narrow">
  <?= view('sections/_head', ['s' => $s, 'h' => $h]) ?>
  <?php if ($d['faqs']): foreach ($d['faqs'] as $f): ?>
    <details class="faq">
      <summary><?= e($f['question']) ?> <?= icon('plus') ?></summary>
      <div class="prose"><?= rich($f['answer']) ?></div>
    </details>
  <?php endforeach; else: ?><?= view('partials/empty', ['message' => 'No questions yet.']) ?><?php endif ?>
</div>
