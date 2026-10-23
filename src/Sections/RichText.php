<?php
declare(strict_types=1);

namespace Sections;

final class RichText extends Section
{
    public const KEY = 'rich_text';
    public const LABEL = 'Rich text';
    public const DESCRIPTION = 'Formatted text with headings, lists and links, with an optional sidebar.';
    public const GROUP = 'Basics';

    public static function schema(): array
    {
        return [
            ['key' => 'eyebrow', 'type' => 'text', 'label' => 'Small label above the heading', 'max' => 60],
            ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 160],
            ['key' => 'body', 'type' => 'richtext', 'label' => 'Text', 'default' => '<p>Write something here.</p>'],
            ['key' => 'sidebar', 'type' => 'select', 'label' => 'Sidebar', 'options' => ['none' => 'No sidebar', 'right' => 'Sidebar on the right', 'left' => 'Sidebar on the left'], 'default' => 'none'],
            ['key' => 'sidebar_title', 'type' => 'text', 'label' => 'Sidebar heading', 'max' => 120, 'show_if' => ['sidebar', ['right', 'left']]],
            ['key' => 'sidebar_body', 'type' => 'richtext', 'label' => 'Sidebar text', 'show_if' => ['sidebar', ['right', 'left']]],
        ];
    }
}
