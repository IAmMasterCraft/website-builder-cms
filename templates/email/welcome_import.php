<?php
declare(strict_types=1);

/** @var array $vars name, member_number, login_url, site_name */
$text = "Hello {$vars['name']},\n\nYou now have an online account with {$vars['site_name']}. Your member number is {$vars['member_number']}.\n\n"
    . "To log in for the first time, choose a password here (use “Forgot password” with this email address):\n{$vars['login_url']}\n";
$html = '<p>Hello ' . e($vars['name']) . ',</p><p>You now have an online account with ' . e($vars['site_name']) . '. Your member number is <strong>' . e($vars['member_number']) . '</strong>.</p>'
    . '<p>To log in for the first time, choose a password with “Forgot password” and this email address:</p>'
    . '<p><a href="' . e($vars['login_url']) . '" style="display:inline-block;background:#12355b;color:#ffffff;padding:12px 20px;border-radius:999px;text-decoration:none;font-weight:600">Choose my password</a></p>';
return ['subject' => 'Your member account is ready', 'text' => $text, 'html' => $html];
