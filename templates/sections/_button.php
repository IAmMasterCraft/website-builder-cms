<?php
/** The single button_label/button_url pair. @var array $s @var ?string $cls */
$label = trim((string) ($s['button_label'] ?? ''));
$url = safe_url((string) ($s['button_url'] ?? ''));
if ($label === '' || $url === '') {
    return;
}
?>
<p class="split-action"><a class="btn <?= e($cls ?? 'accent') ?>" href="<?= e($url) ?>"><?= e($label) ?> <?= icon('arrow-right') ?></a></p>
