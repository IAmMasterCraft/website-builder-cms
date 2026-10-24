<?php
declare(strict_types=1);

namespace Sections;

final class Cta extends Section
{
    public const KEY = 'cta';
    public const LABEL = 'Call to action';
    public const DESCRIPTION = 'A bold band with a short message and one or two buttons.';
    public const GROUP = 'Basics';

    public static function schema(): array
    {
        return [
            ['key' => 'eyebrow', 'type' => 'text', 'label' => 'Small label above the heading', 'max' => 60, 'default' => ''],
            ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 120, 'required' => true, 'default' => 'Be part of it'],
            ['key' => 'text', 'type' => 'textarea', 'label' => 'Text', 'max' => 300, 'default' => 'Membership connects you with events, news and neighbours who care about the same place you do.'],
            ['key' => 'button1_label', 'type' => 'text', 'label' => 'Button text', 'max' => 40, 'default' => 'Join us today'],
            ['key' => 'button1_url', 'type' => 'url', 'label' => 'Button link', 'default' => '/join'],
            ['key' => 'button2_label', 'type' => 'text', 'label' => 'Second button text', 'max' => 40],
            ['key' => 'button2_url', 'type' => 'url', 'label' => 'Second button link'],
            ['key' => 'tone', 'type' => 'select', 'label' => 'Style', 'options' => ['brand' => 'Brand colour', 'accent' => 'Accent colour', 'dark' => 'Dark', 'soft' => 'Soft', 'image' => 'Photo with a glass panel'], 'default' => 'brand'],
            ['key' => 'media_id', 'type' => 'media', 'label' => 'Photo', 'show_if' => ['tone', ['image']]],
        ];
    }

    public static function defaultStyle(): array
    {
        return ['spacing' => 'compact'] + parent::defaultStyle();
    }

    public static function data(array $settings, array $ctx): array
    {
        return ['media' => $settings['tone'] === 'image' ? self::mediaFor([$settings])[0] : null];
    }
}
