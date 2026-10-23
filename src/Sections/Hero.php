<?php
declare(strict_types=1);

namespace Sections;

final class Hero extends Section
{
    public const KEY = 'hero';
    public const LABEL = 'Hero';
    public const DESCRIPTION = 'Big opening banner: heading, text, up to two buttons, one image or a slider.';
    public const GROUP = 'Basics';

    public static function schema(): array
    {
        return [
            ['key' => 'eyebrow', 'type' => 'text', 'label' => 'Small label above the heading', 'max' => 60, 'default' => 'Welcome'],
            ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 120, 'required' => true, 'default' => 'Bringing our community together'],
            ['key' => 'text', 'type' => 'textarea', 'label' => 'Text', 'max' => 400, 'default' => 'Events, news and support for everyone who calls this place home.'],
            ['key' => 'button1_label', 'type' => 'text', 'label' => 'First button text', 'max' => 40, 'default' => 'See what’s on'],
            ['key' => 'button1_url', 'type' => 'url', 'label' => 'First button link', 'default' => '/events'],
            ['key' => 'button2_label', 'type' => 'text', 'label' => 'Second button text', 'max' => 40, 'default' => 'Become a member'],
            ['key' => 'button2_url', 'type' => 'url', 'label' => 'Second button link', 'default' => '/join'],
            ['key' => 'layout', 'type' => 'select', 'label' => 'Layout', 'options' => ['split' => 'Text beside the image', 'centered' => 'Centred text over the image'], 'default' => 'split'],
            ['key' => 'slides', 'type' => 'repeater', 'label' => 'Images', 'max' => 6, 'item_label' => 'caption', 'add_label' => 'Add image',
                'help' => 'One image shows on its own; two or more become a slider.',
                'fields' => [
                    ['key' => 'media_id', 'type' => 'media', 'label' => 'Image', 'required' => true],
                    ['key' => 'caption', 'type' => 'text', 'label' => 'Caption', 'max' => 120],
                ]],
            ['key' => 'autoplay', 'type' => 'checkbox', 'label' => 'Move the slider on automatically', 'default' => false, 'help' => 'Pauses on hover and focus; off for visitors who prefer reduced motion.'],
        ];
    }

    public static function defaultStyle(): array
    {
        return ['background' => 'brand', 'spacing' => 'roomy'] + parent::defaultStyle();
    }

    public static function data(array $settings, array $ctx): array
    {
        $slides = [];
        media_preload(array_map(static fn (array $s): int => (int) ($s['media_id'] ?? 0), $settings['slides']));
        foreach ($settings['slides'] as $s) {
            $m = media_find((int) ($s['media_id'] ?? 0) ?: null);
            if ($m !== null && $m['kind'] === 'image') {
                $slides[] = ['media' => $m, 'caption' => (string) ($s['caption'] ?? '')];
            }
        }
        return ['slides' => $slides];
    }
}
