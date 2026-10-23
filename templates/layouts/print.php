<?php /** Print pages (member cards). @var string $content @var string $title */
[$theme] = request_theme(); ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Print') ?> · <?= e(setting('site.name')) ?></title>
<meta name="robots" content="noindex">
<link rel="icon" href="<?= e(asset('assets/img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(theme_css_url($theme)) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/site.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/card.css')) ?>">
<script type="module" src="<?= e(asset('assets/js/card.js')) ?>"></script>
</head>
<body class="print-body">
<?= $content ?>
</body>
</html>
