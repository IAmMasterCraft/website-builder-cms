<?php
declare(strict_types=1);

namespace Core;

/**
 * Allow-list HTML sanitiser for rich text (posts, events, pages...).
 * Unknown elements are unwrapped (their text kept); dangerous ones are removed
 * with their content; every attribute not on the allow-list is dropped.
 */
final class Sanitizer
{
    /** tag => allowed attributes */
    private const ALLOWED = [
        'p' => [], 'br' => [], 'hr' => [], 'h2' => [], 'h3' => [], 'h4' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'blockquote' => [], 'ul' => [], 'ol' => [], 'li' => [], 'code' => [], 'pre' => [],
        'a' => ['href', 'title'], 'img' => ['src', 'alt', 'width', 'height'],
        'figure' => [], 'figcaption' => [], 'table' => [], 'thead' => [], 'tbody' => [],
        'tr' => [], 'th' => ['scope'], 'td' => [], 'sub' => [], 'sup' => [],
    ];

    /** Removed together with everything inside them. */
    private const DROP = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea',
        'select', 'option', 'noscript', 'template', 'svg', 'math', 'link', 'meta', 'base',
        'head', 'title', 'frame', 'frameset', 'applet', 'audio', 'video', 'source', 'canvas',
    ];

    /** Renamed instead of unwrapped. */
    private const RENAME = ['h1' => 'h2', 'h5' => 'h4', 'h6' => 'h4'];

    public static function clean(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><!DOCTYPE html><html><body><div id="__root">' . $html . '</div></body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('__root');
        if ($root === null) {
            return '';
        }
        self::walk($root, $doc);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }

    private static function walk(\DOMNode $node, \DOMDocument $doc): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction || $child instanceof \DOMCdataSection) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP, true)) {
                $node->removeChild($child);
                continue;
            }
            if (isset(self::RENAME[$tag])) {
                $child = self::rename($child, self::RENAME[$tag], $doc);
                $tag = self::RENAME[$tag];
            }
            if (!array_key_exists($tag, self::ALLOWED)) {
                self::walk($child, $doc);
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            self::cleanAttributes($child, self::ALLOWED[$tag]);
            if ($tag === 'img' && !$child->hasAttribute('src')) {
                $node->removeChild($child);
                continue;
            }
            self::walk($child, $doc);
        }
    }

    /** @param list<string> $allowed */
    private static function cleanAttributes(\DOMElement $el, array $allowed): void
    {
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->nodeName);
            $value = trim($attr->nodeValue ?? '');
            if (!in_array($name, $allowed, true)) {
                $el->removeAttribute($attr->nodeName);
                continue;
            }
            if ($name === 'href') {
                $safe = \safe_url($value);
                if ($safe === '') {
                    $el->removeAttribute($attr->nodeName);
                } else {
                    $el->setAttribute('href', $safe);
                }
            } elseif ($name === 'src') {
                $ok = (str_starts_with($value, '/') && !str_starts_with($value, '//')) || str_starts_with(strtolower($value), 'https://');
                if (!$ok || preg_match('/[\x00-\x20"<>]/', $value)) {
                    $el->removeAttribute($attr->nodeName);
                }
            } elseif (($name === 'width' || $name === 'height') && !ctype_digit($value)) {
                $el->removeAttribute($attr->nodeName);
            } elseif ($name === 'scope' && !in_array($value, ['row', 'col'], true)) {
                $el->removeAttribute($attr->nodeName);
            }
        }
        if (strtolower($el->tagName) === 'a' && preg_match('#^https?://#i', $el->getAttribute('href'))) {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private static function rename(\DOMElement $el, string $tag, \DOMDocument $doc): \DOMElement
    {
        $new = $doc->createElement($tag);
        while ($el->firstChild !== null) {
            $new->appendChild($el->firstChild);
        }
        $el->parentNode?->replaceChild($new, $el);
        return $new;
    }
}
