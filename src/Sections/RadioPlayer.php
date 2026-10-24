<?php
declare(strict_types=1);

namespace Sections;

/**
 * The live radio player (§6.2) as a compact bar or a full panel. Shows an
 * "off air" card while the station is switched off.
 */
final class RadioPlayer extends Section
{
    public const KEY = 'radio_player';
    public const LABEL = 'Radio player';
    public const DESCRIPTION = 'The community radio player, as a compact bar or a full panel.';
    public const GROUP = 'Community';

    public static function schema(): array
    {
        return [
            ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 120, 'default' => 'Listen live'],
            ['key' => 'size', 'type' => 'select', 'label' => 'Size', 'options' => ['mini' => 'Compact bar', 'full' => 'Full player'], 'default' => 'mini'],
        ];
    }

    public static function data(array $settings, array $ctx): array
    {
        return ['on' => radio_enabled()];
    }
}
