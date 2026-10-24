<?php
declare(strict_types=1);

namespace Sections;

final class Tiers extends Section
{
    public const KEY = 'tiers';
    public const LABEL = 'Membership or donation tiers';
    public const DESCRIPTION = 'Two to four price cards with benefits and a button; one can be featured as a dark card.';
    public const GROUP = 'Story and highlights';

    public static function schema(): array
    {
        return array_merge(self::headerFields('Choose how you take part', 'Every level supports our work and gets you a digital member card.'), [
            self::headLayoutField('split'),
            ['key' => 'items', 'type' => 'repeater', 'label' => 'Tiers', 'min' => 1, 'max' => 4, 'item_label' => 'name', 'add_label' => 'Add tier',
                'default' => [
                    ['name' => 'Friend', 'icon' => 'heart', 'from_label' => '', 'price' => 'Free', 'period' => '', 'description' => 'For everyone who wants to stay in touch.', 'features' => "Newsletter and event news\nDigital member card", 'button_label' => 'Join free', 'button_url' => '/join', 'featured' => false, 'badge' => ''],
                    ['name' => 'Supporter', 'icon' => 'hand-heart', 'from_label' => 'Suggested', 'price' => '£5', 'period' => '/month', 'description' => 'Helps keep the hall warm and open.', 'features' => "Everything in Friend\nPriority booking\nVote at the annual meeting", 'button_label' => 'Become a supporter', 'button_url' => '/join', 'featured' => true, 'badge' => 'Most popular'],
                ],
                'fields' => [
                    ['key' => 'name', 'type' => 'text', 'label' => 'Name', 'max' => 60, 'required' => true],
                    ['key' => 'icon', 'type' => 'select', 'label' => 'Icon', 'options' => 'icon_options_none', 'default' => ''],
                    ['key' => 'from_label', 'type' => 'text', 'label' => 'Small label above the price (e.g. Starting at)', 'max' => 30],
                    ['key' => 'price', 'type' => 'text', 'label' => 'Price (e.g. £5 or Free)', 'max' => 20, 'required' => true],
                    ['key' => 'period', 'type' => 'text', 'label' => 'Period (e.g. /month)', 'max' => 20],
                    ['key' => 'description', 'type' => 'textarea', 'label' => 'Short description', 'max' => 200],
                    ['key' => 'features', 'type' => 'textarea', 'label' => 'Benefits, one per line', 'max' => 600, 'help' => 'Up to 8 lines.'],
                    ['key' => 'button_label', 'type' => 'text', 'label' => 'Button text', 'max' => 40],
                    ['key' => 'button_url', 'type' => 'url', 'label' => 'Button link'],
                    ['key' => 'featured', 'type' => 'checkbox', 'label' => 'Feature this tier (dark card)'],
                    ['key' => 'badge', 'type' => 'text', 'label' => 'Badge on the card (e.g. Most popular)', 'max' => 30],
                ]],
        ]);
    }

    public static function defaultStyle(): array
    {
        return ['background' => 'soft'] + parent::defaultStyle();
    }

    /** Benefit lines (trimmed, at most 8). */
    public static function lines(string $text): array
    {
        return array_slice(array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: []), static fn (string $l): bool => $l !== '')), 0, 8);
    }
}
