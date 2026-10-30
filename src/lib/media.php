<?php
declare(strict_types=1);

use Core\UploadException;

const MEDIA_VARIANT_WIDTHS = [400, 800, 1600];
const MEDIA_MAX_PIXELS = 40_000_000;

/** Sniffed MIME type => [kind, allowed extensions, stored extension]. */
function media_types(): array
{
    return [
        'image/jpeg' => ['image', ['jpg', 'jpeg'], 'jpg'],
        'image/png'  => ['image', ['png'], 'png'],
        'image/webp' => ['image', ['webp'], 'webp'],
        'image/gif'  => ['image', ['gif'], 'png'],
        'application/pdf' => ['document', ['pdf'], 'pdf'],
        'application/msword' => ['document', ['doc'], 'doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['document', ['docx'], 'docx'],
        'application/vnd.ms-excel' => ['document', ['xls'], 'xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['document', ['xlsx'], 'xlsx'],
        'application/vnd.ms-powerpoint' => ['document', ['ppt'], 'ppt'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['document', ['pptx'], 'pptx'],
        'audio/mpeg' => ['audio', ['mp3'], 'mp3'],
        'audio/mp4'  => ['audio', ['m4a'], 'm4a'],
        'audio/x-m4a' => ['audio', ['m4a'], 'm4a'],
        'audio/ogg'  => ['audio', ['ogg', 'oga'], 'ogg'],
        'video/mp4'  => ['video', ['mp4', 'm4v'], 'mp4'],
        'video/webm' => ['video', ['webm'], 'webm'],
    ];
}

/** Size limit per kind, in bytes. */
function media_size_limit(string $kind): int
{
    return match ($kind) {
        'image' => 10 * 1024 * 1024,
        'document' => 20 * 1024 * 1024,
        'audio' => 100 * 1024 * 1024,
        'video' => 300 * 1024 * 1024,
        default => 0,
    };
}

/** Human list of accepted extensions (for help text and the accept attribute). */
function media_accept(?string $kind = null): string
{
    $exts = [];
    foreach (media_types() as [$k, $allowed]) {
        if ($kind === null || $k === $kind) {
            foreach ($allowed as $x) {
                $exts['.' . $x] = true;
            }
        }
    }
    return implode(',', array_keys($exts));
}

/**
 * Validate a file by its real content: sniffed MIME, extension allow-list
 * matching that MIME, and per-kind size limit.
 * @return array{kind:string, mime:string, ext:string}
 */
function media_validate(string $path, string $originalName, int $size, ?string $onlyKind = null): array
{
    if (!is_file($path) || $size <= 0) {
        throw new UploadException('The file is empty or missing.');
    }
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);

    // Office Open XML files are zips; confirm the package marker and trust the extension.
    if (in_array($mime, ['application/zip', 'application/octet-stream'], true) && in_array($ext, ['docx', 'xlsx', 'pptx'], true)) {
        $zip = new ZipArchive();
        if ($zip->open($path) === true && $zip->locateName('[Content_Types].xml') !== false) {
            $mime = [
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            ][$ext];
        }
        $zip->close();
    }
    // An .m4a audio file is often sniffed as video/mp4.
    if ($mime === 'video/mp4' && $ext === 'm4a') {
        $mime = 'audio/mp4';
    }

    $types = media_types();
    if (!isset($types[$mime])) {
        throw new UploadException('This type of file is not allowed.');
    }
    [$kind, $allowedExt, $storeExt] = $types[$mime];
    if (!in_array($ext, $allowedExt, true)) {
        throw new UploadException('The file extension does not match its content.');
    }
    if ($onlyKind !== null && $kind !== $onlyKind) {
        throw new UploadException('Please choose ' . ($onlyKind === 'image' ? 'an image' : 'a ' . $onlyKind) . ' file.');
    }
    if ($size > media_size_limit($kind)) {
        throw new UploadException(sprintf('The file is too large (limit %d MB).', media_size_limit($kind) / 1048576));
    }
    if ($kind === 'image') {
        $info = getimagesize($path);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw new UploadException('The image could not be read.');
        }
        if ($info[0] * $info[1] > MEDIA_MAX_PIXELS) {
            throw new UploadException('The image is too large (over 40 megapixels).');
        }
    }
    return ['kind' => $kind, 'mime' => $mime, 'ext' => $storeExt];
}

/** Normalise one entry of $_FILES (single upload) or throw. */
function media_upload_from_files(array $f): array
{
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if (is_array($err)) {
        throw new UploadException('Unexpected multiple upload.');
    }
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        throw new UploadException('The file is too large for the server.');
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($f['tmp_name'] ?? ''))) {
        throw new UploadException('The upload failed. Please try again.');
    }
    return ['tmp' => (string) $f['tmp_name'], 'name' => (string) $f['name'], 'size' => (int) $f['size']];
}

/** Turn a multiple-file input ($_FILES['x'] with arrays) into a list of single entries. */
function media_files_list(array $f): array
{
    if (!is_array($f['name'] ?? null)) {
        return ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$f];
    }
    $out = [];
    foreach (array_keys($f['name']) as $i) {
        if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $out[] = ['name' => $f['name'][$i], 'type' => $f['type'][$i] ?? '', 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}

/**
 * Store a validated file and create its media row.
 * $isUpload: true for PHP uploads (move_uploaded_file), false for local files (seeding, tests).
 * @param array{visibility?:string, folder?:string, alt?:string, title?:string, kind?:string, focal_x?:int, focal_y?:int} $opts
 */
function media_store(string $tmp, string $originalName, int $size, ?int $userId, bool $isUpload = true, array $opts = []): int
{
    $info = media_validate($tmp, $originalName, $size, $opts['kind'] ?? null);
    $visibility = in_array($opts['visibility'] ?? 'public', ['public', 'members', 'private'], true) ? ($opts['visibility'] ?? 'public') : 'public';
    $stored = media_write_files($tmp, $info, $isUpload, $visibility);
    $title = $opts['title'] ?? pathinfo($originalName, PATHINFO_FILENAME);
    return db_insert('media', [
        'kind' => $info['kind'],
        'path' => $stored['path'],
        'original_name' => mb_substr(basename($originalName), 0, 255),
        'mime' => $info['kind'] === 'image' ? $stored['mime'] : $info['mime'],
        'size' => $stored['size'],
        'width' => $stored['width'],
        'height' => $stored['height'],
        'title' => mb_substr((string) $title, 0, 200),
        'alt' => mb_substr((string) ($opts['alt'] ?? ''), 0, 300),
        'folder' => mb_substr(slugify((string) ($opts['folder'] ?? ''), 80), 0, 80),
        'visibility' => $visibility,
        'variants' => $stored['variants'] ? json_encode($stored['variants'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : null,
        'uploaded_by' => $userId,
    ] + (isset($opts['focal_x'], $opts['focal_y']) ? ['focal_x' => max(0, min(100, (int) $opts['focal_x'])), 'focal_y' => max(0, min(100, (int) $opts['focal_y']))] : []));
}

/**
 * Write the original (re-encoded for images) and variants.
 * @return array{path:string, mime:string, size:int, width:?int, height:?int, variants:array<int,string>}
 */
function media_write_files(string $tmp, array $info, bool $isUpload, string $visibility): array
{
    $sub = gmdate('Y') . '/' . gmdate('m');
    $name = bin2hex(random_bytes(16));
    $origDir = BASE . '/storage/originals/' . $sub;
    if (!is_dir($origDir) && !mkdir($origDir, 0775, true) && !is_dir($origDir)) {
        throw new RuntimeException('Cannot create media folder');
    }
    $rel = $sub . '/' . $name . '.' . $info['ext'];
    $dest = BASE . '/storage/originals/' . $rel;

    if ($info['kind'] !== 'image') {
        $ok = $isUpload ? move_uploaded_file($tmp, $dest) : copy($tmp, $dest);
        if (!$ok) {
            throw new RuntimeException('Could not save the file');
        }
        return ['path' => $rel, 'mime' => $info['mime'], 'size' => (int) filesize($dest), 'width' => null, 'height' => null, 'variants' => []];
    }

    // Images are decoded and re-encoded: this strips EXIF/GPS and any payload hidden in the file.
    $img = image_load($tmp, $info['mime']);
    $storeMime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$info['ext']];
    image_save($img, $dest, $storeMime, 90);
    if ($isUpload && is_file($tmp)) {
        unlink($tmp);
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $public = $visibility === 'public';
    $variantDir = $public ? BASE . '/public/uploads/' . $sub : BASE . '/storage/variants/' . $sub;
    if (!is_dir($variantDir) && !mkdir($variantDir, 0775, true) && !is_dir($variantDir)) {
        throw new RuntimeException('Cannot create variant folder');
    }
    $variants = [];
    foreach (media_variant_widths($w) as $vw) {
        $vh = max(1, (int) round($h * $vw / $w));
        $resized = image_resize($img, $vw, $vh);
        $file = $name . '-' . $vw . '.webp';
        image_save($resized, $variantDir . '/' . $file, 'image/webp', 80);
        unset($resized);
        $variants[$vw] = ($public ? 'uploads/' : '') . $sub . '/' . $file;
    }
    unset($img);
    return ['path' => $rel, 'mime' => $storeMime, 'size' => (int) filesize($dest), 'width' => $w, 'height' => $h, 'variants' => $variants];
}

/** Variant widths for an image of width $w: each standard width below it, plus the capped full size. */
function media_variant_widths(int $w): array
{
    $out = array_values(array_filter(MEDIA_VARIANT_WIDTHS, static fn (int $x): bool => $x < $w));
    $out[] = min($w, max(MEDIA_VARIANT_WIDTHS));
    return array_values(array_unique($out));
}

function image_load(string $path, string $mime): GdImage
{
    ini_set('memory_limit', '512M');
    $img = match ($mime) {
        'image/jpeg' => imagecreatefromjpeg($path),
        'image/png' => imagecreatefrompng($path),
        'image/webp' => imagecreatefromwebp($path),
        'image/gif' => imagecreatefromgif($path),
        default => false,
    };
    if (!$img instanceof GdImage) {
        throw new UploadException('The image could not be decoded.');
    }
    if (!imageistruecolor($img)) {
        imagepalettetotruecolor($img);
    }
    imagealphablending($img, false);
    imagesavealpha($img, true);
    if ($mime === 'image/jpeg') {
        $img = image_orient($img, exif_orientation($path));
    }
    return $img;
}

/** EXIF orientation (1 when missing). Broken EXIF blocks are common, so their warnings are ignored here only. */
function exif_orientation(string $path): int
{
    if (!function_exists('exif_read_data')) {
        return 1;
    }
    set_error_handler(static fn (): bool => true, E_WARNING | E_NOTICE);
    try {
        $exif = exif_read_data($path);
    } finally {
        restore_error_handler();
    }
    return is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
}

function image_orient(GdImage $img, int $orientation): GdImage
{
    $rotated = match ($orientation) {
        3 => imagerotate($img, 180, 0),
        6 => imagerotate($img, -90, 0),
        8 => imagerotate($img, 90, 0),
        default => null,
    };
    if ($rotated instanceof GdImage) {
        unset($img);
        return $rotated;
    }
    if ($orientation === 2) {
        imageflip($img, IMG_FLIP_HORIZONTAL);
    } elseif ($orientation === 4) {
        imageflip($img, IMG_FLIP_VERTICAL);
    }
    return $img;
}

function image_resize(GdImage $src, int $w, int $h): GdImage
{
    $dst = imagecreatetruecolor($w, $h);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));
    return $dst;
}

function image_save(GdImage $img, string $dest, string $mime, int $quality): void
{
    $ok = match ($mime) {
        'image/jpeg' => imagejpeg($img, $dest, $quality),
        'image/png' => imagepng($img, $dest, 6),
        'image/webp' => imagewebp($img, $dest, $quality),
        default => false,
    };
    if (!$ok) {
        throw new RuntimeException('Could not write image');
    }
}

/** @return array<string,mixed>|null media row with variants decoded */
function media_find(?int $id): ?array
{
    if ($id === null || $id <= 0) {
        return null;
    }
    $cache = &media_cache();
    if (!array_key_exists($id, $cache)) {
        $cache[$id] = media_decode(q_one('SELECT * FROM media WHERE id = ?', [$id]));
    }
    return $cache[$id];
}

/** Load many media rows in one query (avoids N+1 on list pages). */
function media_preload(array $ids): void
{
    $cache = &media_cache();
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0 && !array_key_exists($i, $cache))));
    if ($ids === []) {
        return;
    }
    foreach ($ids as $i) {
        $cache[$i] = null;
    }
    foreach (q_all('SELECT * FROM media WHERE id IN (' . db_placeholders(count($ids)) . ')', $ids) as $row) {
        $cache[(int) $row['id']] = media_decode($row);
    }
}

function &media_cache(): array
{
    static $cache = [];
    return $cache;
}

function media_forget(int $id): void
{
    $cache = &media_cache();
    unset($cache[$id]);
}

function media_decode(?array $row): ?array
{
    if ($row === null) {
        return null;
    }
    $v = $row['variants'] ? json_decode((string) $row['variants'], true) : [];
    $row['variants'] = [];
    foreach (is_array($v) ? $v : [] as $w => $p) {
        $row['variants'][(int) $w] = (string) $p;
    }
    ksort($row['variants']);
    return $row;
}

/** URL of the original file (served by PHP with an access check). */
function media_original_url(array $m): string
{
    $name = slugify(pathinfo((string) $m['original_name'], PATHINFO_FILENAME), 60) ?: 'file';
    $ext = strtolower(pathinfo((string) $m['path'], PATHINFO_EXTENSION));
    return '/media/' . (int) $m['id'] . '/' . $name . '.' . $ext;
}

/** URL of the smallest variant at least $width wide (or the largest). */
function media_url(?array $m, int $width = 800): string
{
    if ($m === null) {
        return '';
    }
    if ($m['kind'] !== 'image' || $m['variants'] === []) {
        return media_original_url($m);
    }
    $chosen = array_key_last($m['variants']);
    foreach (array_keys($m['variants']) as $w) {
        if ($w >= $width) {
            $chosen = $w;
            break;
        }
    }
    return media_variant_url($m, $chosen);
}

function media_variant_url(array $m, int $w): string
{
    if ($m['visibility'] === 'public') {
        return '/' . $m['variants'][$w];
    }
    return '/media/' . (int) $m['id'] . '/w/' . $w;
}

/**
 * Classes that move a cropped image's object-position to its focal point
 * (strict CSP: no style attributes, so 5 % steps map to fx-NN / fy-NN classes).
 */
function media_focal_class(?array $m): string
{
    if ($m === null) {
        return '';
    }
    $x = (int) (round(max(0, min(100, (int) ($m['focal_x'] ?? 50))) / 5) * 5);
    $y = (int) (round(max(0, min(100, (int) ($m['focal_y'] ?? 50))) / 5) * 5);
    return $x === 50 && $y === 50 ? '' : 'fx-' . $x . ' fy-' . $y;
}

/** Responsive, lazy <img>. Returns '' when there is no image. */
function img_html(?array $m, string $sizes = '100vw', ?string $alt = null, string $class = '', bool $lazy = true, int $width = 800): string
{
    if ($m === null || $m['kind'] !== 'image' || ($m['visibility'] !== 'public' && !media_can_view($m, current_user()))) {
        return '';
    }
    $srcset = [];
    foreach (array_keys($m['variants']) as $w) {
        $srcset[] = media_variant_url($m, $w) . ' ' . $w . 'w';
    }
    $w = (int) $m['width'];
    $h = (int) $m['height'];
    $dispW = min($width, $w ?: $width);
    $dispH = $w > 0 ? (int) round($h * $dispW / $w) : $dispW;
    $class = trim($class . ' ' . media_focal_class($m));
    return sprintf(
        '<img src="%s" srcset="%s" sizes="%s" width="%d" height="%d" alt="%s"%s%s decoding="async">',
        e(media_url($m, $width)),
        e(implode(', ', $srcset)),
        e($sizes),
        $dispW,
        $dispH,
        e($alt ?? (string) $m['alt']),
        $class !== '' ? ' class="' . e($class) . '"' : '',
        $lazy ? ' loading="lazy"' : ' fetchpriority="high"'
    );
}

/**
 * Who may open a file. Member photos (private, folder member-photos) are for
 * their owner and staff with the members permission; other private files for
 * their uploader and staff.
 */
function media_can_view(array $m, ?array $user): bool
{
    return match ($m['visibility']) {
        'public' => true,
        'members' => viewer_is_member($user),
        default => ($user !== null && (int) $m['uploaded_by'] === (int) $user['id'] && $user['status'] === 'active')
            || ((string) $m['folder'] === 'member-photos' ? user_can($user, 'members') : is_staff($user)),
    };
}

/** May this file be attached to content, sections or emails? Never member photos or other private files. */
function media_attachable(?array $m): bool
{
    return $m !== null && $m['visibility'] !== 'private' && (string) $m['folder'] !== 'member-photos';
}

/** Media ids under "media_id" / "*_media_id" keys anywhere in builder sections or campaign blocks JSON. */
function media_ids_in_json(?string $json): array
{
    $data = json_decode((string) $json, true);
    $ids = [];
    $walk = static function (mixed $node) use (&$walk, &$ids): void {
        if (!is_array($node)) {
            return;
        }
        foreach ($node as $k => $v) {
            if (is_string($k) && preg_match('/(^|_)media_id$/', $k) && (is_int($v) || (is_string($v) && ctype_digit($v))) && (int) $v > 0) {
                $ids[(int) $v] = true;
            } elseif (is_array($v)) {
                $walk($v);
            }
        }
    };
    $walk($data);
    return array_keys($ids);
}

/**
 * Every place a media item is used. 'needs' is the visibility that place
 * requires: 'public' (shown to everyone, or the builder/emails need a public
 * file), 'members' (members-only item or card artwork), or 'pin' (embedded
 * in rich text by file URL: moving the file would break the link, so its
 * visibility is left alone).
 * @return list<array{label:string,title:string,url:string,needs:string}>
 */
function media_references(int $id): array
{
    $m = media_find($id);
    $out = [];
    $add = static function (string $label, string $title, string $url, string $needs) use (&$out): void {
        $out[] = ['label' => $label, 'title' => $title, 'url' => $url, 'needs' => $needs];
    };
    $vis = static fn (array $r): string => ($r['vis'] ?? 'public') === 'public' ? 'public' : 'members';
    // [table, column, title column, label, admin url, has a visibility column]
    $refs = [
        ['posts', 'cover_media_id', 'title', 'Post', '/admin/posts/%d/edit', true],
        ['events', 'cover_media_id', 'title', 'Event', '/admin/events/%d/edit', true],
        ['albums', 'cover_media_id', 'title', 'Album cover', '/admin/albums/%d/edit', true],
        ['downloads', 'cover_media_id', 'title', 'Download cover', '/admin/downloads/%d/edit', true],
        ['downloads', 'file_media_id', 'title', 'Download file', '/admin/downloads/%d/edit', true],
        ['pages', 'cover_media_id', 'title', 'Page', '/admin/pages/%d/edit', true],
        ['leaders', 'photo_media_id', 'name', 'Leader', '/admin/leaders/%d/edit', false],
        ['awards', 'photo_media_id', 'recipient', 'Award', '/admin/awards/%d/edit', false],
        ['groups_orgs', 'logo_media_id', 'name', 'Group', '/admin/groups/%d/edit', false],
        ['members', 'photo_media_id', 'first_name', 'Member photo', '/admin', false],
        ['radio_media', 'media_id', 'title', 'Radio programme', '/admin/radio/media/%d', false],
        ['radio_media', 'cover_media_id', 'title', 'Radio cover', '/admin/radio/media/%d', false],
    ];
    foreach ($refs as [$table, $col, $titleCol, $label, $url, $hasVis]) {
        $sql = 'SELECT id, ' . db_ident($titleCol) . ' AS t' . ($hasVis ? ', visibility AS vis' : '') . ' FROM ' . db_ident($table) . ' WHERE ' . db_ident($col) . ' = ?';
        foreach (q_all($sql, [$id]) as $r) {
            $add($label, (string) $r['t'], sprintf($url, $r['id']), $hasVis ? $vis($r) : 'public');
        }
    }
    foreach (q_all('SELECT a.id, a.title, a.visibility AS vis FROM album_photos p JOIN albums a ON a.id = p.album_id WHERE p.media_id = ? GROUP BY a.id, a.title, a.visibility', [$id]) as $r) {
        $add('Album photo', (string) $r['title'], '/admin/albums/' . $r['id'] . '/photos', $vis($r));
    }
    if ((int) setting('site.logo_media_id', 0) === $id) {
        $add('Site logo', 'Settings', '/admin/settings', 'public');
    }
    if ((int) setting('site.favicon_media_id', 0) === $id) {
        $add('Site icon (favicon)', 'Settings', '/admin/settings', 'public');
    }
    foreach (['card.background_media_id' => 'Member card background', 'card.signature_media_id' => 'Member card signature'] as $key => $label) {
        if ((int) setting($key, 0) === $id) {
            $add($label, 'Membership settings', '/admin/settings/membership', 'members');
        }
    }
    // Builder sections (live pages, revisions, saved templates) and campaign blocks: these need public images.
    $like = '%' . $id . '%';
    foreach (q_all("SELECT id, title, sections FROM pages WHERE sections LIKE ?", [$like]) as $r) {
        if (in_array($id, media_ids_in_json($r['sections']), true)) {
            $add('Page section', (string) $r['title'], '/admin/pages/' . $r['id'] . '/builder', 'public');
        }
    }
    foreach (q_all("SELECT r.page_id, p.title, r.sections FROM page_revisions r JOIN pages p ON p.id = r.page_id WHERE r.sections LIKE ?", [$like]) as $r) {
        if (in_array($id, media_ids_in_json($r['sections']), true)) {
            $add('Page revision', (string) $r['title'], '/admin/pages/' . $r['page_id'] . '/builder', 'public');
        }
    }
    foreach (q_all("SELECT id, name, sections FROM section_templates WHERE sections LIKE ?", [$like]) as $r) {
        if (in_array($id, media_ids_in_json($r['sections']), true)) {
            $add('Saved template', (string) $r['name'], '/admin/templates', 'public');
        }
    }
    foreach (q_all("SELECT id, subject, blocks FROM campaigns WHERE blocks LIKE ?", [$like]) as $r) {
        if (in_array($id, media_ids_in_json($r['blocks']), true)) {
            $add('Campaign', (string) $r['subject'], '/admin/campaigns/' . $r['id'], 'public');
        }
    }
    // Images embedded in rich text are referenced by their variant file name.
    if ($m !== null && $m['variants'] !== []) {
        $needle = '%' . pathinfo((string) $m['path'], PATHINFO_FILENAME) . '%';
        foreach (['posts' => '/admin/posts/%d/edit', 'events' => '/admin/events/%d/edit', 'pages' => '/admin/pages/%d/edit'] as $table => $url) {
            foreach (q_all('SELECT id, title FROM ' . db_ident($table) . ' WHERE body LIKE ?', [$needle]) as $r) {
                $add('Embedded in', (string) $r['title'], sprintf($url, $r['id']), 'pin');
            }
        }
    }
    return $out;
}

/**
 * Where a media item is used. Each entry: [label, title, admin url].
 * @return list<array{0:string,1:string,2:string}>
 */
function media_usage(int $id): array
{
    return array_map(static fn (array $r): array => [$r['label'], $r['title'], $r['url']], media_references($id));
}

/**
 * The visibility a media item should have for where it is used, or null to
 * leave it as it is (unused, private, or pinned by an embedded link).
 */
function media_required_visibility(int $id): ?string
{
    $m = media_find($id);
    if (!media_attachable($m)) {
        return null;
    }
    $needs = array_column(media_references($id), 'needs');
    if ($needs === [] || in_array('pin', $needs, true)) {
        return null;
    }
    return in_array('public', $needs, true) ? 'public' : 'members';
}

/**
 * Move a media item between public and members-only: its WebP variants move
 * between public/uploads (served by Apache) and storage/variants (served by
 * PHP after an access check). Originals always stay in storage/originals.
 * Returns true when something changed.
 */
function media_set_visibility(int $id, string $visibility): bool
{
    $m = media_find($id);
    if (!media_attachable($m) || !in_array($visibility, ['public', 'members'], true) || $m['visibility'] === $visibility) {
        return false;
    }
    $variants = [];
    foreach ($m['variants'] as $w => $p) {
        $rel = $m['visibility'] === 'public' ? (string) preg_replace('#^uploads/#', '', $p) : $p;
        $from = $m['visibility'] === 'public' ? BASE . '/public/' . $p : BASE . '/storage/variants/' . $p;
        $to = ($visibility === 'public' ? BASE . '/public/uploads/' : BASE . '/storage/variants/') . $rel;
        if (str_contains($rel, '..')) {
            throw new RuntimeException('Bad variant path');
        }
        if (is_file($from)) {
            $dir = dirname($to);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create variant folder');
            }
            if (!rename($from, $to)) {
                throw new RuntimeException('Could not move ' . basename($from));
            }
        }
        $variants[$w] = ($visibility === 'public' ? 'uploads/' : '') . $rel;
    }
    db_update('media', $id, [
        'visibility' => $visibility,
        'variants' => $variants ? json_encode($variants, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : null,
    ]);
    media_forget($id);
    return true;
}

/** Give media the visibility their uses require (members-only items keep their files members-only). */
function media_sync_visibility(array $ids): int
{
    $n = 0;
    foreach (array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)) as $id) {
        media_forget($id);
        $want = media_required_visibility($id);
        if ($want !== null && media_set_visibility($id, $want)) {
            $n++;
        }
    }
    return $n;
}

/** Sync every attachable media item (bin/migrate after an upgrade). */
function media_sync_all(): int
{
    return media_sync_visibility(array_map('intval', array_column(q_all("SELECT id FROM media WHERE visibility IN ('public','members')"), 'id')));
}

/** Delete the files of a media row (original and variants). */
function media_delete_files(array $m): void
{
    $files = [BASE . '/storage/originals/' . $m['path']];
    foreach ($m['variants'] as $p) {
        $files[] = $m['visibility'] === 'public' ? BASE . '/public/' . $p : BASE . '/storage/variants/' . $p;
    }
    foreach ($files as $f) {
        if (is_file($f) && str_starts_with((string) realpath($f), (string) realpath(BASE))) {
            unlink($f);
        }
    }
}

/** Delete a media item; refused while it is in use. */
function media_delete(int $id): void
{
    $m = media_find($id);
    if ($m === null) {
        return;
    }
    if (media_usage($id) !== []) {
        throw new UploadException('This file is in use and cannot be deleted.');
    }
    q('DELETE FROM media WHERE id = ?', [$id]);
    media_delete_files($m);
    media_forget($id);
}

/** Replace a media item's file with a new one of the same kind, keeping its id. */
function media_replace(int $id, string $tmp, string $originalName, int $size, bool $isUpload = true): void
{
    $m = media_find($id);
    if ($m === null) {
        throw new UploadException('Media item not found.');
    }
    if (q_val('SELECT 1 FROM radio_media WHERE media_id = ? LIMIT 1', [$id]) !== null) {
        // Its measured duration drives the radio schedule: upload a new programme instead.
        throw new UploadException('This file is a radio programme. Upload a new programme in the Radio library instead of replacing it.');
    }
    $info = media_validate($tmp, $originalName, $size, (string) $m['kind']);
    $stored = media_write_files($tmp, $info, $isUpload, (string) $m['visibility']);
    db_update('media', $id, [
        'path' => $stored['path'],
        'original_name' => mb_substr(basename($originalName), 0, 255),
        'mime' => $info['kind'] === 'image' ? $stored['mime'] : $info['mime'],
        'size' => $stored['size'],
        'width' => $stored['width'],
        'height' => $stored['height'],
        'variants' => $stored['variants'] ? json_encode($stored['variants'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : null,
    ]);
    media_delete_files($m);
    media_forget($id);
}

/** Absolute path of a stored file for serving; null when outside storage. */
function media_file_path(array $m, ?int $variantWidth = null): ?string
{
    if ($variantWidth !== null) {
        if (!isset($m['variants'][$variantWidth])) {
            return null;
        }
        $root = $m['visibility'] === 'public' ? BASE . '/public' : BASE . '/storage/variants';
        $file = $root . '/' . $m['variants'][$variantWidth];
    } else {
        $root = BASE . '/storage/originals';
        $file = $root . '/' . $m['path'];
    }
    $real = realpath($file);
    $rootReal = realpath($root);
    return $real !== false && $rootReal !== false && str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR) ? $real : null;
}

/** Send a file with optional single byte-range support (for seeking audio/video). */
function send_file(string $path, string $mime, string $downloadName, bool $inline, bool $public): never
{
    $size = (int) filesize($path);
    $start = 0;
    $end = $size - 1;
    $status = 200;
    $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
    if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') {
            $start = max(0, $size - (int) $m[2]);
        } else {
            $start = (int) $m[1];
            $end = $m[2] !== '' ? min((int) $m[2], $size - 1) : $size - 1;
        }
        if ($start > $end || $start >= $size) {
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }
        $status = 206;
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    http_response_code($status);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . ($end - $start + 1));
    header('Accept-Ranges: bytes');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Cache-Control: ' . ($public ? 'public, max-age=86400' : 'private, max-age=600'));
    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName) ?: 'file';
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safeName . '"');
    if (request_method() === 'HEAD') {
        exit;
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        exit;
    }
    fseek($fh, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fh)) {
        $chunk = fread($fh, (int) min(65536, $left));
        if ($chunk === false) {
            break;
        }
        echo $chunk;
        $left -= strlen($chunk);
        flush();
    }
    fclose($fh);
    exit;
}
