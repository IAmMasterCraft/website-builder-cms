<?php
declare(strict_types=1);

/** @var array $vars name, verify_url, days, group?, site_name */
$what = isset($vars['group']) ? 'the account for ' . $vars['group'] : 'your membership';
$text = "Hello {$vars['name']},\n\nThank you for signing up with {$vars['site_name']}. Please confirm this email address to activate $what:\n{$vars['verify_url']}\n\n"
    . "The link works for {$vars['days']} days. Accounts that are not verified are removed after that.\nIf you did not sign up, ignore this email.\n";
$html = '<p>Hello ' . e($vars['name']) . ',</p><p>Thank you for signing up with ' . e($vars['site_name']) . '. Please confirm this email address to activate ' . e($what) . '.</p>'
    . '<p><a href="' . e($vars['verify_url']) . '" style="display:inline-block;background:#12355b;color:#ffffff;padding:12px 20px;border-radius:999px;text-decoration:none;font-weight:600">Verify my email</a></p>'
    . '<p style="color:#5a6779;font-size:14px">The link works for ' . (int) $vars['days'] . ' days; unverified accounts are removed after that. If you did not sign up, ignore this email.</p>';
return ['subject' => 'Please verify your email', 'text' => $text, 'html' => $html];
