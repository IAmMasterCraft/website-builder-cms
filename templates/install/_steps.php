<div class="brand-hero"><span class="logo-mark" aria-hidden="true">G</span> Gather setup</div>
<ol class="install-steps" aria-label="Installation steps">
  <?php $passed = true; foreach (\Install\Wizard::STEPS as $key => $label): $current = $key === $step; if ($current) { $passed = false; } ?>
    <li<?= $current ? ' aria-current="step"' : ($passed ? ' class="done"' : '') ?>><?= e($label) ?></li>
  <?php endforeach ?>
</ol>
