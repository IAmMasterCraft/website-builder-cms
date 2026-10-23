<?php
declare(strict_types=1);

/** @var array $vars name, group, number, site_name */
$text = "Hello {$vars['name']},\n\n{$vars['group']} is now registered with {$vars['site_name']} and listed in our groups directory.\n\nYour group ID: {$vars['number']}\n";
$html = '<p>Hello ' . e($vars['name']) . ',</p><p>' . e($vars['group']) . ' is now registered with ' . e($vars['site_name']) . ' and listed in our groups directory.</p>'
    . '<p style="font-size:14px;color:#5a6779;margin-bottom:4px">Your group ID</p><p style="font-size:24px;font-weight:700;margin-top:0">' . e($vars['number']) . '</p>';
return ['subject' => 'Your group is registered (' . $vars['number'] . ')', 'text' => $text, 'html' => $html];
