<?php
declare(strict_types=1);

namespace Sections;

final class Process extends Section
{
    public const KEY = 'process';
    public const LABEL = 'Process and timeline';
    public const DESCRIPTION = 'Numbered steps on a timeline that fills as you scroll, with an optional photo and button.';
    public const GROUP = 'Story and highlights';

    public static function schema(): array
    {
        return array_merge(self::headerFields('How it works', 'Getting involved is simple. Here is what happens after you say hello.'), self::buttonFields('Join now', '/join'), [
            ['key' => 'media_id', 'type' => 'media', 'label' => 'Photo beside the steps (optional)'],
            ['key' => 'layout', 'type' => 'select', 'label' => 'Layout', 'options' => ['vertical' => 'Vertical timeline', 'horizontal' => 'Cards in a row'], 'default' => 'vertical'],
            ['key' => 'steps', 'type' => 'repeater', 'label' => 'Steps', 'min' => 2, 'max' => 8, 'item_label' => 'title', 'add_label' => 'Add step',
                'default' => [
                    ['title' => 'Say hello', 'text' => 'Join online in five minutes or drop in at any event.'],
                    ['title' => 'Confirm your email', 'text' => 'One click and your digital member card is ready.'],
                    ['title' => 'Find your people', 'text' => 'Pick the clubs, branches and events that suit you.'],
                    ['title' => 'Make a difference', 'text' => 'Volunteer, vote at the annual meeting, lead a project.'],
                ],
                'fields' => [
                    ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'max' => 80, 'required' => true],
                    ['key' => 'text', 'type' => 'textarea', 'label' => 'Text', 'max' => 300],
                ]],
        ]);
    }

    public static function data(array $settings, array $ctx): array
    {
        return ['media' => self::mediaFor([$settings])[0]];
    }
}
