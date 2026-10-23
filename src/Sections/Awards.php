<?php
declare(strict_types=1);

namespace Sections;

final class Awards extends Section
{
    public const KEY = 'awards';
    public const LABEL = 'Awards';
    public const DESCRIPTION = 'Award winners grouped by year: the latest year, all years or one year.';
    public const GROUP = 'Community';

    public static function schema(): array
    {
        return array_merge(self::headerFields('Awards and recognition'), [
            ['key' => 'year', 'type' => 'select', 'label' => 'Year', 'options' => 'award_year_any_options', 'default' => 'latest'],
            self::countField(6, 30),
        ], self::linkFields('All awards', '/awards'));
    }

    public static function data(array $settings, array $ctx): array
    {
        $year = (string) $settings['year'];
        $params = [];
        $where = published_sql();
        if ($year === 'latest') {
            $latest = q_val('SELECT MAX(year) FROM awards WHERE ' . published_sql());
            if ($latest === null) {
                return ['by_year' => []];
            }
            $where .= ' AND year = ?';
            $params[] = (int) $latest;
        } elseif (ctype_digit($year)) {
            $where .= ' AND year = ?';
            $params[] = (int) $year;
        }
        $rows = q_all("SELECT * FROM awards WHERE $where ORDER BY year DESC, sort, id LIMIT " . (int) $settings['count'], $params);
        media_preload(array_column($rows, 'photo_media_id'));
        $byYear = [];
        foreach ($rows as $r) {
            $byYear[(int) $r['year']][] = $r;
        }
        return ['by_year' => $byYear];
    }
}
