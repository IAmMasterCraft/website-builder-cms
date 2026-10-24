<?php
declare(strict_types=1);

namespace Sections;

final class Showcase extends Section
{
    public const KEY = 'showcase';
    public const LABEL = 'Numbered showcase';
    public const DESCRIPTION = 'A sticky heading with an index beside large numbered photo cards that stack as you scroll.';
    public const GROUP = 'Story and highlights';

    public static function schema(): array
    {
        return array_merge(self::headerFields('Projects that changed our street', 'A few of the things members have built together.'), self::buttonFields('All projects', '/projects'), [
            ['key' => 'items', 'type' => 'repeater', 'label' => 'Cards', 'min' => 1, 'max' => 6, 'item_label' => 'title', 'add_label' => 'Add card',
                'default' => [
                    ['title' => 'Community garden', 'text' => 'Raised beds and a seed library behind the hall.', 'icon' => 'sprout', 'media_id' => null, 'link_label' => '', 'link_url' => ''],
                    ['title' => 'Warm spaces', 'text' => 'A heated, welcoming hall on cold winter evenings.', 'icon' => 'house-heart', 'media_id' => null, 'link_label' => '', 'link_url' => ''],
                ],
                'fields' => [
                    ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'max' => 100, 'required' => true],
                    ['key' => 'text', 'type' => 'textarea', 'label' => 'Text', 'max' => 300],
                    ['key' => 'media_id', 'type' => 'media', 'label' => 'Photo'],
                    ['key' => 'icon', 'type' => 'select', 'label' => 'Icon', 'options' => 'icon_options_none', 'default' => ''],
                    ['key' => 'link_label', 'type' => 'text', 'label' => 'Link text', 'max' => 40],
                    ['key' => 'link_url', 'type' => 'url', 'label' => 'Link address'],
                ]],
        ]);
    }

    public static function data(array $settings, array $ctx): array
    {
        return ['media' => self::mediaFor($settings['items'])];
    }
}
