<?php
declare(strict_types=1);

use Core\UploadException;

test('media_validate accepts a real JPEG and a PDF', function (): void {
    $j = fixture_jpeg(50, 40);
    same(['kind' => 'image', 'mime' => 'image/jpeg', 'ext' => 'jpg'], media_validate($j, 'Holiday.JPG', (int) filesize($j)));
    $p = fixture_file("%PDF-1.4\n%%EOF\n", 'pdf');
    same('document', media_validate($p, 'minutes.pdf', (int) filesize($p))['kind']);
});

test('upload of a PHP file is rejected, even renamed to .jpg (acceptance 12)', function (): void {
    $php = fixture_file("<?php system(\$_GET['c']); ?>", 'php');
    same('This type of file is not allowed.', throws(UploadException::class, fn () => media_validate($php, 'shell.php', (int) filesize($php)))->getMessage());
    throws(UploadException::class, fn () => media_validate($php, 'shell.jpg', (int) filesize($php)));
    $html = fixture_file('<html><script>alert(1)</script></html>', 'html');
    throws(UploadException::class, fn () => media_validate($html, 'page.html', (int) filesize($html)));
});

test('extension must match the sniffed content', function (): void {
    $j = fixture_jpeg(20, 20);
    same('The file extension does not match its content.', throws(UploadException::class, fn () => media_validate($j, 'photo.pdf', (int) filesize($j)))->getMessage());
});

test('size limits apply per kind and kind restrictions are enforced', function (): void {
    $j = fixture_jpeg(20, 20);
    throws(UploadException::class, fn () => media_validate($j, 'a.jpg', 11 * 1024 * 1024));
    $p = fixture_file("%PDF-1.4\n%%EOF\n", 'pdf');
    same('Please choose an image file.', throws(UploadException::class, fn () => media_validate($p, 'a.pdf', 100, 'image'))->getMessage());
    throws(UploadException::class, fn () => media_validate($j, 'a.jpg', 0));
});

test('images are re-encoded (EXIF/GPS stripped) with WebP variants 400/800/1600', function (): void {
    $marker = 'GPS-SECRET-LOCATION-51.5N';
    $j = fixture_jpeg(2000, 1000, "Exif\0\0" . $marker);
    ok(str_contains((string) file_get_contents($j), $marker), 'fixture carries the marker');
    $id = media_store($j, 'big.jpg', (int) filesize($j), null, false);
    $m = media_find($id);
    same([400, 800, 1600], array_keys($m['variants']));
    same(2000, (int) $m['width']);
    $orig = BASE . '/storage/originals/' . $m['path'];
    ok(is_file($orig), 'original stored outside public/');
    not_contains($marker, (string) file_get_contents($orig), 'metadata stripped');
    foreach ($m['variants'] as $w => $rel) {
        $file = BASE . '/public/' . $rel;
        ok(is_file($file), "variant $w exists in public/uploads");
        same('image/webp', (new finfo(FILEINFO_MIME_TYPE))->file($file));
        same($w, getimagesize($file)[0]);
    }
    contains('srcset="/uploads/', img_html($m));
    contains('loading="lazy"', img_html($m));
});

test('small images get one variant at their own width', function (): void {
    same([300], media_variant_widths(300));
    same([400, 800, 1000], media_variant_widths(1000));
    same([400, 800, 1600], media_variant_widths(4000));
});

test('members-only images keep variants out of public/ and are served by PHP', function (): void {
    $id = fixture_image_media(null, 'members');
    $m = media_find($id);
    foreach ($m['variants'] as $w => $rel) {
        ok(!str_starts_with($rel, 'uploads/'), 'not under public/uploads');
        ok(is_file(BASE . '/storage/variants/' . $rel));
        same('/media/' . $id . '/w/' . $w, media_variant_url($m, $w));
    }
    ok(!media_can_view($m, null), 'visitor cannot view');
    ok(media_can_view($m, ['id' => 1, 'role' => 'member', 'status' => 'active']), 'member can view');
    ok(!media_can_view($m, ['id' => 1, 'role' => 'member', 'status' => 'unverified']), 'unverified member cannot');
});

test('media_file_path refuses paths outside storage', function (): void {
    $id = fixture_pdf_media();
    $m = media_find($id);
    ok(media_file_path($m) !== null);
    $m['path'] = '../../config.php';
    same(null, media_file_path($m));
});

test('media in use cannot be deleted; unused media can', function (): void {
    $used = fixture_image_media();
    $cat = (string) q_val("SELECT id FROM categories WHERE slug = 'news'");
    fixture_content('posts', ['title' => 'Uses media', 'category_id' => $cat, 'status' => 'draft', 'cover_media_id' => (string) $used]);
    $usage = media_usage($used);
    same('Post', $usage[0][0]);
    throws(UploadException::class, fn () => media_delete($used));
    $free = fixture_image_media();
    $m = media_find($free);
    media_delete($free);
    same(null, q_val('SELECT id FROM media WHERE id = ?', [$free]));
    ok(!is_file(BASE . '/storage/originals/' . $m['path']), 'file removed');
});

test('media_replace keeps the id and swaps the file', function (): void {
    $id = fixture_image_media();
    $before = media_find($id);
    $j = fixture_jpeg(500, 500);
    media_replace($id, $j, 'new.jpg', (int) filesize($j), false);
    $after = media_find($id);
    same(500, (int) $after['width']);
    ok($before['path'] !== $after['path']);
    ok(!is_file(BASE . '/storage/originals/' . $before['path']), 'old file removed');
    $p = fixture_file("%PDF-1.4\n%%EOF\n", 'pdf');
    throws(UploadException::class, fn () => media_replace($id, $p, 'x.pdf', (int) filesize($p), false));
});

test('media_files_list flattens multi-file uploads and skips empty slots', function (): void {
    $list = media_files_list(['name' => ['a.jpg', ''], 'type' => ['', ''], 'tmp_name' => ['/tmp/a', ''], 'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE], 'size' => [10, 0]]);
    same(1, count($list));
    same('a.jpg', $list[0]['name']);
    throws(UploadException::class, fn () => media_upload_from_files(['error' => UPLOAD_ERR_OK, 'tmp_name' => '/etc/passwd', 'name' => 'x', 'size' => 1]));
});

test('a broken EXIF block does not raise warnings or fail the upload', function (): void {
    $j = fixture_jpeg(100, 100, "Exif\0\0garbage");
    $warned = false;
    set_error_handler(static function () use (&$warned): bool { $warned = true; return true; });
    try {
        same(1, exif_orientation($j));
        $id = media_store($j, 'broken.jpg', (int) filesize($j), null, false);
    } finally {
        restore_error_handler();
    }
    ok(!$warned, 'no warning escaped');
    ok($id > 0);
});
