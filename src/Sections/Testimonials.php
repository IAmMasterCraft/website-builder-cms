<?php
declare(strict_types=1);

namespace Sections;

final class Testimonials extends Section
{
    public const KEY = 'testimonials';
    public const LABEL = 'Testimonials';
    public const DESCRIPTION = 'Quotes from members and partners with name, role, photo and rating, as a slider or a grid.';
    public const GROUP = 'Story and highlights';

    public static function schema(): array
    {
        return array_merge(self::headerFields('In their words', 'What members and partners say about being part of the association.'), [
            self::headLayoutField('split'),
            ['key' => 'layout', 'type' => 'select', 'label' => 'Layout', 'options' => ['slider' => 'Slider', 'grid' => 'Grid'], 'default' => 'slider'],
            ['key' => 'autoplay', 'type' => 'checkbox', 'label' => 'Move the slider on automatically', 'default' => false, 'help' => 'Pauses on hover and focus; off for visitors who prefer reduced motion.'],
            ['key' => 'items', 'type' => 'repeater', 'label' => 'Quotes', 'min' => 1, 'max' => 10, 'item_label' => 'name', 'add_label' => 'Add quote',
                'default' => [
                    ['quote' => 'I moved here knowing nobody. Within a month I had friends, a garden plot and a reason to get up on Saturdays.', 'name' => 'Ama B.', 'role' => 'Member since last spring', 'media_id' => null, 'rating' => '5'],
                    ['quote' => 'Professional, warm and always ready to help. Working with the association is a pleasure.', 'name' => 'Local school', 'role' => 'Partner', 'media_id' => null, 'rating' => '5'],
                ],
                'fields' => [
                    ['key' => 'quote', 'type' => 'textarea', 'label' => 'Quote', 'max' => 400, 'required' => true],
                    ['key' => 'name', 'type' => 'text', 'label' => 'Name', 'max' => 80, 'required' => true],
                    ['key' => 'role', 'type' => 'text', 'label' => 'Role or relationship', 'max' => 80],
                    ['key' => 'media_id', 'type' => 'media', 'label' => 'Photo (optional)'],
                    ['key' => 'rating', 'type' => 'select', 'label' => 'Star rating', 'options' => ['' => 'No stars', '5' => '5 stars', '4' => '4 stars', '3' => '3 stars'], 'default' => ''],
                ]],
        ]);
    }

    public static function defaultStyle(): array
    {
        return ['background' => 'dark'] + parent::defaultStyle();
    }

    public static function data(array $settings, array $ctx): array
    {
        return ['media' => self::mediaFor($settings['items'])];
    }
}
