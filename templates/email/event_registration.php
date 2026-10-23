<?php
declare(strict_types=1);

/** @var array $vars name, event, quantity, ticket, event_url, ics_url, site_name */
$ev = $vars['event'];
$when = fmt_datetime($ev['start_at']) . ' (' . site_tz()->getName() . ')';
$where = trim(implode(', ', array_filter([(string) $ev['venue_name'], (string) $ev['venue_address']])));
$ticket = $vars['ticket'] ? $vars['ticket']['name'] . ($vars['ticket']['price_label'] !== '' ? ' (' . $vars['ticket']['price_label'] . ')' : '') : '';
$text = "Hello {$vars['name']},\n\nYou are registered for {$ev['title']}.\n\nWhen: $when\n"
    . ($where !== '' ? "Where: $where\n" : '')
    . ($ticket !== '' ? "Ticket: $ticket\n" : '')
    . "Places: {$vars['quantity']}\n\nEvent details: {$vars['event_url']}\nAdd to your calendar: {$vars['ics_url']}\n\nSee you there,\n{$vars['site_name']}\n";
$html = '<p>Hello ' . e($vars['name']) . ',</p><p>You are registered for <strong>' . e($ev['title']) . '</strong>.</p>'
    . '<p><strong>When:</strong> ' . e($when) . '<br>'
    . ($where !== '' ? '<strong>Where:</strong> ' . e($where) . '<br>' : '')
    . ($ticket !== '' ? '<strong>Ticket:</strong> ' . e($ticket) . '<br>' : '')
    . '<strong>Places:</strong> ' . (int) $vars['quantity'] . '</p>'
    . '<p><a href="' . e($vars['event_url']) . '" style="color:#0b7577">View the event</a> · <a href="' . e($vars['ics_url']) . '" style="color:#0b7577">Add to calendar</a></p>'
    . '<p>See you there,<br>' . e($vars['site_name']) . '</p>';
return ['subject' => 'You are registered: ' . $ev['title'], 'text' => $text, 'html' => $html];
