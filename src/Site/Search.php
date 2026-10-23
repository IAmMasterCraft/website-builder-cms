<?php
declare(strict_types=1);

namespace Site;

final class Search
{
    public static function show(array $p): void
    {
        $q = mb_substr(query_param('q'), 0, 100);
        $results = [];
        if ($q !== '') {
            $bucket = 'search|' . client_ip();
            if (rate_limited($bucket, 60, 60)) {
                abort(429);
            }
            rate_hit($bucket);
            $results = site_search($q, current_user());
        }
        render('site/search', ['title' => $q !== '' ? 'Search: ' . $q : 'Search', 'q' => $q, 'results' => $results, 'noindex' => true]);
    }
}
