<?php
declare(strict_types=1);

/** @var array $vars name, email, member_number, admin_url, site_name */
$text = "{$vars['name']} ({$vars['email']}" . ($vars['member_number'] !== '' ? ", {$vars['member_number']}" : '') . ") has asked for their account to be deleted.\n\n"
    . "Review and confirm the deletion in the admin area:\n{$vars['admin_url']}\n";
$html = '<p>' . e($vars['name']) . ' (' . e($vars['email']) . ($vars['member_number'] !== '' ? ', ' . e($vars['member_number']) : '') . ') has asked for their account to be deleted.</p>'
    . '<p><a href="' . e($vars['admin_url']) . '">Review the request in the admin area</a></p>';
return ['subject' => 'Account deletion request', 'text' => $text, 'html' => $html];
