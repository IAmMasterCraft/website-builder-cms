<?php
/** Up to two buttons from settings. @var array $s @var string $first @var string $second @var ?string $class */
$buttons = [];
foreach ([1, 2] as $i) {
    $label = trim((string) ($s["button{$i}_label"] ?? ''));
    $url = safe_url((string) ($s["button{$i}_url"] ?? ''));
    if ($label !== '' && $url !== '') {
        $buttons[] = [$label, $url, $i === 1 ? $first : $second];
    }
}
if (!$buttons) {
    return;
}
?>
<div class="hero-actions<?= isset($class) ? ' ' . e($class) : '' ?>">
  <?php foreach ($buttons as $n => [$label, $url, $cls]): ?><a class="btn <?= e($cls) ?>" href="<?= e($url) ?>"><?= e($label) ?><?= $n === 0 ? ' ' . icon('arrow-right') : '' ?></a><?php endforeach ?>
</div>
