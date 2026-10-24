<?php
declare(strict_types=1);

namespace Sections;

/**
 * One page-builder section type (§3.1). Subclasses declare their settings
 * schema (which drives validation and the editor's settings form) and may
 * load data for rendering; the markup lives in templates/sections/<key>.php.
 *
 * Schema field: [key, type, label, default?, required?, max?, min?, help?,
 *   options? (array or option-provider name), fields? (repeater), show_if?].
 * Types: text, textarea, richtext, url, number, select, checkbox, media
 * (images; 'kind' => 'video' for videos), repeater.
 */
abstract class Section
{
    public const KEY = '';
    public const LABEL = '';
    public const DESCRIPTION = '';
    /** Picker group: Basics, Content, Community, Contact & embeds. */
    public const GROUP = 'Basics';

    /** @return list<array<string,mixed>> */
    abstract public static function schema(): array;

    /** Extra data for the template (queries). Must not change anything. */
    public static function data(array $settings, array $ctx): array
    {
        return [];
    }

    /** Default style for a new section of this type. */
    public static function defaultStyle(): array
    {
        return ['background' => 'none', 'bg_media_id' => null, 'spacing' => 'normal', 'width' => 'contained', 'anchor' => ''];
    }

    /** Short text for the editor's section list (e.g. the heading). */
    public static function summary(array $settings): string
    {
        foreach (['heading', 'title', 'label'] as $k) {
            if (isset($settings[$k]) && is_string($settings[$k]) && trim($settings[$k]) !== '') {
                return trim($settings[$k]);
            }
        }
        return '';
    }

    public static function render(array $settings, array $ctx): string
    {
        return view('sections/' . static::KEY, [
            's' => $settings,
            'd' => static::data($settings, $ctx),
            'ctx' => $ctx,
            'h' => $ctx['heading_tag'] ?? 'h2',
        ]);
    }

    /** Defaults for every top-level field. */
    public static function defaults(): array
    {
        $out = [];
        foreach (static::schema() as $f) {
            $out[$f['key']] = $f['default'] ?? match ($f['type']) {
                'checkbox' => false,
                'number' => $f['min'] ?? 0,
                'repeater' => [],
                'media' => null,
                'select' => is_array($f['options'] ?? null) ? (string) array_key_first($f['options']) : '',
                default => '',
            };
        }
        return $out;
    }

    // ---- Shared field groups --------------------------------------------------

    /** Eyebrow + heading + intro shown above most sections. */
    protected static function headerFields(string $heading = '', string $intro = ''): array
    {
        return [
            ['key' => 'eyebrow', 'type' => 'text', 'label' => 'Small label above the heading', 'max' => 60, 'default' => ''],
            ['key' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 160, 'default' => $heading],
            ['key' => 'intro', 'type' => 'textarea', 'label' => 'Intro text', 'max' => 500, 'default' => $intro],
        ];
    }

    /** How the heading block is laid out: stacked, heading beside the intro, or centred. */
    protected static function headLayoutField(string $default = 'stacked'): array
    {
        return ['key' => 'head_layout', 'type' => 'select', 'label' => 'Heading layout', 'options' => ['stacked' => 'Heading above the intro', 'split' => 'Heading left, intro right', 'center' => 'Centred'], 'default' => $default];
    }

    /** One call-to-action button (text + link). */
    protected static function buttonFields(string $label = '', string $url = ''): array
    {
        return [
            ['key' => 'button_label', 'type' => 'text', 'label' => 'Button text', 'max' => 40, 'default' => $label, 'help' => 'Leave empty to hide the button.'],
            ['key' => 'button_url', 'type' => 'url', 'label' => 'Button link', 'default' => $url],
        ];
    }

    /** Media rows for a list of repeater items, loaded in one query. */
    protected static function mediaFor(array $items, string $key = 'media_id'): array
    {
        media_preload(array_map(static fn (array $i): int => (int) ($i[$key] ?? 0), $items));
        $out = [];
        foreach ($items as $n => $i) {
            $m = media_find((int) ($i[$key] ?? 0) ?: null);
            $out[$n] = $m !== null && $m['kind'] === 'image' ? $m : null;
        }
        return $out;
    }

    /** A "View all" style link. */
    protected static function linkFields(string $label = '', string $url = ''): array
    {
        return [
            ['key' => 'link_label', 'type' => 'text', 'label' => 'Link text', 'max' => 60, 'default' => $label, 'help' => 'Leave empty to hide the link.'],
            ['key' => 'link_url', 'type' => 'url', 'label' => 'Link address', 'default' => $url],
        ];
    }

    protected static function countField(int $default, int $max = 12, string $label = 'How many to show'): array
    {
        return ['key' => 'count', 'type' => 'number', 'label' => $label, 'min' => 1, 'max' => $max, 'default' => $default];
    }
}
