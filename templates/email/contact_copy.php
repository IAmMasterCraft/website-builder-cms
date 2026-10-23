<?php
declare(strict_types=1);

/** @var array $vars name, email, subject, message, site_name */
$text = "Hello {$vars['name']},\n\nThank you for getting in touch with {$vars['site_name']}. This is a copy of your message; a volunteer will reply by email.\n\n---\n{$vars['message']}\n---\n";
$html = '<p>Hello ' . e($vars['name']) . ',</p><p>Thank you for getting in touch with ' . e($vars['site_name']) . '. This is a copy of your message; a volunteer will reply by email.</p>'
    . '<p style="white-space:pre-wrap;border-left:4px solid #d6dee8;padding-left:12px;color:#2f3b4c">' . e($vars['message']) . '</p>';
return ['subject' => 'We received your message', 'text' => $text, 'html' => $html];
