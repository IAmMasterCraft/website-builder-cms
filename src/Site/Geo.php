<?php
declare(strict_types=1);

namespace Site;

/** Location data that is not in the bundled files (C4): local areas added by admins. */
final class Geo
{
    /** GET /geo/custom.json?v=N: cached for a year when v is current (the URL changes with every edit). */
    public static function custom(array $p): void
    {
        $json = json_encode(geo_custom_payload(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $current = query_param('v') === (string) (int) setting('geo.custom_version', 0);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=' . ($current ? '31536000, immutable' : '300'));
        header('X-Content-Type-Options: nosniff');
        echo $json;
    }
}
