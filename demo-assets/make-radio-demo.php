<?php
declare(strict_types=1);

/*
 * Development tool (not used at runtime): builds the original demo radio
 * media in demo-assets/radio/. Music is synthesised here (pentatonic
 * melodies over soft pads), the talk segment is read by the macOS speech
 * synthesiser from text written for this project, and the video is a
 * generated animation. Needs macOS (afconvert, say) and an ffmpeg with
 * libvpx (e.g. Playwright's bundled one): FFMPEG=/path/to/ffmpeg php demo-assets/make-radio-demo.php
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$out = __DIR__ . '/radio';
$tmp = sys_get_temp_dir() . '/gather-radio-' . bin2hex(random_bytes(3));
mkdir($tmp);
$rate = 22050;

function wav(string $file, array $samples, int $rate): void
{
    $data = '';
    foreach ($samples as $s) {
        $data .= pack('v', (int) max(-32767, min(32767, round($s * 32767))) & 0xffff);
    }
    file_put_contents($file, 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16) . 'data' . pack('V', strlen($data)) . $data);
}

/** A gentle tune: melody notes over a pad, with a fade in/out. */
function tune(int $seconds, float $bpm, array $scale, int $seed, int $rate): array
{
    mt_srand($seed);
    $n = $seconds * $rate;
    $out = array_fill(0, $n, 0.0);
    $beat = 60 / $bpm;
    $chords = [[0, 2, 4], [3, 5, 0], [4, 1, 3], [2, 4, 6]];
    $t = 0.0;
    $step = 0;
    while ($t < $seconds) {
        $len = $beat * [0.5, 1, 1, 1.5, 2][mt_rand(0, 4)];
        $deg = mt_rand(0, count($scale) - 1);
        $f = 220 * 2 ** ($scale[$deg] / 12) * (mt_rand(0, 3) === 0 ? 2 : 1);
        $start = (int) ($t * $rate);
        $end = min($n, (int) (($t + $len) * $rate));
        for ($i = $start; $i < $end; $i++) {
            $x = ($i - $start) / $rate;
            $env = min(1, $x * 30) * exp(-3.2 * $x / $len);
            $out[$i] += 0.22 * $env * (sin(2 * M_PI * $f * $x) + 0.3 * sin(4 * M_PI * $f * $x));
        }
        $t += $len;
        $step++;
    }
    $bar = $beat * 4;
    for ($i = 0; $i < $n; $i++) {
        $x = $i / $rate;
        $c = $chords[(int) floor($x / $bar) % 4];
        $pad = 0.0;
        foreach ($c as $d) {
            $pad += sin(2 * M_PI * (110 * 2 ** ($scale[$d % count($scale)] / 12)) * $x);
        }
        $fade = min(1, $x / 2, ($seconds - $x) / 3);
        $out[$i] = ($out[$i] + 0.06 * $pad) * max(0, $fade);
    }
    return $out;
}

$major = [0, 2, 4, 7, 9, 12, 14];
$minor = [0, 3, 5, 7, 10, 12, 15];
$tracks = [
    'harbour-morning' => [62, 96, $major, 11],
    'evening-calm' => [74, 70, $minor, 23],
    'market-day' => [48, 118, $major, 37],
    'station-ident' => [9, 120, $major, 5],
];
foreach ($tracks as $name => [$sec, $bpm, $scale, $seed]) {
    wav("$tmp/$name.wav", tune($sec, $bpm, $scale, $seed, $rate), $rate);
    passthru(sprintf('afconvert -f m4af -d aac -b 48000 %s %s', escapeshellarg("$tmp/$name.wav"), escapeshellarg("$out/$name.m4a")));
}

$talk = 'Good morning, and welcome to Harbourside Community Radio. Here are this week\'s notices. '
    . 'The community garden needs volunteers on Saturday from ten o\'clock. Bring gloves, and we will bring the tea. '
    . 'Our homework club is back on Tuesday and Thursday afternoons in the library room. '
    . 'And the summer festival planning meeting is on Wednesday evening. Everyone is welcome. '
    . 'That is all for now. Stay tuned for more music.';
file_put_contents("$tmp/talk.txt", $talk);
passthru(sprintf('say -r 175 -f %s -o %s', escapeshellarg("$tmp/talk.txt"), escapeshellarg("$tmp/talk.aiff")));
passthru(sprintf('afconvert -f m4af -d aac -b 40000 %s %s', escapeshellarg("$tmp/talk.aiff"), escapeshellarg("$out/community-notices.m4a")));

$ffmpeg = getenv('FFMPEG') ?: 'ffmpeg';
$frames = '';
$w = 640;
$h = 360;
for ($i = 0; $i < 15 * 24; $i++) {
    $img = imagecreatetruecolor($w, $h);
    $p = $i / 360;
    for ($y = 0; $y < $h; $y += 4) {
        $r = (int) (18 + 30 * sin($p * 6.28 + $y / 90));
        $g = (int) (60 + 40 * sin($p * 6.28 + $y / 70 + 1));
        $b = (int) (110 + 50 * sin($p * 6.28 + $y / 120 + 2));
        imagefilledrectangle($img, 0, $y, $w, $y + 3, imagecolorallocate($img, max(0, $r), max(0, $g), max(0, $b)));
    }
    for ($k = 0; $k < 24; $k++) {
        $bh = (int) (20 + 90 * abs(sin($i / 6 + $k * 0.7)));
        imagefilledrectangle($img, 80 + $k * 20, 300 - $bh, 92 + $k * 20, 300, imagecolorallocatealpha($img, 242, 178, 70, 20));
    }
    imagestring($img, 5, 80, 60, 'Harbourside Radio - studio session', imagecolorallocate($img, 255, 255, 255));
    ob_start();
    imagejpeg($img, null, 80);
    $frames .= ob_get_clean();
}
$cmd = sprintf('%s -y -f image2pipe -framerate 24 -c:v mjpeg -i pipe:0 -c:v libvpx -b:v 350k -an %s', escapeshellarg($ffmpeg), escapeshellarg("$out/studio-session.webm"));
$pipe = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
fwrite($pipes[0], $frames);
fclose($pipes[0]);
proc_close($pipe);
array_map('unlink', glob("$tmp/*") ?: []);
rmdir($tmp);
foreach (glob("$out/*") ?: [] as $f) {
    printf("%-28s %7.1f KB\n", basename($f), filesize($f) / 1024);
}
