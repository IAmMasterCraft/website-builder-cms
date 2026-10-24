<?php
declare(strict_types=1);

namespace Sections;

final class Points extends Section
{
    public const KEY = 'points';
    public const LABEL = 'Highlights list';
    public const DESCRIPTION = 'Heading and text on one side, a list of short highlights in soft rows on the other.';
    public const GROUP = 'Story and highlights';

    public static function schema(): array
    {
        return array_merge(
            [
                ['key' => 'eyebrow', 'type' => 'text', 'label' => 'Small label above the heading', 'max' => 60, 'default' => 'Why join'],
                ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 160, 'required' => true, 'default' => 'More than a membership card'],
                ['key' => 'text', 'type' => 'textarea', 'label' => 'Text', 'max' => 600, 'default' => 'Members shape what we do and get first news of everything that is happening.'],
            ],
            self::buttonFields('Become a member', '/join'),
            [
                ['key' => 'list_label', 'type' => 'text', 'label' => 'Small label above the list (optional)', 'max' => 60, 'default' => ''],
                ['key' => 'style', 'type' => 'select', 'label' => 'Markers', 'options' => ['dots' => 'Dots', 'checks' => 'Ticks', 'numbers' => 'Numbers'], 'default' => 'dots'],
                ['key' => 'points', 'type' => 'repeater', 'label' => 'Highlights', 'min' => 1, 'max' => 8, 'item_label' => 'title', 'add_label' => 'Add highlight',
                    'default' => [
                        ['title' => 'A real say', 'text' => 'Vote at general meetings and stand for the committee.'],
                        ['title' => 'Events first', 'text' => 'Book places before they open to everyone.'],
                        ['title' => 'Friendly support', 'text' => 'Advice and a helping hand when life gets hard.'],
                    ],
                    'fields' => [
                        ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'max' => 80, 'required' => true],
                        ['key' => 'text', 'type' => 'textarea', 'label' => 'Text', 'max' => 200],
                    ]],
            ]
        );
    }
}
