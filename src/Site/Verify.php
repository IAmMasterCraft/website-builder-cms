<?php
declare(strict_types=1);

namespace Site;

/**
 * Public ID card check (§4.3): /verify/{number}/{code} shows valid,
 * expired or not valid, with the holder's name and photo only.
 */
final class Verify
{
    private static function lookup(array $p): array
    {
        $bucket = 'verify:' . client_ip();
        if (rate_limited($bucket, 60, 600)) {
            abort(429);
        }
        rate_hit($bucket);
        return card_lookup(rawurldecode((string) $p['number']), (string) $p['code']);
    }

    public static function show(array $p): void
    {
        $r = self::lookup($p);
        header('Cache-Control: no-store');
        render('site/verify_card', [
            'title' => 'Member card check', 'state' => $r['state'], 'member' => $r['member'],
            'photo_url' => $r['member'] !== null && $r['member']['photo_media_id'] ? '/verify/' . rawurlencode((string) $p['number']) . '/' . $p['code'] . '/photo' : null,
            'noindex' => true,
        ], 'site', $r['state'] === 'invalid' ? 404 : 200);
    }

    /** The holder's photo, only for a matching (valid or expired) card. */
    public static function photo(array $p): void
    {
        $r = self::lookup($p);
        $m = $r['member'] !== null && $r['member']['photo_media_id'] ? media_find((int) $r['member']['photo_media_id']) : null;
        $file = null;
        if ($m !== null && $m['variants'] !== []) {
            $width = array_key_last($m['variants']);
            foreach (array_keys($m['variants']) as $w) {
                if ($w >= 400) {
                    $width = $w;
                    break;
                }
            }
            $file = media_file_path($m, (int) $width);
        }
        if ($m === null || $file === null) {
            abort(404);
        }
        send_file($file, 'image/webp', 'photo.webp', true, false);
    }
}
