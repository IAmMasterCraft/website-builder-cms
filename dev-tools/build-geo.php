<?php
declare(strict_types=1);

/*
 * Builds the bundled location data (Addendum C4) from the GeoNames dumps
 * (https://www.geonames.org, CC BY 4.0; credited in CREDITS.md):
 *
 *   public/assets/geo/countries.json  every country (ISO code, English name, phone code,
 *                                      has-divisions flag) + time zone -> country table
 *   public/assets/geo/<cc>.json       first-level divisions and their second-level
 *                                      areas for one country (lazy-loaded by location.js)
 *   src/geo/index.php                 PHP index used for validation (countries, versions)
 *
 * Usage (dev only, never deployed):
 *   php dev-tools/build-geo.php [--download] [--src=folder]
 *
 * The raw dumps (countryInfo.txt, admin1CodesASCII.txt, admin2Codes.txt,
 * timeZones.txt) are downloaded into dev-tools/geo-src/ (git-ignored) when
 * missing or with --download, and only ever read as data. Corrections (for
 * example Nigeria's duplicate LGAs) live in dev-tools/geo-corrections.json;
 * the build stops when a country's expected counts do not match.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

const GEO_BASE_URL = 'https://download.geonames.org/export/dump/';
const GEO_FILES = ['countryInfo.txt', 'admin1CodesASCII.txt', 'admin2Codes.txt', 'timeZones.txt'];
/** Codes in countryInfo.txt that no longer exist. */
const GEO_OBSOLETE = ['AN', 'CS'];
/** Older time zone names some browsers still report (IANA backward links). */
const GEO_TZ_ALIASES = [
    'Asia/Calcutta' => 'IN', 'Asia/Saigon' => 'VN', 'Asia/Katmandu' => 'NP', 'Asia/Rangoon' => 'MM', 'Europe/Kiev' => 'UA',
    'America/Buenos_Aires' => 'AR', 'America/Indianapolis' => 'US', 'America/Louisville' => 'US', 'Asia/Dacca' => 'BD',
    'Asia/Ulan_Bator' => 'MN', 'Atlantic/Faeroe' => 'FO', 'Pacific/Truk' => 'FM', 'Pacific/Ponape' => 'FM',
    'Africa/Asmera' => 'ER', 'Africa/Timbuktu' => 'ML', 'America/Godthab' => 'GL', 'Asia/Thimbu' => 'BT',
    'Europe/Belfast' => 'GB', 'GB' => 'GB', 'Asia/Chongqing' => 'CN', 'Asia/Harbin' => 'CN', 'Asia/Kashgar' => 'CN',
    'America/Montreal' => 'CA', 'Australia/ACT' => 'AU', 'Australia/NSW' => 'AU', 'US/Eastern' => 'US', 'US/Central' => 'US',
    'US/Mountain' => 'US', 'US/Pacific' => 'US', 'Canada/Eastern' => 'CA', 'Europe/Uzhgorod' => 'UA', 'Europe/Zaporozhye' => 'UA',
];
/** Built-in level labels where the local terms are well known; everything else uses Region / Area. */
const GEO_LABELS = [
    'NG' => ['State', 'Local Government Area'],
    'GB' => ['Nation', 'County'],
    'US' => ['State', 'County'],
    'CA' => ['Province or territory', 'Census division'],
    'AU' => ['State or territory', 'Local government area'],
    'IE' => ['Province', 'County'],
    'IN' => ['State', 'District'],
    'GH' => ['Region', 'District'],
    'KE' => ['County', 'Sub-county'],
    'ZA' => ['Province', 'District'],
    'DE' => ['State', 'District'],
    'FR' => ['Region', 'Department'],
    'IT' => ['Region', 'Province'],
    'ES' => ['Autonomous community', 'Province'],
];

$root = dirname(__DIR__);
$opts = getopt('', ['download', 'src:']);
$src = isset($opts['src']) ? rtrim((string) $opts['src'], '/') : $root . '/dev-tools/geo-src';
if (!is_dir($src) && !mkdir($src, 0755, true)) {
    fwrite(STDERR, "Cannot create $src\n");
    exit(1);
}

foreach (GEO_FILES as $file) {
    $path = $src . '/' . $file;
    if (is_file($path) && !isset($opts['download'])) {
        continue;
    }
    echo "Downloading $file\n";
    $ctx = stream_context_create(['http' => ['timeout' => 60, 'user_agent' => 'gather-build-geo']]);
    $data = file_get_contents(GEO_BASE_URL . $file, false, $ctx);
    if ($data === false || strlen($data) < 1000) {
        fwrite(STDERR, "Download of $file failed\n");
        exit(1);
    }
    file_put_contents($path, $data);
}

$corrections = json_decode((string) file_get_contents($root . '/dev-tools/geo-corrections.json'), true, 512, JSON_THROW_ON_ERROR);

/** @return iterable<list<string>> tab-separated rows, comments skipped */
function geo_rows(string $path): iterable
{
    $fh = fopen($path, 'r');
    if ($fh === false) {
        throw new RuntimeException("Cannot read $path");
    }
    while (($line = fgets($fh)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        yield explode("\t", $line);
    }
    fclose($fh);
}

function geo_clean_name(string $name): string
{
    $name = trim((string) preg_replace('/\s+/u', ' ', $name));
    // "Ehime -Mbano" -> "Ehime-Mbano"
    return trim((string) preg_replace('/\s+-\s*|\s*-\s+/u', '-', $name));
}

function geo_strip_suffix(string $name, ?string $suffix): string
{
    if ($suffix !== null && $suffix !== '' && str_ends_with($name, $suffix) && mb_strlen($name) > mb_strlen($suffix)) {
        return trim(mb_substr($name, 0, mb_strlen($name) - mb_strlen($suffix)));
    }
    return $name;
}

$collator = class_exists('Collator') ? new Collator('en') : null;
$sortByName = static function (array &$list) use ($collator): void {
    usort($list, static fn (array $a, array $b): int => $collator ? $collator->compare($a[1], $b[1]) : strcasecmp($a[1], $b[1]));
};

// ---- Countries -------------------------------------------------------------------------
$countries = [];
foreach (geo_rows($src . '/countryInfo.txt') as $r) {
    $code = $r[0] ?? '';
    if (!preg_match('/^[A-Z]{2}$/', $code) || in_array($code, GEO_OBSOLETE, true) || ($r[16] ?? '') === '') {
        continue;
    }
    $phone = trim((string) ($r[12] ?? ''));
    $phone = $phone === '' ? '' : '+' . ltrim(explode(' and ', $phone)[0], '+');
    $countries[$code] = ['code' => $code, 'name' => geo_clean_name((string) $r[4]), 'phone' => $phone];
}

// ---- First level -----------------------------------------------------------------------
$admin1 = [];
foreach (geo_rows($src . '/admin1CodesASCII.txt') as $r) {
    [$cc, $code] = array_pad(explode('.', (string) $r[0], 2), 2, '');
    if (!isset($countries[$cc]) || $code === '' || $code === '00' || trim((string) ($r[1] ?? '')) === '') {
        continue;
    }
    $fix = $corrections[$cc] ?? [];
    $name = $fix['admin1_rename'][$code] ?? geo_strip_suffix(geo_clean_name((string) $r[1]), $fix['admin1_strip_suffix'] ?? null);
    $admin1[$cc][$code] = $name;
}

// ---- Second level ----------------------------------------------------------------------
$admin2 = [];
foreach (geo_rows($src . '/admin2Codes.txt') as $r) {
    $parts = explode('.', (string) $r[0], 3);
    if (count($parts) !== 3) {
        continue;
    }
    [$cc, $a1, $a2] = $parts;
    if (!isset($admin1[$cc][$a1]) || $a2 === '' || trim((string) ($r[1] ?? '')) === '') {
        continue;
    }
    $fix = $corrections[$cc] ?? [];
    $key = $a1 . '.' . $a2;
    if (in_array($key, $fix['admin2_drop'] ?? [], true)) {
        continue;
    }
    $name = $fix['admin2_rename'][$key] ?? geo_strip_suffix(geo_clean_name((string) $r[1]), $fix['admin2_strip_suffix'] ?? null);
    $admin2[$cc][$a1][$a2] = $name;
}

// ---- Checks ----------------------------------------------------------------------------
$failed = false;
foreach ($corrections as $cc => $fix) {
    if (!is_array($fix) || !isset($fix['expect'])) {
        continue;
    }
    $n1 = count($admin1[$cc] ?? []);
    $n2 = array_sum(array_map('count', $admin2[$cc] ?? []));
    foreach (['admin1' => $n1, 'admin2' => $n2] as $level => $n) {
        if (isset($fix['expect'][$level]) && $fix['expect'][$level] !== $n) {
            fwrite(STDERR, "$cc: expected {$fix['expect'][$level]} $level, found $n\n");
            $failed = true;
        }
    }
    foreach ($fix['admin2_rename'] ?? [] as $key => $name) {
        [$a1, $a2] = explode('.', $key, 2);
        if (($admin2[$cc][$a1][$a2] ?? null) !== $name) {
            fwrite(STDERR, "$cc: correction $key ($name) did not apply\n");
            $failed = true;
        }
    }
    echo "$cc: $n1 first-level, $n2 second-level\n";
}
if ($failed) {
    exit(1);
}

// ---- Time zones ------------------------------------------------------------------------
$tz = [];
foreach (geo_rows($src . '/timeZones.txt') as $r) {
    if (($r[0] ?? '') === 'CountryCode' || !isset($countries[$r[0] ?? ''])) {
        continue;
    }
    $tz[(string) $r[1]] = (string) $r[0];
}
foreach (GEO_TZ_ALIASES as $zone => $cc) {
    $tz[$zone] ??= $cc;
}
ksort($tz);

// ---- Write -----------------------------------------------------------------------------
$outDir = $root . '/public/assets/geo';
if (!is_dir($outDir) && !mkdir($outDir, 0755, true)) {
    fwrite(STDERR, "Cannot create $outDir\n");
    exit(1);
}
foreach (glob($outDir . '/*.json') ?: [] as $old) {
    unlink($old);
}
$list = [];
foreach ($countries as $cc => $c) {
    $list[] = [$cc, $c['name'], $c['phone'], isset($admin1[$cc]) ? 1 : 0];
}
$sortByName($list);
$version = substr(hash('sha256', json_encode([$list, $admin1, $admin2, $tz], JSON_THROW_ON_ERROR)), 0, 12);
$json = static fn (mixed $v): string => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

file_put_contents($outDir . '/countries.json', $json(['v' => $version, 'countries' => $list, 'labels' => GEO_LABELS, 'tz' => $tz]) . "\n");
$bytes = 0;
foreach ($admin1 as $cc => $states) {
    $a1 = [];
    foreach ($states as $code => $name) {
        $a1[] = [(string) $code, $name];
    }
    $sortByName($a1);
    $a2 = [];
    foreach ($admin2[$cc] ?? [] as $code => $areas) {
        $rows = [];
        foreach ($areas as $c2 => $name) {
            $rows[] = [(string) $c2, $name];
        }
        $sortByName($rows);
        $a2[(string) $code] = $rows;
    }
    $data = $json(['c' => $cc, 'a1' => $a1, 'a2' => (object) $a2]) . "\n";
    $bytes += strlen($data);
    file_put_contents($outDir . '/' . strtolower($cc) . '.json', $data);
}

$index = [];
foreach ($list as [$cc, $name, $phone, $has]) {
    $index[$cc] = [$name, $phone, $has];
}
$php = "<?php\n// Generated by dev-tools/build-geo.php from GeoNames (CC BY 4.0). Do not edit by hand.\n"
    . "// Country code => [English name, phone code, has first-level divisions (public/assets/geo/<cc>.json)].\n"
    . 'return ' . var_export(['version' => $version, 'countries' => $index, 'labels' => GEO_LABELS], true) . ";\n";
if (!is_dir($root . '/src/geo')) {
    mkdir($root . '/src/geo', 0755, true);
}
file_put_contents($root . '/src/geo/index.php', $php);

printf("%d countries, %d with divisions (%.0f KB), %d time zones; version %s\n", count($list), count($admin1), $bytes / 1024, count($tz), $version);
