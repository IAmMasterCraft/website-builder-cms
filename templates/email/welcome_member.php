<?php
declare(strict_types=1);

/** @var array $vars name, member_number, card_url, site_name */
$text = "Hello {$vars['name']},\n\nWelcome to {$vars['site_name']}! Your email is verified and your membership is active.\n\nYour member number: {$vars['member_number']}\n\n"
    . "Your digital member card (with a QR code anyone can check) is in your account:\n{$vars['card_url']}\n";
$html = '<p>Hello ' . e($vars['name']) . ',</p><p>Welcome to ' . e($vars['site_name']) . '! Your email is verified and your membership is active.</p>'
    . '<p style="font-size:14px;color:#5a6779;margin-bottom:4px">Your member number</p><p style="font-size:24px;font-weight:700;letter-spacing:0.04em;margin-top:0">' . e($vars['member_number']) . '</p>'
    . '<p><a href="' . e($vars['card_url']) . '" style="display:inline-block;background:#12355b;color:#ffffff;padding:12px 20px;border-radius:999px;text-decoration:none;font-weight:600">See my member card</a></p>';
return ['subject' => 'Welcome! Your member number is ' . $vars['member_number'], 'text' => $text, 'html' => $html];
