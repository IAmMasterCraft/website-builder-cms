<?php
declare(strict_types=1);

namespace Sections;

/** Sanitised iframe from an allow-list (§3.1, §11). */
final class Embed extends Section
{
    public const KEY = 'embed';
    public const LABEL = 'Embed';
    public const DESCRIPTION = 'A YouTube or Vimeo video, Google map, or Facebook / X timeline.';
    public const GROUP = 'Contact & embeds';

    public static function schema(): array
    {
        return [
            ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 120],
            ['key' => 'url', 'type' => 'text', 'label' => 'Link to the video, map or page', 'max' => 2000,
                'help' => 'Paste a YouTube, Vimeo, Google Maps embed, Facebook page or X profile link.'],
            ['key' => 'title', 'type' => 'text', 'label' => 'Description for screen readers', 'max' => 120, 'required' => true, 'default' => 'Embedded content'],
            ['key' => 'aspect', 'type' => 'select', 'label' => 'Shape', 'options' => ['16x9' => 'Widescreen 16:9', '4x3' => 'Standard 4:3', '1x1' => 'Square', '3x4' => 'Tall (timelines)'], 'default' => '16x9'],
            ['key' => 'caption', 'type' => 'text', 'label' => 'Caption', 'max' => 200],
        ];
    }

    public static function data(array $settings, array $ctx): array
    {
        return ['embed' => embed_resolve((string) $settings['url'])];
    }
}
