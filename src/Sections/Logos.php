<?php
declare(strict_types=1);

namespace Sections;

final class Logos extends Section
{
    public const KEY = 'logos';
    public const LABEL = 'Partners and sponsors';
    public const DESCRIPTION = 'A row of partner logos or names that scrolls slowly in a loop (pauses on hover), or a still grid.';
    public const GROUP = 'Story and highlights';

    public static function schema(): array
    {
        return [
            ['key' => 'eyebrow', 'type' => 'text', 'label' => 'Small label', 'max' => 60, 'default' => 'Proudly supported by'],
            ['key' => 'heading', 'type' => 'text', 'label' => 'Heading (optional)', 'max' => 160, 'default' => ''],
            ['key' => 'style', 'type' => 'select', 'label' => 'Display', 'options' => ['marquee' => 'Scrolling row', 'grid' => 'Still grid'], 'default' => 'marquee'],
            ['key' => 'speed', 'type' => 'select', 'label' => 'Scrolling speed', 'options' => ['slow' => 'Slow', 'normal' => 'Normal', 'fast' => 'Fast'], 'default' => 'normal', 'show_if' => ['style', ['marquee']]],
            ['key' => 'items', 'type' => 'repeater', 'label' => 'Partners', 'min' => 1, 'max' => 16, 'item_label' => 'name', 'add_label' => 'Add partner',
                'help' => 'Upload a logo, or leave it empty to show the name with an icon.',
                'default' => [
                    ['name' => 'Town Council', 'media_id' => null, 'icon' => 'landmark', 'url' => ''],
                    ['name' => 'Community Fund', 'media_id' => null, 'icon' => 'hand-heart', 'url' => ''],
                    ['name' => 'City Library', 'media_id' => null, 'icon' => 'book-open', 'url' => ''],
                ],
                'fields' => [
                    ['key' => 'name', 'type' => 'text', 'label' => 'Name', 'max' => 60, 'required' => true],
                    ['key' => 'media_id', 'type' => 'media', 'label' => 'Logo (optional)'],
                    ['key' => 'icon', 'type' => 'select', 'label' => 'Icon when there is no logo', 'options' => 'icon_options_none', 'default' => ''],
                    ['key' => 'url', 'type' => 'url', 'label' => 'Website (optional)'],
                ]],
        ];
    }

    public static function defaultStyle(): array
    {
        return ['spacing' => 'compact'] + parent::defaultStyle();
    }

    public static function data(array $settings, array $ctx): array
    {
        return ['media' => self::mediaFor($settings['items'])];
    }
}
