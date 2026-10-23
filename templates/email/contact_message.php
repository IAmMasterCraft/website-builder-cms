<?php
declare(strict_types=1);

/** @var array $vars name, email, phone, subject, message, site_name */
$subject = $vars['subject'] !== '' ? $vars['subject'] : 'Message from the website';
$text = "New message from the website contact form.\n\nFrom: {$vars['name']} <{$vars['email']}>\n" . ($vars['phone'] !== '' ? "Phone: {$vars['phone']}\n" : '')
    . "Subject: $subject\n\n{$vars['message']}\n\nReply to this person at {$vars['email']}.\n";
$html = '<p><strong>New message from the website contact form.</strong></p>'
    . '<p>From: ' . e($vars['name']) . ' &lt;<a href="mailto:' . e($vars['email']) . '">' . e($vars['email']) . '</a>&gt;' . ($vars['phone'] !== '' ? '<br>Phone: ' . e($vars['phone']) : '') . '<br>Subject: ' . e($subject) . '</p>'
    . '<p style="white-space:pre-wrap;border-left:4px solid #0b7577;padding-left:12px">' . e($vars['message']) . '</p>';
return ['subject' => 'Website message: ' . mb_substr($subject, 0, 120), 'text' => $text, 'html' => $html];
