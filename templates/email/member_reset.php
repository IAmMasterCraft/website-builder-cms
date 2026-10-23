<?php
declare(strict_types=1);

/** @var array $vars name, reset_url, site_name */
$text = "Hello {$vars['name']},\n\nAn administrator of {$vars['site_name']} has sent you a link to set a new password:\n{$vars['reset_url']}\n\nThe link works for one hour.\n";
$html = '<p>Hello ' . e($vars['name']) . ',</p><p>An administrator of ' . e($vars['site_name']) . ' has sent you a link to set a new password.</p>'
    . '<p><a href="' . e($vars['reset_url']) . '" style="display:inline-block;background:#12355b;color:#ffffff;padding:12px 20px;border-radius:999px;text-decoration:none;font-weight:600">Set my password</a></p>'
    . '<p style="color:#5a6779;font-size:14px">The link works for one hour.</p>';
return ['subject' => 'Set your password', 'text' => $text, 'html' => $html];
