<?php
declare(strict_types=1);

/*
 * Cascading location fields (Addendum C4): Country -> first-level division
 * (state/region) -> second-level area (LGA/county), from the bundled
 * GeoNames data (public/assets/geo/, built by dev-tools/build-geo.php) plus
 * local areas the admins add ("Location lists"). Values are stored as ISO
 * country code + GeoNames admin codes + a snapshot of the display names in
 * member_locations (one row per member and "slot": residence, nationality,
 * origin, cf<custom field id>).
 */

/** Slots used by the standard profile fields. Custom location fields use "cf<id>". */
const GEO_SLOTS = ['residence', 'nationality', 'origin'];
/** Admin-added local areas get codes "x<id>" so they never clash with GeoNames codes. */
const GEO_CUSTOM_PREFIX = 'x';

/** The generated PHP index: version, countries (code => [name, phone, has divisions]), built-in labels. */
function geo_index(): array
{
    static $index = null;
    if ($index === null) {
        $index = require BASE . '/src/geo/index.php';
    }
    return $index;
}

/** @return array<string,string> ISO code => English name, alphabetical */
function geo_countries(): array
{
    static $out = null;
    if ($out === null) {
        $out = [];
        foreach (geo_index()['countries'] as $cc => [$name]) {
            $out[$cc] = $name;
        }
    }
    return $out;
}

function geo_country_exists(string $cc): bool
{
    return isset(geo_index()['countries'][$cc]);
}

function geo_country_name(?string $cc): string
{
    return $cc !== null && $cc !== '' ? (geo_index()['countries'][$cc][0] ?? $cc) : '';
}

/** URL of a country's division list (versioned, so it can be cached for a long time). */
function geo_country_url(string $cc): string
{
    return '/assets/geo/' . strtolower($cc) . '.json?v=' . geo_index()['version'];
}

/**
 * First- and second-level divisions of a country from the bundled file.
 * @return array{a1:array<string,string>, a2:array<string,array<string,string>>}
 */
function geo_country_data(string $cc): array
{
    static $cache = [];
    if (isset($cache[$cc])) {
        return $cache[$cc];
    }
    $out = ['a1' => [], 'a2' => []];
    // Only codes from the generated index ever reach the file system.
    if (geo_country_exists($cc) && (int) geo_index()['countries'][$cc][2] === 1) {
        $raw = json_decode((string) file_get_contents(BASE . '/public/assets/geo/' . strtolower($cc) . '.json'), true);
        foreach (is_array($raw['a1'] ?? null) ? $raw['a1'] : [] as [$code, $name]) {
            $out['a1'][(string) $code] = (string) $name;
        }
        foreach (is_array($raw['a2'] ?? null) ? $raw['a2'] : [] as $a1 => $rows) {
            foreach ($rows as [$code, $name]) {
                $out['a2'][(string) $a1][(string) $code] = (string) $name;
            }
        }
    }
    return $cache[$cc] = $out;
}

/** Local areas added by admins, by country and first-level code. Reset with $reset (tests, after edits). */
function geo_custom_areas(?string $cc = null, bool $reset = false): array
{
    static $all = null;
    if ($reset) {
        $all = null;
    }
    if ($all === null) {
        $all = [];
        try {
            foreach (q_all('SELECT id, country_code, admin1_code, name FROM geo_custom_areas ORDER BY name') as $r) {
                $all[$r['country_code']][$r['admin1_code']][GEO_CUSTOM_PREFIX . $r['id']] = (string) $r['name'];
            }
        } catch (PDOException) {
            // Before migration 020 the table does not exist.
        }
    }
    return $cc === null ? $all : ($all[$cc] ?? []);
}

/** @return array<string,string> code => name */
function geo_admin1_options(string $cc): array
{
    return geo_country_data($cc)['a1'];
}

/** @return array<string,string> code => name (bundled areas and admin-added ones, alphabetical) */
function geo_admin2_options(string $cc, string $a1): array
{
    $out = (geo_country_data($cc)['a2'][$a1] ?? []) + (geo_custom_areas($cc)[$a1] ?? []);
    if (class_exists('Collator')) {
        (new Collator('en'))->asort($out);
    } else {
        asort($out, SORT_FLAG_CASE | SORT_STRING);
    }
    return $out;
}

/** Built-in label pairs merged with the admins' overrides (Location lists). @return array<string,array{0:string,1:string}> */
function geo_label_map(): array
{
    $map = geo_index()['labels'];
    foreach ((array) setting('geo.labels', []) as $cc => $pair) {
        if (is_string($cc) && geo_country_exists($cc) && is_array($pair)) {
            $base = $map[$cc] ?? ['Region', 'Area'];
            $map[$cc] = [trim((string) ($pair[0] ?? '')) ?: $base[0], trim((string) ($pair[1] ?? '')) ?: $base[1]];
        }
    }
    return $map;
}

/** [first level, second level] labels for a country: Nigeria State / Local Government Area, default Region / Area. */
function geo_labels(?string $cc): array
{
    return geo_label_map()[$cc ?? ''] ?? ['Region', 'Area'];
}

/** Country suggested by a CDN header (no IP database): CF-IPCountry, CloudFront, or a generic header. */
function geo_header_country(?array $server = null): ?string
{
    $server ??= $_SERVER;
    foreach (['HTTP_CF_IPCOUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'HTTP_X_COUNTRY_CODE', 'HTTP_X_VERCEL_IP_COUNTRY'] as $h) {
        $v = strtoupper(trim((string) ($server[$h] ?? '')));
        if (preg_match('/^[A-Z]{2}$/', $v) && geo_country_exists($v)) {
            return $v;
        }
    }
    return null;
}

// ---- Configuration of a location field ---------------------------------------------

/**
 * Normalised configuration: levels 1 (country) .. 3 (country, state, area),
 * optional fixed country, free-text city, required levels and labels.
 */
function geo_config(array $c): array
{
    $levels = max(1, min(3, (int) ($c['levels'] ?? 2)));
    $country = strtoupper((string) ($c['country'] ?? ''));
    return [
        'levels' => $levels,
        'country' => geo_country_exists($country) ? $country : '',
        'city' => (bool) ($c['city'] ?? false),
        'required' => max(0, min($levels, (int) ($c['required'] ?? 0))),
        'label' => (string) ($c['label'] ?? 'Location'),
        'country_label' => (string) ($c['country_label'] ?? 'Country'),
        'l1_label' => trim((string) ($c['l1_label'] ?? '')),
        'l2_label' => trim((string) ($c['l2_label'] ?? '')),
        'l1_suffix' => (string) ($c['l1_suffix'] ?? ''),
        'city_label' => (string) ($c['city_label'] ?? 'Town or city'),
        'hint' => (bool) ($c['hint'] ?? false),
    ];
}

/** Label of level 1 or 2 for a country under a configuration. */
function geo_level_label(array $cfg, int $level, ?string $cc): string
{
    $fixed = $level === 1 ? $cfg['l1_label'] : $cfg['l2_label'];
    if ($fixed !== '') {
        return $fixed;
    }
    $label = geo_labels($cc)[$level - 1];
    return $level === 1 ? $label . $cfg['l1_suffix'] : $label;
}

/** Sign-up "origin" block settings (super admin, Membership settings). */
function geo_origin_settings(): array
{
    $d = ['enabled' => false, 'levels' => 3, 'required' => 0, 'country' => '', 'country_label' => 'Country of origin', 'l1_label' => '', 'l2_label' => '', 'legend' => 'Where you are from'];
    $saved = (array) setting('members.origin', []);
    foreach ($d as $k => $v) {
        if (array_key_exists($k, $saved) && gettype($saved[$k]) === gettype($v)) {
            $d[$k] = $saved[$k];
        }
    }
    return $d;
}

/** @return array<string,array> slot => configuration for the standard member fields */
function member_location_configs(bool $forAdmin = false, bool $includeOrigin = true): array
{
    $out = [
        'residence' => geo_config(['levels' => 2, 'city' => true, 'required' => $forAdmin ? 0 : 1, 'label' => 'Where you live', 'country_label' => 'Country of residence', 'hint' => true]),
        'nationality' => geo_config(['levels' => 1, 'label' => 'Nationality', 'country_label' => 'Nationality']),
    ];
    $o = geo_origin_settings();
    if ($includeOrigin && $o['enabled']) {
        $out['origin'] = geo_config(['levels' => $o['levels'], 'country' => $o['country'], 'required' => $forAdmin ? 0 : $o['required'], 'label' => $o['legend'],
            'country_label' => $o['country_label'], 'l1_label' => $o['l1_label'], 'l2_label' => $o['l2_label'], 'l1_suffix' => ' of origin']);
    }
    return $out;
}

/** Form input prefix for a slot (res_country, nat_country, origin_state, cf_<key>_area...). */
function geo_prefix(string $slot, ?string $fieldKey = null): string
{
    return match ($slot) {
        'residence' => 'res',
        'nationality' => 'nat',
        'origin' => 'origin',
        default => 'cf_' . (string) $fieldKey,
    };
}

// ---- Values ------------------------------------------------------------------------

function geo_empty(): array
{
    return ['country_code' => null, 'country_name' => null, 'admin1_code' => null, 'admin1_name' => null, 'admin2_code' => null, 'admin2_name' => null, 'locality' => null];
}

function geo_is_empty(?array $loc): bool
{
    if ($loc === null) {
        return true;
    }
    foreach (geo_empty() as $k => $_) {
        if (($loc[$k] ?? null) !== null && $loc[$k] !== '') {
            return false;
        }
    }
    return true;
}

/**
 * Validate one location from form input (names <prefix>_country, _state,
 * _state_text, _area, _area_text, _city). Codes must exist in the bundled
 * data or the admin-added areas; levels without a list become free text.
 * @return array{0:?array,1:array<string,string>} [location or null when empty, errors by input name]
 */
function geo_validate(array $in, string $p, array $cfg): array
{
    $str = static fn (string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : '';
    $errors = [];
    $loc = geo_empty();
    $cc = $cfg['country'] !== '' ? $cfg['country'] : strtoupper($str($p . '_country'));
    if ($cc === '') {
        if ($cfg['required'] >= 1) {
            $errors[$p . '_country'] = 'Choose ' . geo_article($cfg['country_label']) . '.';
        }
        return [null, $errors];
    }
    if (!geo_country_exists($cc)) {
        return [null, [$p . '_country' => 'Choose a country from the list.']];
    }
    $loc['country_code'] = $cc;
    $loc['country_name'] = geo_country_name($cc);
    if ($cfg['levels'] >= 2) {
        $label = geo_level_label($cfg, 1, $cc);
        [$code, $name, $err] = geo_validate_level(geo_admin1_options($cc), $str($p . '_state'), $str($p . '_state_text'), $label);
        if ($err !== null) {
            $errors[$p . '_state'] = $err;
        } elseif ($name === null && $cfg['required'] >= 2) {
            $errors[$p . '_state'] = 'Choose your ' . lcfirst_label($label) . '.';
        }
        [$loc['admin1_code'], $loc['admin1_name']] = [$code, $name];
        if ($cfg['levels'] >= 3 && $name !== null) {
            $label2 = geo_level_label($cfg, 2, $cc);
            $opts = $code !== null ? geo_admin2_options($cc, $code) : [];
            [$code2, $name2, $err2] = geo_validate_level($opts, $str($p . '_area'), $str($p . '_area_text'), $label2);
            if ($err2 !== null) {
                $errors[$p . '_area'] = $err2;
            } elseif ($name2 === null && $cfg['required'] >= 3) {
                $errors[$p . '_area'] = 'Choose your ' . lcfirst_label($label2) . '.';
            }
            [$loc['admin2_code'], $loc['admin2_name']] = [$code2, $name2];
        } elseif ($cfg['levels'] >= 3 && $cfg['required'] >= 3 && !isset($errors[$p . '_state'])) {
            $errors[$p . '_area'] = 'Choose your ' . lcfirst_label(geo_level_label($cfg, 2, $cc)) . '.';
        }
    }
    if ($cfg['city']) {
        $city = $str($p . '_city');
        if (mb_strlen($city) > 80) {
            $errors[$p . '_city'] = 'Keep this under 80 characters.';
        }
        $loc['locality'] = $city !== '' ? mb_substr($city, 0, 80) : null;
    }
    return [$loc, $errors];
}

/** @return array{0:?string,1:?string,2:?string} [code, name, error] */
function geo_validate_level(array $options, string $code, string $text, string $label): array
{
    if ($options !== []) {
        if ($code === '') {
            return [null, null, null];
        }
        return isset($options[$code]) ? [$code, $options[$code], null] : [null, null, 'Choose a ' . lcfirst_label($label) . ' from the list.'];
    }
    // No list for this country (or state): free text.
    if (mb_strlen($text) > 120) {
        return [null, null, 'Keep this under 120 characters.'];
    }
    return [null, $text !== '' ? $text : null, null];
}

function lcfirst_label(string $label): string
{
    // "Local Government Area" stays capitalised; "Region" -> "region".
    return preg_match('/^[A-Z][a-z]+$/', $label) ? strtolower($label) : $label;
}

function geo_article(string $label): string
{
    $l = lcfirst_label($label);
    return preg_match('/^(country|nationality|region|state)/i', $l) ? 'your ' . strtolower($l) : $l;
}

/** Form values (<prefix>_country ...) for a stored location. */
function geo_form_values(?array $loc, string $p): array
{
    $loc ??= geo_empty();
    $a1 = (string) ($loc['admin1_code'] ?? '');
    $a2 = (string) ($loc['admin2_code'] ?? '');
    return [
        $p . '_country' => (string) ($loc['country_code'] ?? ''),
        $p . '_state' => $a1, $p . '_state_text' => $a1 === '' ? (string) ($loc['admin1_name'] ?? '') : '',
        $p . '_area' => $a2, $p . '_area_text' => $a2 === '' ? (string) ($loc['admin2_name'] ?? '') : '',
        $p . '_city' => (string) ($loc['locality'] ?? ''),
    ];
}

/**
 * Text for a location at a level: 'country', 'admin1', 'admin2', 'locality'
 * or 'full' ("Ado-Ekiti, Ekiti, Nigeria").
 */
function geo_display(?array $loc, string $level = 'full'): string
{
    if ($loc === null) {
        return '';
    }
    $country = (string) ($loc['country_name'] ?? '') ?: geo_country_name($loc['country_code'] ?? null);
    return match ($level) {
        'country' => $country,
        'admin1' => (string) ($loc['admin1_name'] ?? ''),
        'admin2' => (string) ($loc['admin2_name'] ?? ''),
        'locality' => (string) ($loc['locality'] ?? ''),
        default => implode(', ', array_values(array_filter([(string) ($loc['locality'] ?? ''), (string) ($loc['admin2_name'] ?? ''), (string) ($loc['admin1_name'] ?? ''), $country], static fn (string $s): bool => $s !== ''))),
    };
}

// ---- Storage -----------------------------------------------------------------------

/** @return array<string,array> slot => location */
function member_locations(int $memberId): array
{
    $out = [];
    foreach (q_all('SELECT * FROM member_locations WHERE member_id = ?', [$memberId]) as $r) {
        $out[(string) $r['slot']] = array_intersect_key($r, geo_empty());
    }
    return $out;
}

/**
 * Save locations by slot (null/empty = remove). The members table keeps
 * the display names of residence and nationality (country, region, city,
 * nationality) as a snapshot for lists, search and older reports.
 * @param array<string,?array> $locations
 */
function member_locations_save(int $memberId, array $locations): void
{
    foreach ($locations as $slot => $loc) {
        $slot = (string) $slot;
        if (!in_array($slot, GEO_SLOTS, true) && !preg_match('/^cf\d{1,9}$/', $slot)) {
            continue;
        }
        if (geo_is_empty($loc)) {
            q('DELETE FROM member_locations WHERE member_id = ? AND slot = ?', [$memberId, $slot]);
            continue;
        }
        $row = array_map(static fn ($v) => $v === '' ? null : $v, array_intersect_key($loc + geo_empty(), geo_empty()));
        q('INSERT INTO member_locations (member_id, slot, country_code, country_name, admin1_code, admin1_name, admin2_code, admin2_name, locality)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE country_code = VALUES(country_code), country_name = VALUES(country_name), admin1_code = VALUES(admin1_code),
               admin1_name = VALUES(admin1_name), admin2_code = VALUES(admin2_code), admin2_name = VALUES(admin2_name), locality = VALUES(locality)',
            [$memberId, $slot, $row['country_code'], $row['country_name'], $row['admin1_code'], $row['admin1_name'], $row['admin2_code'], $row['admin2_name'], $row['locality']]);
    }
}

/** Snapshot columns on members for the residence and nationality locations. */
function member_location_snapshot(array $locations): array
{
    $out = [];
    if (array_key_exists('residence', $locations)) {
        $r = $locations['residence'];
        $out['country'] = geo_is_empty($r) ? null : (mb_substr(geo_display($r, 'country'), 0, 80) ?: null);
        $out['region'] = geo_is_empty($r) ? null : (mb_substr(geo_display($r, 'admin1'), 0, 80) ?: null);
        $out['city'] = geo_is_empty($r) ? null : (mb_substr(geo_display($r, 'locality'), 0, 80) ?: null);
    }
    if (array_key_exists('nationality', $locations)) {
        $out['nationality'] = geo_is_empty($locations['nationality']) ? null : (mb_substr(geo_display($locations['nationality'], 'country'), 0, 80) ?: null);
    }
    return $out;
}

// ---- Matching free text (migration, CSV import) --------------------------------------

/** Lower-case ASCII key for comparing place names ("Akwa-Ibom State" -> "akwa ibom"). */
function geo_name_key(string $name, bool $stripWords = true): string
{
    $s = mb_strtolower(trim($name));
    if (class_exists('Normalizer')) {
        $s = (string) preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize($s, Normalizer::FORM_D));
    }
    $s = (string) preg_replace('/[^a-z0-9]+/u', ' ', $s);
    if ($stripWords) {
        $s = (string) preg_replace('/\b(local government area|lga|state|province|county|region|of|the)\b/', ' ', $s);
    }
    return trim((string) preg_replace('/\s+/', ' ', $s));
}

/** Other names people type for countries, and nationalities (demonyms). */
function geo_country_aliases(): array
{
    return [
        'uk' => 'GB', 'u k' => 'GB', 'great britain' => 'GB', 'britain' => 'GB', 'england' => 'GB', 'scotland' => 'GB', 'wales' => 'GB', 'northern ireland' => 'GB',
        'british' => 'GB', 'english' => 'GB', 'scottish' => 'GB', 'welsh' => 'GB', 'northern irish' => 'GB', 'united kingdom great britain and northern ireland' => 'GB',
        'usa' => 'US', 'u s a' => 'US', 'us' => 'US', 'america' => 'US', 'united states america' => 'US', 'american' => 'US',
        'nigerian' => 'NG', 'ghanaian' => 'GH', 'kenyan' => 'KE', 'south african' => 'ZA', 'ugandan' => 'UG', 'tanzanian' => 'TZ', 'cameroonian' => 'CM',
        'sierra leonean' => 'SL', 'liberian' => 'LR', 'gambian' => 'GM', 'senegalese' => 'SN', 'ivorian' => 'CI', 'cote d ivoire' => 'CI', 'togolese' => 'TG',
        'beninese' => 'BJ', 'zimbabwean' => 'ZW', 'zambian' => 'ZM', 'malawian' => 'MW', 'ethiopian' => 'ET', 'eritrean' => 'ER', 'somali' => 'SO',
        'sudanese' => 'SD', 'egyptian' => 'EG', 'moroccan' => 'MA', 'algerian' => 'DZ', 'tunisian' => 'TN', 'libyan' => 'LY', 'congolese' => 'CD', 'rwandan' => 'RW',
        'irish' => 'IE', 'french' => 'FR', 'german' => 'DE', 'italian' => 'IT', 'spanish' => 'ES', 'portuguese' => 'PT', 'dutch' => 'NL', 'netherlands' => 'NL', 'holland' => 'NL',
        'belgian' => 'BE', 'swiss' => 'CH', 'austrian' => 'AT', 'polish' => 'PL', 'czech' => 'CZ', 'czech republic' => 'CZ', 'slovak' => 'SK', 'hungarian' => 'HU',
        'romanian' => 'RO', 'bulgarian' => 'BG', 'greek' => 'GR', 'turkish' => 'TR', 'turkey' => 'TR', 'cypriot' => 'CY', 'maltese' => 'MT', 'swedish' => 'SE',
        'norwegian' => 'NO', 'danish' => 'DK', 'finnish' => 'FI', 'icelandic' => 'IS', 'lithuanian' => 'LT', 'latvian' => 'LV', 'estonian' => 'EE', 'ukrainian' => 'UA',
        'russian' => 'RU', 'russia' => 'RU', 'croatian' => 'HR', 'serbian' => 'RS', 'bosnian' => 'BA', 'albanian' => 'AL', 'slovenian' => 'SI', 'kosovan' => 'XK',
        'indian' => 'IN', 'pakistani' => 'PK', 'bangladeshi' => 'BD', 'sri lankan' => 'LK', 'nepalese' => 'NP', 'nepali' => 'NP', 'afghan' => 'AF', 'iranian' => 'IR', 'iran' => 'IR',
        'iraqi' => 'IQ', 'syrian' => 'SY', 'syria' => 'SY', 'lebanese' => 'LB', 'jordanian' => 'JO', 'israeli' => 'IL', 'palestinian' => 'PS', 'palestine' => 'PS',
        'saudi' => 'SA', 'saudi arabian' => 'SA', 'emirati' => 'AE', 'uae' => 'AE', 'yemeni' => 'YE', 'chinese' => 'CN', 'japanese' => 'JP', 'korean' => 'KR', 'south korean' => 'KR',
        'south korea' => 'KR', 'north korea' => 'KP', 'vietnamese' => 'VN', 'thai' => 'TH', 'filipino' => 'PH', 'philippine' => 'PH', 'malaysian' => 'MY', 'indonesian' => 'ID',
        'singaporean' => 'SG', 'burmese' => 'MM', 'burma' => 'MM', 'australian' => 'AU', 'new zealander' => 'NZ', 'kiwi' => 'NZ', 'canadian' => 'CA', 'mexican' => 'MX',
        'brazilian' => 'BR', 'argentinian' => 'AR', 'argentine' => 'AR', 'chilean' => 'CL', 'colombian' => 'CO', 'peruvian' => 'PE', 'venezuelan' => 'VE', 'cuban' => 'CU',
        'jamaican' => 'JM', 'trinidadian' => 'TT', 'barbadian' => 'BB', 'bajan' => 'BB', 'guyanese' => 'GY', 'haitian' => 'HT', 'dominican' => 'DO',
        'drc' => 'CD', 'dr congo' => 'CD', 'democratic republic congo' => 'CD', 'republic congo' => 'CG', 'ivory coast' => 'CI', 'swaziland' => 'SZ', 'eswatini' => 'SZ',
        'vatican' => 'VA', 'macedonia' => 'MK', 'north macedonian' => 'MK', 'moldovan' => 'MD', 'belarusian' => 'BY', 'georgian' => 'GE', 'armenian' => 'AM', 'azerbaijani' => 'AZ',
        'kazakh' => 'KZ', 'uzbek' => 'UZ', 'mongolian' => 'MN', 'taiwanese' => 'TW', 'hong konger' => 'HK', 'luxembourgish' => 'LU', 'montenegrin' => 'ME',
    ];
}

/** ISO code for a typed country or nationality, or null. */
function geo_match_country(string $text): ?string
{
    $t = trim($text);
    if ($t === '') {
        return null;
    }
    if (preg_match('/^[A-Za-z]{2}$/', $t) && geo_country_exists(strtoupper($t))) {
        return strtoupper($t);
    }
    static $byName = null;
    if ($byName === null) {
        $byName = [];
        foreach (geo_countries() as $cc => $name) {
            $byName[geo_name_key($name, false)] = $cc;
        }
    }
    $key = geo_name_key($t, false);
    $alias = geo_country_aliases();
    return $byName[$key] ?? $alias[$key] ?? $alias[geo_name_key($t)] ?? $byName[geo_name_key($t)] ?? null;
}

/** First-level code for a typed name in a country, or null. */
function geo_match_admin1(string $cc, string $text): ?string
{
    return geo_match_in(geo_admin1_options($cc), $text);
}

function geo_match_admin2(string $cc, string $a1, string $text): ?string
{
    return geo_match_in(geo_admin2_options($cc, $a1), $text);
}

/** @param array<string,string> $options code => name */
function geo_match_in(array $options, string $text): ?string
{
    $t = trim($text);
    if ($t === '' || $options === []) {
        return null;
    }
    if (isset($options[$t])) {
        return $t;
    }
    foreach ([false, true] as $strip) {
        $key = geo_name_key($t, $strip);
        if ($key === '') {
            continue;
        }
        foreach ($options as $code => $name) {
            if (geo_name_key($name, $strip) === $key) {
                return (string) $code;
            }
        }
    }
    return null;
}

/**
 * Turn typed names into a location (used by the upgrade of existing members
 * and by CSV import). Unmatched levels keep the typed name without a code,
 * so nothing typed is lost.
 */
function geo_from_names(string $country, string $admin1 = '', string $admin2 = '', string $city = ''): ?array
{
    $loc = geo_empty();
    $cc = geo_match_country($country);
    if ($cc === null && $admin1 !== '') {
        // "Wales" typed as the region with no country: a UK nation.
        $guess = geo_match_country($admin1);
        $cc = $guess === 'GB' && geo_match_admin1('GB', $admin1) !== null ? 'GB' : null;
    }
    if ($cc !== null) {
        $loc['country_code'] = $cc;
        $loc['country_name'] = geo_country_name($cc);
    } elseif (trim($country) !== '') {
        $loc['country_name'] = mb_substr(trim($country), 0, 80);
    }
    if (trim($admin1) !== '') {
        $a1 = $cc !== null ? geo_match_admin1($cc, $admin1) : null;
        $loc['admin1_code'] = $a1;
        $loc['admin1_name'] = $a1 !== null ? geo_admin1_options($cc)[$a1] : mb_substr(trim($admin1), 0, 120);
        if (trim($admin2) !== '') {
            $a2 = $a1 !== null ? geo_match_admin2((string) $cc, $a1, $admin2) : null;
            $loc['admin2_code'] = $a2;
            $loc['admin2_name'] = $a2 !== null ? geo_admin2_options((string) $cc, $a1)[$a2] : mb_substr(trim($admin2), 0, 120);
        }
    }
    if (trim($city) !== '') {
        $loc['locality'] = mb_substr(trim($city), 0, 80);
    }
    return geo_is_empty($loc) ? null : $loc;
}

/** Do the codes of a stored location still exist (bundled data or admin-added areas)? */
function geo_codes_valid(array $loc): bool
{
    $cc = (string) ($loc['country_code'] ?? '');
    if ($cc === '' || !geo_country_exists($cc)) {
        return false;
    }
    $a1 = (string) ($loc['admin1_code'] ?? '');
    if ($a1 !== '' && !isset(geo_admin1_options($cc)[$a1])) {
        return false;
    }
    $a2 = (string) ($loc['admin2_code'] ?? '');
    return $a2 === '' || ($a1 !== '' && isset(geo_admin2_options($cc, $a1)[$a2]));
}

// ---- Filters and audiences ------------------------------------------------------------

/**
 * A location filter "slot|CC|admin1|admin2" (used by member filters and
 * campaign audiences) -> [slot, cc, a1, a2], or null when invalid.
 */
function geo_filter_decode(string $value): ?array
{
    $parts = explode('|', $value);
    if (count($parts) !== 4) {
        return null;
    }
    [$slot, $cc, $a1, $a2] = $parts;
    if ((!in_array($slot, GEO_SLOTS, true) && !preg_match('/^cf\d{1,9}$/', $slot)) || !geo_country_exists($cc)
        || ($a1 !== '' && !isset(geo_admin1_options($cc)[$a1])) || ($a2 !== '' && ($a1 === '' || !isset(geo_admin2_options($cc, $a1)[$a2])))) {
        return null;
    }
    return [$slot, $cc, $a1, $a2];
}

function geo_filter_encode(string $slot, string $cc, string $a1 = '', string $a2 = ''): string
{
    return $slot . '|' . $cc . '|' . $a1 . '|' . $a2;
}

/** SQL condition (members alias m) for a decoded filter. @return array{0:string,1:list<mixed>} */
function geo_filter_sql(array $f): array
{
    [$slot, $cc, $a1, $a2] = $f;
    $sql = 'EXISTS (SELECT 1 FROM member_locations ml WHERE ml.member_id = m.id AND ml.slot = ? AND ml.country_code = ?';
    $params = [$slot, $cc];
    if ($a1 !== '') {
        $sql .= ' AND ml.admin1_code = ?';
        $params[] = $a1;
    }
    if ($a2 !== '') {
        $sql .= ' AND ml.admin2_code = ?';
        $params[] = $a2;
    }
    return [$sql . ')', $params];
}

/** Slots a filter can use: standard ones plus location custom fields. @return array<string,string> slot => label */
function geo_filter_slots(): array
{
    $out = ['residence' => 'Country of residence', 'origin' => 'Origin', 'nationality' => 'Nationality'];
    foreach (custom_fields('member') as $f) {
        if ($f['type'] === 'location') {
            $out['cf' . $f['id']] = (string) $f['label'];
        }
    }
    return $out;
}

/** "Members whose state of origin is Ekiti (Nigeria)". */
function geo_filter_label(array $f): string
{
    [$slot, $cc, $a1, $a2] = $f;
    $slots = geo_filter_slots();
    $what = $slots[$slot] ?? 'Location';
    $place = $a2 !== '' ? geo_admin2_options($cc, $a1)[$a2] . ', ' . geo_admin1_options($cc)[$a1] : ($a1 !== '' ? geo_admin1_options($cc)[$a1] : '');
    return $what . ': ' . ($place !== '' ? $place . ' (' . geo_country_name($cc) . ')' : geo_country_name($cc));
}

// ---- Custom location fields --------------------------------------------------------------

/** Configuration of a custom field of type "location" (custom_fields.field_config). */
function custom_field_location_config(array $field): array
{
    $c = json_decode((string) ($field['field_config'] ?? ''), true);
    $c = is_array($c) ? $c : [];
    $levels = max(1, min(3, (int) ($c['levels'] ?? 2)));
    return geo_config([
        'levels' => $levels, 'country' => (string) ($c['country'] ?? ''), 'required' => (int) $field['is_required'] === 1 ? (($c['country'] ?? '') !== '' ? 2 : 1) : 0,
        'label' => (string) $field['label'], 'country_label' => (string) ($c['country_label'] ?? '') ?: 'Country',
        'l1_label' => (string) ($c['l1_label'] ?? ''), 'l2_label' => (string) ($c['l2_label'] ?? ''),
    ]) + ['card_level' => in_array($c['card_level'] ?? '', ['full', 'country', 'admin1', 'admin2'], true) ? $c['card_level'] : 'full'];
}

// ---- Admin-added local areas ----------------------------------------------------------------

/** Add a missing local area under a first-level division. Returns the new id. */
function geo_custom_area_add(string $cc, string $a1, string $name, ?int $by): int
{
    $name = trim((string) preg_replace('/\s+/u', ' ', $name));
    if (!geo_country_exists($cc) || !isset(geo_admin1_options($cc)[$a1])) {
        throw new InvalidArgumentException('Choose a country and a first-level division from the list.');
    }
    if ($name === '' || mb_strlen($name) > 120) {
        throw new InvalidArgumentException('Enter the name of the local area (up to 120 characters).');
    }
    if (geo_match_in(geo_admin2_options($cc, $a1), $name) !== null) {
        throw new InvalidArgumentException('“' . $name . '” is already on the list.');
    }
    $id = db_insert('geo_custom_areas', ['country_code' => $cc, 'admin1_code' => $a1, 'name' => $name, 'created_by' => $by]);
    setting_set('geo.custom_version', (int) setting('geo.custom_version', 0) + 1);
    geo_custom_areas(null, true);
    return $id;
}

/** Members (and filters) using an admin-added area. */
function geo_custom_area_usage(int $id): int
{
    $a = q_one('SELECT country_code, admin1_code FROM geo_custom_areas WHERE id = ?', [$id]);
    return $a === null ? 0 : (int) q_val('SELECT COUNT(*) FROM member_locations WHERE country_code = ? AND admin1_code = ? AND admin2_code = ?', [$a['country_code'], $a['admin1_code'], GEO_CUSTOM_PREFIX . $id]);
}

/** Everything location.js needs besides the bundled files: admin-added areas. */
function geo_custom_payload(): array
{
    $areas = [];
    foreach (geo_custom_areas() as $cc => $byA1) {
        foreach ($byA1 as $a1 => $list) {
            foreach ($list as $code => $name) {
                $areas[$cc][$a1][] = [$code, $name];
            }
        }
    }
    return ['areas' => (object) $areas];
}

/** Data attributes for a form that contains location fields (labels, versions, custom areas). */
function geo_form_attrs(): string
{
    $v = (int) setting('geo.custom_version', 0);
    $attrs = ' data-geo-v="' . e(geo_index()['version']) . '" data-geo-labels="' . e(json_attr(geo_label_map())) . '"';
    if (geo_custom_areas() !== []) {
        $attrs .= ' data-geo-custom="' . e('/geo/custom.json?v=' . $v) . '"';
    }
    $header = geo_header_country();
    if ($header !== null) {
        $attrs .= ' data-geo-header="' . e($header) . '"';
    }
    return $attrs;
}

/**
 * A text or choice field became a Location field: turn its saved answers
 * into locations. With a fixed country the answers are matched against its
 * states (and, with three levels, against its local areas when the state is
 * unambiguous); otherwise "area, state, country" text is matched like the
 * upgrade does. Answers that do not match are kept as typed (no code).
 * @return array{matched:int, kept:int}
 */
function custom_field_location_convert(array $field): array
{
    $cfg = custom_field_location_config($field);
    $slot = 'cf' . (int) $field['id'];
    $out = ['matched' => 0, 'kept' => 0];
    foreach (q_all("SELECT member_id, value FROM member_field_values WHERE field_id = ? AND value IS NOT NULL AND value <> ''", [(int) $field['id']]) as $r) {
        $text = trim((string) $r['value']);
        $cc = $cfg['country'];
        if ($cc !== '') {
            $loc = geo_empty();
            $loc['country_code'] = $cc;
            $loc['country_name'] = geo_country_name($cc);
            $a1 = geo_match_admin1($cc, $text);
            if ($a1 !== null) {
                [$loc['admin1_code'], $loc['admin1_name']] = [$a1, geo_admin1_options($cc)[$a1]];
            } elseif ($cfg['levels'] >= 3 && ($hit = geo_find_admin2($cc, $text)) !== null) {
                [$loc['admin1_code'], $loc['admin1_name'], $loc['admin2_code'], $loc['admin2_name']] = [$hit[0], geo_admin1_options($cc)[$hit[0]], $hit[1], geo_admin2_options($cc, $hit[0])[$hit[1]]];
            } else {
                $loc['admin1_name'] = mb_substr($text, 0, 120);
            }
        } else {
            $bits = array_values(array_filter(array_map('trim', explode(',', $text)), static fn (string $b): bool => $b !== ''));
            $loc = count($bits) > 1
                ? geo_from_names((string) end($bits), (string) ($bits[count($bits) - 2] ?? ''), count($bits) > 2 ? (string) $bits[count($bits) - 3] : '')
                : geo_from_names($text);
        }
        if ($loc === null) {
            continue;
        }
        $matched = geo_codes_valid($loc) && !($loc['admin1_name'] !== null && $loc['admin1_code'] === null) && !($loc['admin2_name'] !== null && $loc['admin2_code'] === null);
        $out[$matched ? 'matched' : 'kept']++;
        member_locations_save((int) $r['member_id'], [$slot => $loc]);
        q('UPDATE member_field_values SET value = ? WHERE member_id = ? AND field_id = ?', [mb_substr(geo_display($loc), 0, 500), $r['member_id'], $field['id']]);
    }
    return $out;
}

/** Second-level area whose name matches in exactly one state of a country: [admin1, admin2] or null. */
function geo_find_admin2(string $cc, string $text): ?array
{
    $hits = [];
    foreach (array_keys(geo_admin1_options($cc)) as $a1) {
        $a2 = geo_match_admin2($cc, (string) $a1, $text);
        if ($a2 !== null) {
            $hits[] = [(string) $a1, $a2];
        }
    }
    return count($hits) === 1 ? $hits[0] : null;
}
