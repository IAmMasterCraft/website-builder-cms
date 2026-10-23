<?php
declare(strict_types=1);

namespace Sections;

/**
 * The radio arrives in phase 6. Until its tables exist this section shows a
 * friendly "off air" state; afterwards it reads the station status.
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
        $available = db_table_exists('radio_media');
        return ['available' => $available, 'on' => $available && (bool) setting('radio.enabled', false)];
    }
}
