<?php
declare(strict_types=1);

namespace Sections;

final class Spacer extends Section
{
    public const KEY = 'spacer';
    public const LABEL = 'Spacer or divider';
    public const DESCRIPTION = 'Breathing room between sections, with an optional line.';
    public const GROUP = 'Basics';

    public static function schema(): array
    {
        return [
            ['key' => 'size', 'type' => 'select', 'label' => 'Height', 'options' => ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'], 'default' => 'medium'],
            ['key' => 'divider', 'type' => 'select', 'label' => 'Divider', 'options' => ['none' => 'None', 'line' => 'Line', 'dots' => 'Three dots'], 'default' => 'line'],
        ];
    }

    public static function defaultStyle(): array
    {
        return ['spacing' => 'compact'] + parent::defaultStyle();
    }

    public static function summary(array $settings): string
    {
        return ucfirst((string) $settings['size']) . ($settings['divider'] !== 'none' ? ', ' . $settings['divider'] : '');
    }
}
