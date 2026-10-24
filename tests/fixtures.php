<?php
declare(strict_types=1);

// Shared helpers for tests (required by run.php before the *_test.php files).

/** A real JPEG file of the given size; optional raw bytes injected as an APP1 (EXIF-like) segment. */
function fixture_jpeg(int $w = 1200, int $h = 800, string $app1 = ''): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 30, 90, 140));
    imagefilledellipse($img, (int) ($w / 2), (int) ($h / 2), (int) ($w / 2), (int) ($h / 2), imagecolorallocate($img, 240, 180, 70));
    $file = tempnam(sys_get_temp_dir(), 'gtest') . '.jpg';
    imagejpeg($img, $file, 85);
    if ($app1 !== '') {
        $bytes = (string) file_get_contents($file);
        $segment = "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1;
        file_put_contents($file, substr($bytes, 0, 2) . $segment . substr($bytes, 2));
    }
    return $file;
}

function fixture_file(string $contents, string $ext): string
{
    $file = tempnam(sys_get_temp_dir(), 'gtest') . '.' . $ext;
    file_put_contents($file, $contents);
    return $file;
}

function fixture_admin(string $role = 'super_admin', array $perms = []): int
{
    static $n = 0;
    $n++;
    return create_user("staff{$n}-" . bin2hex(random_bytes(3)) . '@example.test', 'correct horse battery', $role, ucfirst($role) . " $n", 'active', $perms);
}

function fixture_member(string $status = 'active'): int
{
    return create_user('member-' . bin2hex(random_bytes(4)) . '@example.test', 'correct horse battery', 'member', 'Test Member', $status);
}

/** Save content through the model; returns the new id. */
function fixture_content(string $typeKey, array $input, ?int $userId = null): int
{
    $type = content_type($typeKey);
    [$data, $errors] = content_validate($type, $input);
    if ($errors) {
        throw new RuntimeException("fixture $typeKey invalid: " . json_encode($errors));
    }
    return content_save($type, $data, $input, null, $userId);
}

function fixture_image_media(?int $userId = null, string $visibility = 'public'): int
{
    $f = fixture_jpeg(900, 600);
    return media_store($f, 'photo.jpg', (int) filesize($f), $userId, false, ['visibility' => $visibility]);
}

function fixture_pdf_media(?int $userId = null, string $visibility = 'public'): int
{
    $f = fixture_file("%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n", 'pdf');
    return media_store($f, 'doc.pdf', (int) filesize($f), $userId, false, ['visibility' => $visibility]);
}

/** Local datetime-local string N days from now in the site timezone. */
function local_in(string $modify, string $time = '10:00'): string
{
    return (new DateTimeImmutable('now', site_tz()))->modify($modify)->format('Y-m-d') . 'T' . $time;
}

// A5 per-domain throttling is off by default in tests (most test addresses share example.test).
setting_set('mail.bounce', ['per_domain_minute' => 0]);
