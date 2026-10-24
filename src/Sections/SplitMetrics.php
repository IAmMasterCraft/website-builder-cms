<?php
declare(strict_types=1);

namespace Sections;

final class SplitMetrics extends Section
{
    public const KEY = 'split_metrics';
    public const LABEL = 'Image with key numbers';
    public const DESCRIPTION = 'A large photo beside a heading, text, a button and two to four key numbers with accent rules.';
    public const GROUP = 'Story and highlights';

    public static function schema(): array
    {
        return array_merge(
            [
                ['key' => 'eyebrow', 'type' => 'text', 'label' => 'Small label above the heading', 'max' => 60, 'default' => 'At a glance'],
                ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 160, 'required' => true, 'default' => 'A neighbourhood that looks after its own'],
                ['key' => 'text', 'type' => 'textarea', 'label' => 'Text', 'max' => 600, 'default' => 'From the first welcome to a lifetime of friendships, our volunteers make sure nobody is left on their own.'],
            ],
            self::buttonFields('Learn more', '/about-us'),
            [
                ['key' => 'media_id', 'type' => 'media', 'label' => 'Image'],
                ['key' => 'image_position', 'type' => 'select', 'label' => 'Image position', 'options' => ['left' => 'Left', 'right' => 'Right'], 'default' => 'left'],
                ['key' => 'chip_value', 'type' => 'text', 'label' => 'Floating number on the image (optional)', 'max' => 16, 'default' => ''],
                ['key' => 'chip_label', 'type' => 'text', 'label' => 'Floating number label', 'max' => 60, 'default' => '', 'help' => 'For example “48+” and “volunteers every week”.'],
                ['key' => 'metrics', 'type' => 'repeater', 'label' => 'Key numbers', 'max' => 4, 'item_label' => 'label', 'add_label' => 'Add number',
                    'default' => [['value' => '25+', 'label' => 'Years in the community'], ['value' => '1,200', 'label' => 'Members and friends']],
                    'fields' => [
                        ['key' => 'value', 'type' => 'text', 'label' => 'Number (e.g. 25+ or £12k)', 'max' => 16, 'required' => true],
                        ['key' => 'label', 'type' => 'text', 'label' => 'Label', 'max' => 60, 'required' => true],
                    ]],
            ]
        );
    }

    public static function data(array $settings, array $ctx): array
    {
        return ['media' => self::mediaFor([$settings])[0]];
    }
}
