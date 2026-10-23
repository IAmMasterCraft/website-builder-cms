<?php
declare(strict_types=1);

namespace Site;

/** Serves originals (and private variants) after an access check. */
final class MediaFiles
{
    public static function original(array $p): void
    {
        $m = media_find((int) $p['id']);
        if ($m === null || !media_can_view($m, current_user())) {
            abort(404);
        }
        $file = media_file_path($m);
        if ($file === null) {
            abort(404);
        }
        $inline = in_array($m['kind'], ['image', 'audio', 'video'], true) || $m['mime'] === 'application/pdf';
        send_file($file, (string) $m['mime'], basename((string) $p['name']), $inline, $m['visibility'] === 'public');
    }

    public static function variant(array $p): void
    {
        $m = media_find((int) $p['id']);
        if ($m === null || $m['kind'] !== 'image' || !media_can_view($m, current_user())) {
            abort(404);
        }
        $file = media_file_path($m, (int) $p['w']);
        if ($file === null) {
            abort(404);
        }
        send_file($file, 'image/webp', $m['id'] . '-' . (int) $p['w'] . '.webp', true, $m['visibility'] === 'public');
    }
}
