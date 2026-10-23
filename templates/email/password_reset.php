<?php
declare(strict_types=1);

/** @var array $vars name, reset_url, site_name */
$text = "Hello {$vars['name']},\n\nSomeone (hopefully you) asked to reset the password for your {$vars['site_name']} account.\n\n"
    . "Choose a new password here (the link works for one hour):\n{$vars['reset_url']}\n\nIf you did not ask for this, you can ignore this email; your password will not change.\n";
$html = '<p>Hello ' . e($vars['name']) . ',</p><p>Someone (hopefully you) asked to reset the password for your ' . e($vars['site_name']) . ' account.</p>'
    . '<p><a href="' . e($vars['reset_url']) . '" style="display:inline-block;background:#12355b;color:#ffffff;padding:12px 20px;border-radius:999px;text-decoration:none;font-weight:600">Choose a new password</a></p>'
    . '<p style="color:#5a6779;font-size:14px">The link works for one hour. If you did not ask for this, ignore this email; your password will not change.</p>';
return ['subject' => 'Reset your password', 'text' => $text, 'html' => $html];
