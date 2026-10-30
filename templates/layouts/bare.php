<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Gather') ?></title>
<?= favicon_head_tags() ?>
<link rel="stylesheet" href="<?= e(asset('assets/css/admin.css')) ?>">
</head>
<body class="bare">
<main id="main" class="bare-main"><?= $content ?></main>
</body>
</html>
