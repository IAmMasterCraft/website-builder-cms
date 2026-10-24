<?php
declare(strict_types=1);

namespace Sections;

final class Faq extends Section
{
    public const KEY = 'faq';
    public const LABEL = 'FAQ accordion';
    public const DESCRIPTION = 'Questions that open to show their answers, from one category or all.';
    public const GROUP = 'Content';

    public static function schema(): array
    {
        return array_merge(self::headerFields('Frequently asked questions'), [
            ['key' => 'category', 'type' => 'select', 'label' => 'Category', 'options' => 'faq_category_any_options', 'default' => ''],
            self::countField(6, 50),
            ['key' => 'layout', 'type' => 'select', 'label' => 'Layout', 'options' => ['split' => 'Heading beside the questions', 'stacked' => 'Heading above the questions'], 'default' => 'split'],
        ], self::linkFields('All questions', '/faq'));
    }

    public static function data(array $settings, array $ctx): array
    {
        $params = [];
        $where = published_sql() . ' AND ' . visibility_sql('', $ctx['viewer'] ?? null);
        if ((string) $settings['category'] !== '') {
            $where .= ' AND category = ?';
            $params[] = (string) $settings['category'];
        }
        return ['faqs' => q_all("SELECT * FROM faqs WHERE $where ORDER BY category, sort, id LIMIT " . (int) $settings['count'], $params)];
    }
}
