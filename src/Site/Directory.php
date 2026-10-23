<?php
declare(strict_types=1);

namespace Site;

/** Awards, FAQ, branches and groups: single public list pages. */
final class Directory
{
    public static function awards(array $p): void
    {
        $years = array_map('intval', array_column(q_all('SELECT DISTINCT year FROM awards WHERE ' . published_sql() . ' ORDER BY year DESC'), 'year'));
        $year = input_int('year', 0, true);
        $year = in_array($year, $years, true) ? $year : 0;
        $rows = q_all('SELECT * FROM awards WHERE ' . published_sql() . ($year ? ' AND year = ?' : '') . ' ORDER BY year DESC, sort, id', $year ? [$year] : []);
        media_preload(array_column($rows, 'photo_media_id'));
        $byYear = [];
        foreach ($rows as $r) {
            $byYear[(int) $r['year']][] = $r;
        }
        render('site/awards', ['title' => 'Awards', 'by_year' => $byYear, 'years' => $years, 'year' => $year, 'canonical' => '/awards']);
    }

    public static function faq(array $p): void
    {
        $rows = q_all('SELECT * FROM faqs WHERE ' . published_sql() . ' AND ' . visibility_sql('', current_user()) . ' ORDER BY category, sort, id');
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r['category'] !== '' ? $r['category'] : 'General'][] = $r;
        }
        render('site/faq', ['title' => 'Frequently asked questions', 'groups' => $groups, 'canonical' => '/faq']);
    }

    public static function branches(array $p): void
    {
        $rows = q_all('SELECT * FROM branches WHERE ' . published_sql() . ' ORDER BY sort, name');
        render('site/branches', ['title' => 'Branches', 'rows' => $rows, 'canonical' => '/branches']);
    }

    public static function groups(array $p): void
    {
        $rows = q_all("SELECT * FROM groups_orgs WHERE status = 'active' ORDER BY sort, name");
        media_preload(array_column($rows, 'logo_media_id'));
        render('site/groups', ['title' => 'Affiliated groups', 'rows' => $rows, 'canonical' => '/groups']);
    }
}
