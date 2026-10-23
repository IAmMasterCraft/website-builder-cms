<?php
declare(strict_types=1);

namespace Sections;

final class Features extends Section
{
    public const KEY = 'features';
    public const LABEL = 'Feature columns';
    public const DESCRIPTION = 'Two to four cards in a row, each with an icon, title, text and link.';
    public const GROUP = 'Basics';

    public static function schema(): array
    {
        return array_merge(self::headerFields('What we do'), [
            ['key' => 'columns', 'type' => 'select', 'label' => 'Cards per row', 'options' => ['2' => '2', '3' => '3', '4' => '4'], 'default' => '3'],
            ['key' => 'cards', 'type' => 'repeater', 'label' => 'Cards', 'min' => 1, 'max' => 8, 'item_label' => 'title', 'add_label' => 'Add card',
                'default' => [
                    ['icon' => 'calendar', 'title' => 'Events', 'text' => 'Gatherings, classes and celebrations all year round.', 'link_label' => 'See events', 'link_url' => '/events'],
                    ['icon' => 'heart', 'title' => 'Support', 'text' => 'Friendly help and advice when you need it.', 'link_label' => '', 'link_url' => ''],
                    ['icon' => 'users', 'title' => 'Membership', 'text' => 'Join hundreds of neighbours who make things happen.', 'link_label' => 'Join us', 'link_url' => '/join'],
                ],
                'fields' => [
                    ['key' => 'icon', 'type' => 'select', 'label' => 'Icon', 'options' => 'icon_options', 'default' => 'heart'],
                    ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'max' => 80, 'required' => true],
                    ['key' => 'text', 'type' => 'textarea', 'label' => 'Text', 'max' => 300],
                    ['key' => 'link_label', 'type' => 'text', 'label' => 'Link text', 'max' => 40],
                    ['key' => 'link_url', 'type' => 'url', 'label' => 'Link address'],
                ]],
        ]);
    }
}
