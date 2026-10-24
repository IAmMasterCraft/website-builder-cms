<?php
declare(strict_types=1);

namespace Sections;

final class AboutBand extends Section
{
    public const KEY = 'about_band';
    public const LABEL = 'Story with counters';
    public const DESCRIPTION = 'A dark band: heading and story side by side, then a wide rounded photo with counters over it.';
    public const GROUP = 'Story and highlights';

    public static function schema(): array
    {
        return array_merge(
            [
                ['key' => 'eyebrow', 'type' => 'text', 'label' => 'Small label above the heading', 'max' => 60, 'default' => 'About us'],
                ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 160, 'required' => true, 'default' => 'Rooted here for a generation'],
                ['key' => 'text', 'type' => 'textarea', 'label' => 'Story', 'max' => 900, 'default' => 'We started as a handful of neighbours with a kettle and a borrowed hall. Today we run events, clubs and support for families right across the area.'],
            ],
            self::buttonFields('', ''),
            [
                ['key' => 'media_id', 'type' => 'media', 'label' => 'Wide photo'],
                ['key' => 'counters', 'type' => 'repeater', 'label' => 'Counters on the photo', 'max' => 4, 'item_label' => 'label', 'add_label' => 'Add counter',
                    'default' => [['value' => '30', 'label' => 'Years of service'], ['value' => '120', 'label' => 'Events a year'], ['value' => '600+', 'label' => 'Volunteer hours a month']],
                    'help' => 'Numbers count up when they scroll into view.',
                    'fields' => [
                        ['key' => 'value', 'type' => 'text', 'label' => 'Number (e.g. 30 or 600+)', 'max' => 16, 'required' => true],
                        ['key' => 'label', 'type' => 'text', 'label' => 'Label', 'max' => 60, 'required' => true],
                    ]],
            ]
        );
    }

    public static function defaultStyle(): array
    {
        return ['background' => 'dark'] + parent::defaultStyle();
    }

    public static function data(array $settings, array $ctx): array
    {
        return ['media' => self::mediaFor([$settings])[0]];
    }
}
