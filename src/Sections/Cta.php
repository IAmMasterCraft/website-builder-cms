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
            ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 120, 'required' => true, 'default' => 'Be part of it'],
            ['key' => 'text', 'type' => 'textarea', 'label' => 'Text', 'max' => 300, 'default' => 'Membership connects you with events, news and neighbours who care about the same place you do.'],
            ['key' => 'button1_label', 'type' => 'text', 'label' => 'Button text', 'max' => 40, 'default' => 'Join us today'],
            ['key' => 'button1_url', 'type' => 'url', 'label' => 'Button link', 'default' => '/join'],
            ['key' => 'button2_label', 'type' => 'text', 'label' => 'Second button text', 'max' => 40],
            ['key' => 'button2_url', 'type' => 'url', 'label' => 'Second button link'],
            ['key' => 'tone', 'type' => 'select', 'label' => 'Colour', 'options' => ['brand' => 'Brand colour', 'accent' => 'Accent colour', 'soft' => 'Soft'], 'default' => 'brand'],
        ];
    }

    public static function defaultStyle(): array
    {
        return ['spacing' => 'compact'] + parent::defaultStyle();
    }
}
