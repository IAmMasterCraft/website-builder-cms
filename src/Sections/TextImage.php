<?php
declare(strict_types=1);

namespace Sections;

final class TextImage extends Section
{
    public const KEY = 'text_image';
    public const LABEL = 'Text and image';
    public const DESCRIPTION = 'A picture beside a block of text, image on the left or right.';
    public const GROUP = 'Basics';

    public static function schema(): array
    {
        return [
            ['key' => 'eyebrow', 'type' => 'text', 'label' => 'Small label above the heading', 'max' => 60],
            ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 160, 'default' => 'Our story'],
            ['key' => 'body', 'type' => 'richtext', 'label' => 'Text', 'default' => '<p>Tell visitors who you are and why it matters.</p>'],
            ['key' => 'media_id', 'type' => 'media', 'label' => 'Image'],
            ['key' => 'image_position', 'type' => 'select', 'label' => 'Image position', 'options' => ['left' => 'Left', 'right' => 'Right'], 'default' => 'right'],
            ['key' => 'button_label', 'type' => 'text', 'label' => 'Button text', 'max' => 40],
            ['key' => 'button_url', 'type' => 'url', 'label' => 'Button link'],
        ];
    }

    public static function data(array $settings, array $ctx): array
    {
        $m = media_find((int) ($settings['media_id'] ?? 0) ?: null);
        return ['media' => $m !== null && $m['kind'] === 'image' ? $m : null];
    }
}
