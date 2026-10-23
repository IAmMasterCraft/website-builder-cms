<?php
declare(strict_types=1);

namespace Sections;

/** Donation details and external links only: there is no online payment (D6). */
final class Donate extends Section
{
    public const KEY = 'donate';
    public const LABEL = 'Donate';
    public const DESCRIPTION = 'How to give: bank details and buttons linking to your donation pages.';
    public const GROUP = 'Contact & embeds';

    public static function schema(): array
    {
        return [
            ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 120, 'default' => 'Support our work'],
            ['key' => 'text', 'type' => 'textarea', 'label' => 'Text', 'max' => 600, 'default' => 'Every gift helps us keep our doors open and our events free.'],
            ['key' => 'use_site_details', 'type' => 'checkbox', 'label' => 'Use the bank details and links from Site settings', 'default' => true],
            ['key' => 'bank_text', 'type' => 'textarea', 'label' => 'Bank details', 'max' => 600, 'show_if' => ['use_site_details', [false]]],
            ['key' => 'links', 'type' => 'repeater', 'label' => 'Donation buttons', 'max' => 4, 'item_label' => 'label', 'add_label' => 'Add button', 'show_if' => ['use_site_details', [false]],
                'fields' => [
                    ['key' => 'label', 'type' => 'text', 'label' => 'Button text', 'max' => 40, 'required' => true],
                    ['key' => 'url', 'type' => 'url', 'label' => 'Link (https://…)', 'required' => true],
                ]],
        ];
    }

    public static function data(array $settings, array $ctx): array
    {
        if ($settings['use_site_details']) {
            return ['bank_text' => (string) setting('donate.bank_text', ''), 'links' => donate_links()];
        }
        return ['bank_text' => (string) $settings['bank_text'], 'links' => array_values(array_filter($settings['links'], static fn (array $l): bool => safe_url($l['url'] ?? '') !== ''))];
    }
}
