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
            ['key' => 'layout', 'type' => 'select', 'label' => 'Layout', 'options' => ['fullscreen' => 'Full-width photo behind the text', 'split' => 'Text beside the image', 'centered' => 'Centred text over the image'], 'default' => 'fullscreen'],
            ['key' => 'height', 'type' => 'select', 'label' => 'Height', 'options' => ['full' => 'Full screen', 'tall' => 'Tall', 'auto' => 'Fit the text'], 'default' => 'full', 'show_if' => ['layout', ['fullscreen']]],
            ['key' => 'overlay', 'type' => 'select', 'label' => 'Darken the photo', 'options' => ['medium' => 'Medium', 'strong' => 'Strong (busy photos)', 'light' => 'Light'], 'default' => 'medium', 'show_if' => ['layout', ['fullscreen']],
                'help' => 'Keeps white text readable over the photo.'],
            ['key' => 'slides', 'type' => 'repeater', 'label' => 'Images', 'max' => 6, 'item_label' => 'caption', 'add_label' => 'Add image',
                'help' => 'One image shows on its own; two or more become a slider.',
                'fields' => [
                    ['key' => 'media_id', 'type' => 'media', 'label' => 'Image', 'required' => true],
                    ['key' => 'caption', 'type' => 'text', 'label' => 'Caption', 'max' => 120],
                ]],
            ['key' => 'autoplay', 'type' => 'checkbox', 'label' => 'Move the slider on automatically', 'default' => false, 'help' => 'Pauses on hover and focus; off for visitors who prefer reduced motion.'],
            ['key' => 'kenburns', 'type' => 'checkbox', 'label' => 'Slow zoom on the photos', 'default' => true, 'show_if' => ['layout', ['fullscreen']]],
            ['key' => 'video_media_id', 'type' => 'media', 'kind' => 'video', 'label' => 'Background video (optional)', 'show_if' => ['layout', ['fullscreen']],
                'help' => 'A short, silent MP4 or WebM from the media library. It loops muted behind the text; the first image is shown instead for visitors who prefer reduced motion.'],
            ['key' => 'proof_text', 'type' => 'text', 'label' => 'Social proof line (optional)', 'max' => 120, 'default' => '', 'help' => 'For example “Join 1,200 neighbours who already take part”.'],
            ['key' => 'proof_count', 'type' => 'text', 'label' => 'Number in the last avatar (optional)', 'max' => 8, 'default' => '', 'help' => 'For example “+1k”.'],
            ['key' => 'proof_avatars', 'type' => 'repeater', 'label' => 'Faces next to the proof line', 'max' => 4, 'item_label' => 'media_id', 'add_label' => 'Add face',
                'fields' => [['key' => 'media_id', 'type' => 'media', 'label' => 'Photo', 'required' => true]]],
            ['key' => 'float_title', 'type' => 'text', 'label' => 'Floating card title (optional)', 'max' => 60, 'default' => '', 'show_if' => ['layout', ['fullscreen']],
                'help' => 'A glass card in the corner, e.g. “Next event: Harbour festival”.'],
            ['key' => 'float_text', 'type' => 'text', 'label' => 'Floating card text', 'max' => 80, 'default' => '', 'show_if' => ['layout', ['fullscreen']]],
            ['key' => 'float_url', 'type' => 'url', 'label' => 'Floating card link', 'default' => '', 'show_if' => ['layout', ['fullscreen']]],
            ['key' => 'float_icon', 'type' => 'select', 'label' => 'Floating card icon', 'options' => 'icon_options', 'default' => 'calendar', 'show_if' => ['layout', ['fullscreen']]],
        ];
    }

    public static function defaultStyle(): array
    {
        return ['background' => 'dark', 'spacing' => 'roomy', 'width' => 'full'] + parent::defaultStyle();
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
        $video = null;
        if ((int) ($settings['video_media_id'] ?? 0) > 0) {
            $v = media_find((int) $settings['video_media_id']);
            $video = $v !== null && $v['kind'] === 'video' && $v['visibility'] === 'public' ? $v : null;
        }
        return ['slides' => $slides, 'video' => $video, 'avatars' => array_values(array_filter(self::mediaFor($settings['proof_avatars'] ?? [])))];
    }
}
