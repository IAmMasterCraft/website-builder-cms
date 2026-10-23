<?php
declare(strict_types=1);

namespace Sections;

final class Contact extends Section
{
    public const KEY = 'contact';
    public const LABEL = 'Contact';
    public const DESCRIPTION = 'Contact form, map and your address, phone and email.';
    public const GROUP = 'Contact & embeds';

    public static function schema(): array
    {
        return [
            ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 120, 'default' => 'Get in touch'],
            ['key' => 'intro', 'type' => 'textarea', 'label' => 'Intro text', 'max' => 400, 'default' => 'Send us a message and a volunteer will reply, usually within two working days.'],
            ['key' => 'show_form', 'type' => 'checkbox', 'label' => 'Show the contact form', 'default' => true],
            ['key' => 'show_details', 'type' => 'checkbox', 'label' => 'Show address, phone and email (from Site settings)', 'default' => true],
            ['key' => 'show_map', 'type' => 'checkbox', 'label' => 'Show a map', 'default' => true],
            ['key' => 'map_query', 'type' => 'text', 'label' => 'Map location', 'max' => 200, 'help' => 'An address or place name. Leave empty to use the address from Site settings.', 'show_if' => ['show_map', [true]]],
        ];
    }

    public static function data(array $settings, array $ctx): array
    {
        $query = trim((string) $settings['map_query']) !== '' ? (string) $settings['map_query'] : (string) setting('contact.address', '');
        // One-time messages from the last submission (like flash()).
        $sent = isset($_SESSION['contact_sent']) && is_int($_SESSION['contact_sent']) && $_SESSION['contact_sent'] > time() - 600;
        $errors = is_array($_SESSION['contact_errors'] ?? null) ? $_SESSION['contact_errors'] : [];
        $old = is_array($_SESSION['contact_old'] ?? null) ? $_SESSION['contact_old'] : [];
        if (empty($ctx['preview'])) {
            unset($_SESSION['contact_sent'], $_SESSION['contact_errors'], $_SESSION['contact_old']);
        }
        return [
            'map_src' => $settings['show_map'] && $query !== '' ? 'https://www.google.com/maps?q=' . rawurlencode($query) . '&output=embed' : '',
            'address' => (string) setting('contact.address', ''),
            'phone' => (string) setting('contact.phone', ''),
            'email' => (string) setting('contact.email', ''),
            'sent' => $sent,
            'errors' => $errors,
            'old' => $old,
        ];
    }
}
