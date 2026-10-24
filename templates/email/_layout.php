<?php
/**
 * Responsive email layout (tables + inline styles for mail clients, one
 * media query for small screens). Colours come from the active theme.
 * @var string $body built by the app from escaped values
 * @var string $site_name @var string $site_url @var array $c @var ?array $logo
 * @var string $preheader @var string $unsubscribe_url @var string $footer_note @var string $address @var string $charity_number
 */
$font = "-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title><?= e($site_name) ?></title>
<style>
  body { margin: 0; padding: 0; }
  a { color: <?= e($c['link']) ?>; }
  .email-body img { max-width: 100%; height: auto; }
  @media (max-width: 620px) {
    .email-shell { padding: 0 !important; }
    .email-card { border-radius: 0 !important; }
    .email-pad { padding-left: 20px !important; padding-right: 20px !important; }
    .email-col { display: block !important; width: 100% !important; }
    .email-h1 { font-size: 24px !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background:#eef1f5;font-family:<?= $font ?>;color:#14202e;-webkit-text-size-adjust:100%">
<?php if ($preheader !== ''): ?><div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent"><?= e($preheader) ?></div><?php endif ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef1f5">
  <tr><td align="center" class="email-shell" style="padding:28px 12px">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" class="email-card" style="width:100%;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 2px 12px rgba(16,24,40,.08)">
      <tr><td class="email-pad" style="background:<?= e($c['brand']) ?>;color:<?= e($c['brand_ink']) ?>;padding:22px 32px">
        <a href="<?= e($site_url) ?>" style="color:<?= e($c['brand_ink']) ?>;text-decoration:none;font-size:20px;font-weight:800;letter-spacing:-.01em">
          <?php if ($logo): ?><img src="<?= e(base_url(media_url($logo, 400))) ?>" alt="<?= e($site_name) ?>" height="40" style="height:40px;width:auto;display:block;border:0"><?php else: ?><?= e($site_name) ?><?php endif ?>
        </a>
      </td></tr>
      <tr><td style="height:4px;background:<?= e($c['accent']) ?>;line-height:4px;font-size:0">&nbsp;</td></tr>
      <tr><td class="email-pad email-body" style="padding:32px;font-size:16px;line-height:1.6;color:#14202e"><?= $body ?></td></tr>
      <tr><td class="email-pad" style="padding:20px 32px 26px;background:<?= e($c['soft']) ?>;color:#4a5668;font-size:13px;line-height:1.55">
        <p style="margin:0 0 6px"><?= e($footer_note) ?></p>
        <p style="margin:0"><strong style="color:#14202e"><?= e($site_name) ?></strong><?= $address !== '' ? ' · ' . e($address) : '' ?><?= $charity_number !== '' ? ' · Registered charity ' . e($charity_number) : '' ?></p>
        <?php if ($unsubscribe_url !== ''): ?><p style="margin:10px 0 0"><a href="<?= e($unsubscribe_url) ?>" style="color:#4a5668;text-decoration:underline">Unsubscribe from these emails</a> · <a href="<?= e(base_url('/account/preferences')) ?>" style="color:#4a5668;text-decoration:underline">Email preferences</a></p><?php endif ?>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
