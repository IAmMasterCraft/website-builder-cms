<?php
declare(strict_types=1);

namespace Sections;

/** Members who opted in to the celebrants list (§4.5). Never shows the birth year. */
final class Birthdays extends Section
{
    public const KEY = 'birthdays';
    public const LABEL = 'Birthday celebrants';
    public const DESCRIPTION = 'Members celebrating today or this week (only those who opted in).';
    public const GROUP = 'Community';

    public static function schema(): array
    {
        return [
            ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 120, 'default' => 'Happy birthday!'],
            ['key' => 'intro', 'type' => 'textarea', 'label' => 'Intro text', 'max' => 300, 'default' => 'Warm wishes to the members celebrating.'],
            ['key' => 'range', 'type' => 'select', 'label' => 'Show birthdays', 'options' => ['today' => 'Today', 'week' => 'This week (next 7 days)'], 'default' => 'today'],
            ['key' => 'empty_text', 'type' => 'text', 'label' => 'Text when nobody is celebrating', 'max' => 160, 'default' => 'No birthdays to celebrate today. Check back tomorrow!'],
            ['key' => 'hide_when_empty', 'type' => 'checkbox', 'label' => 'Hide the section when nobody is celebrating', 'default' => false],
        ];
    }

    public static function data(array $settings, array $ctx): array
    {
        return ['people' => birthday_celebrants((string) $settings['range'], $ctx['now'] ?? null)];
    }
}
