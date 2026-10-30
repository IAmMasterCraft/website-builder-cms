<?php
declare(strict_types=1);

// Addendum C4: cascading location fields (C1/C2 are in site_icon_test.php).

function c4_reset(): void
{
    setting_set('members', []);
    setting_set('members.origin', []);
    setting_set('geo.labels', []);
    q('DELETE FROM geo_custom_areas');
    geo_custom_areas(null, true);
    settings_all([]);
}

/** Ekiti State and one of its LGAs, looked up by name in the bundled data. */
function c4_ekiti(): array
{
    $a1 = (string) geo_match_admin1('NG', 'Ekiti');
    $a2 = (string) geo_match_admin2('NG', $a1, 'Ado-Ekiti');
    return [$a1, $a2];
}

// ---- Data ---------------------------------------------------------------------------

test('C4 data: Nigeria has 37 first-level divisions (36 states + FCT) and 774 LGAs', function (): void {
    $ng = geo_country_data('NG');
    same(37, count($ng['a1']));
    same(774, array_sum(array_map('count', $ng['a2'])));
    ok(in_array('Federal Capital Territory', $ng['a1'], true), 'FCT spelled out');
    foreach ($ng['a1'] as $name) {
        ok(!str_ends_with($name, ' State'), "state names are uniform: $name");
    }
    $lagos = geo_admin2_options('NG', (string) geo_match_admin1('NG', 'Lagos'));
    same(20, count($lagos));
    ok(in_array('Lagos Island', $lagos, true) && in_array('Lagos Mainland', $lagos, true) && in_array('Oshodi-Isolo', $lagos, true));
    same(1, count(array_keys($lagos, 'Oshodi-Isolo', true)), 'the GeoNames duplicate is gone');
    ok(in_array('Buruku', geo_admin2_options('NG', (string) geo_match_admin1('NG', 'Benue')), true), 'Benue correction applied');
    same(16, count(geo_admin2_options('NG', c4_ekiti()[0])));
});

test('C4 data: UK nations, countries, phone codes and the time zone table', function (): void {
    same(['ENG' => 'England', 'NIR' => 'Northern Ireland', 'SCT' => 'Scotland', 'WLS' => 'Wales'], geo_admin1_options('GB'));
    ok(count(geo_countries()) >= 245);
    same('Nigeria', geo_country_name('NG'));
    same('+234', geo_index()['countries']['NG'][1]);
    $json = json_decode((string) file_get_contents(BASE . '/public/assets/geo/countries.json'), true);
    same('NG', $json['tz']['Africa/Lagos']);
    same('GB', $json['tz']['Europe/London']);
    same('IN', $json['tz']['Asia/Calcutta'], 'old alias browsers still report');
    same(geo_index()['version'], $json['v'], 'PHP index and JSON files come from the same build');
    foreach ($json['countries'] as [$cc, , , $has]) {
        same($has === 1, is_file(BASE . '/public/assets/geo/' . strtolower($cc) . '.json'), "$cc file matches its flag");
    }
    $names = array_values(geo_countries());
    $sorted = $names;
    (new Collator('en'))->sort($sorted);
    same($sorted, $names, 'alphabetical');
});

test('C4 labels adapt per country and admins can override them', function (): void {
    c4_reset();
    same(['State', 'Local Government Area'], geo_labels('NG'));
    same(['Nation', 'County'], geo_labels('GB'));
    same(['State', 'County'], geo_labels('US'));
    same(['Region', 'Area'], geo_labels('BR'));
    setting_set('geo.labels', ['NG' => ['', 'LGA'], 'BR' => ['State', '']]);
    same(['State', 'LGA'], geo_labels('NG'));
    same(['State', 'Area'], geo_labels('BR'));
    $cfg = geo_config(['levels' => 3, 'l1_suffix' => ' of origin']);
    same('State of origin', geo_level_label($cfg, 1, 'NG'));
    same('LGA', geo_level_label($cfg, 2, 'NG'));
    c4_reset();
});

// ---- Validation ---------------------------------------------------------------------

test('C4 validation: codes must exist; levels without a list become free text', function (): void {
    c4_reset();
    $res = member_location_configs()['residence'];
    [$loc, $e] = geo_validate(['res_country' => 'GB', 'res_state' => 'WLS', 'res_city' => 'Cardiff'], 'res', $res);
    same([], $e);
    same(['GB', 'United Kingdom', 'WLS', 'Wales', 'Cardiff'], [$loc['country_code'], $loc['country_name'], $loc['admin1_code'], $loc['admin1_name'], $loc['locality']]);
    [, $e] = geo_validate(['res_country' => 'GB', 'res_state' => 'XYZ'], 'res', $res);
    ok(isset($e['res_state']), 'unknown state code');
    [, $e] = geo_validate(['res_country' => 'QQ'], 'res', $res);
    ok(isset($e['res_country']), 'unknown country');
    [, $e] = geo_validate([], 'res', $res);
    ok(isset($e['res_country']), 'country of residence is required at sign-up');
    [$loc, $e] = geo_validate([], 'res', member_location_configs(true)['residence']);
    same([[], null], [$e, $loc], 'optional for admins');
    // A country without divisions: the state is typed.
    [$loc, $e] = geo_validate(['res_country' => 'AQ', 'res_state' => 'ignored', 'res_state_text' => 'McMurdo'], 'res', $res);
    same([], $e);
    same([null, 'McMurdo'], [$loc['admin1_code'], $loc['admin1_name']]);
    // Three levels: Nigeria -> Ekiti -> Ado-Ekiti.
    [$a1, $a2] = c4_ekiti();
    $origin = geo_config(['levels' => 3, 'required' => 3, 'l1_suffix' => ' of origin']);
    [$loc, $e] = geo_validate(['origin_country' => 'NG', 'origin_state' => $a1, 'origin_area' => $a2], 'origin', $origin);
    same([], $e);
    same('Ado-Ekiti, Ekiti, Nigeria', geo_display($loc));
    same('Ekiti', geo_display($loc, 'admin1'));
    [, $e] = geo_validate(['origin_country' => 'NG', 'origin_state' => $a1], 'origin', $origin);
    same(['origin_area' => 'Choose your Local Government Area.'], $e);
    $lagos = (string) geo_match_admin1('NG', 'Lagos');
    [, $e] = geo_validate(['origin_country' => 'NG', 'origin_state' => $lagos, 'origin_area' => $a2], 'origin', $origin);
    ok(isset($e['origin_area']), 'an LGA from another state is refused');
    // A fixed country starts at the state.
    [$loc, $e] = geo_validate(['origin_country' => 'GB', 'origin_state' => $a1], 'origin', geo_config(['levels' => 2, 'country' => 'NG']));
    same([], $e);
    same('NG', $loc['country_code'], 'the fixed country wins over posted input');
});

test('C4 profile: locations are returned and their names copied to the snapshot columns', function (): void {
    c4_reset();
    [$d, $e, $locs] = member_profile_validate(member_profile_input(['res_country' => 'NG', 'res_state' => c4_ekiti()[0], 'res_city' => 'Ado', 'nat_country' => 'NG']));
    same([], $e);
    same(['Nigeria', 'Ekiti', 'Ado', 'Nigeria'], [$d['country'], $d['region'], $d['city'], $d['nationality']]);
    same(['residence', 'nationality'], array_keys($locs), 'origin is off by default');
    setting_set('members.origin', ['enabled' => true, 'levels' => 3, 'required' => 2, 'country' => '', 'country_label' => 'Country of origin', 'l1_label' => '', 'l2_label' => 'LGA', 'legend' => 'Origin']);
    settings_all([]);
    [, $e, $locs] = member_profile_validate(member_profile_input(['origin_country' => 'NG']));
    ok(isset($e['origin_state']), 'origin state required by the setting');
    ok(array_key_exists('origin', $locs));
    c4_reset();
});

// ---- Storage, upgrade -----------------------------------------------------------------

test('C4 upgrade: typed nationality/country/region/city become locations without losing anything', function (): void {
    c4_reset();
    $mk = static function (array $cols): int {
        $f = fixture_full_member();
        db_update('members', $f['member_id'], $cols);
        q('DELETE FROM member_locations WHERE member_id = ?', [$f['member_id']]);
        return $f['member_id'];
    };
    $uk = $mk(['country' => 'United Kingdom', 'region' => 'Wales', 'city' => 'Swansea', 'nationality' => 'British']);
    $ng = $mk(['country' => 'nigeria', 'region' => 'Lagos State', 'city' => null, 'nationality' => 'Nigerian']);
    $odd = $mk(['country' => 'Narnia', 'region' => 'Outside the UK', 'city' => 'Cair Paravel', 'nationality' => null]);
    $nation = $mk(['country' => null, 'region' => 'Scotland', 'city' => null, 'nationality' => null]);
    $upgrade = require BASE . '/migrations/021_locations_upgrade.php';
    $upgrade(db());
    $l = member_locations($uk);
    same(['GB', 'WLS', 'Swansea'], [$l['residence']['country_code'], $l['residence']['admin1_code'], $l['residence']['locality']]);
    same('GB', $l['nationality']['country_code'], 'demonym matched');
    $m = q_one('SELECT * FROM members WHERE id = ?', [$uk]);
    same('British', json_decode((string) $m['location_legacy'], true)['nationality'], 'a nationality typed as a demonym is kept as typed');
    $exact = $mk(['country' => 'United Kingdom', 'region' => 'England', 'city' => 'Leeds', 'nationality' => null]);
    $upgrade(db());
    same(null, q_val('SELECT location_legacy FROM members WHERE id = ?', [$exact]), 'exact matches keep no legacy copy');
    $l = member_locations($ng);
    same(['NG', 'Lagos', 'NG'], [$l['residence']['country_code'], $l['residence']['admin1_name'], $l['nationality']['country_code']]);
    $m = q_one('SELECT * FROM members WHERE id = ?', [$ng]);
    same(['Nigeria', 'Lagos'], [$m['country'], $m['region']], 'snapshot uses the list spelling');
    $legacy = json_decode((string) $m['location_legacy'], true);
    ksort($legacy);
    same(['country' => 'nigeria', 'nationality' => 'Nigerian', 'region' => 'Lagos State'], $legacy, 'typed values kept');
    $l = member_locations($odd);
    same([null, 'Narnia', null, 'Outside the UK', 'Cair Paravel'], [$l['residence']['country_code'], $l['residence']['country_name'], $l['residence']['admin1_code'], $l['residence']['admin1_name'], $l['residence']['locality']], 'unmatched text kept without codes');
    same('Narnia', q_val('SELECT country FROM members WHERE id = ?', [$odd]));
    same(['GB', 'SCT'], [member_locations($nation)['residence']['country_code'], member_locations($nation)['residence']['admin1_code']], 'a UK nation without a country');
    // Running it again changes nothing.
    $before = q_all('SELECT * FROM member_locations ORDER BY member_id, slot');
    $upgrade(db());
    same($before, q_all('SELECT * FROM member_locations ORDER BY member_id, slot'));
});

test('C4 matching: country names, aliases and nationalities', function (): void {
    same('GB', geo_match_country('UK'));
    same('GB', geo_match_country('great britain'));
    same('NG', geo_match_country('Nigerian'));
    same('CI', geo_match_country('Côte d’Ivoire'));
    same('US', geo_match_country('United States'));
    same('DE', geo_match_country('de'));
    same(null, geo_match_country('Atlantis'));
    same(c4_ekiti()[0], geo_match_admin1('NG', 'ekiti state'));
    same('ENG', geo_match_admin1('GB', 'England'));
});

// ---- Admin-added areas ------------------------------------------------------------------

test('C4 location lists: admins add a missing local area; it validates and cannot be removed while used', function (): void {
    c4_reset();
    [$a1] = c4_ekiti();
    $id = geo_custom_area_add('NG', $a1, 'Test Development Area', null);
    $code = GEO_CUSTOM_PREFIX . $id;
    same('Test Development Area', geo_admin2_options('NG', $a1)[$code]);
    throws(InvalidArgumentException::class, static fn () => geo_custom_area_add('NG', $a1, 'test development area', null));
    throws(InvalidArgumentException::class, static fn () => geo_custom_area_add('NG', $a1, 'Ado-Ekiti', null));
    throws(InvalidArgumentException::class, static fn () => geo_custom_area_add('NG', 'ZZ', 'X', null));
    [$loc, $e] = geo_validate(['o_country' => 'NG', 'o_state' => $a1, 'o_area' => $code], 'o', geo_config(['levels' => 3]));
    same([], $e);
    $f = fixture_full_member();
    member_locations_save($f['member_id'], ['origin' => $loc]);
    same(1, geo_custom_area_usage($id));
    same([['x' . $id, 'Test Development Area']], ((array) geo_custom_payload()['areas'])['NG'][$a1]);
    member_locations_save($f['member_id'], ['origin' => null]);
    same(0, geo_custom_area_usage($id));
    c4_reset();
});

// ---- Filters, audiences, cards, CSV --------------------------------------------------------

test('C4 audiences and filters: "members whose origin state is Ekiti"', function (): void {
    c4_reset();
    [$a1, $a2] = c4_ekiti();
    $in = fixture_full_member([], ['newsletters' => true]);
    $other = fixture_full_member([], ['newsletters' => true]);
    member_locations_save($in['member_id'], ['origin' => geo_from_names('Nigeria', 'Ekiti', 'Ado-Ekiti')]);
    member_locations_save($other['member_id'], ['origin' => geo_from_names('Nigeria', 'Lagos')]);
    $value = geo_filter_encode('origin', 'NG', $a1);
    [$aud, $err] = audience_validate(['audience_type' => 'location', 'audience_loc_slot' => 'origin', 'aud_loc_country' => 'NG', 'aud_loc_state' => $a1, 'aud_loc_area' => '']);
    same([], $err);
    same($value, $aud['value']);
    $emails = array_column(audience_resolve($aud)['recipients'], 'email');
    ok(in_array($in['email'], $emails, true) && !in_array($other['email'], $emails, true));
    same('Members by Origin: Ekiti (Nigeria)', audience_label($aud));
    ok(audience_includes_user(['type' => 'location', 'value' => geo_filter_encode('origin', 'NG', $a1, $a2), 'field_id' => 0], $in['user_id']), 'down to the LGA');
    [, $err] = audience_validate(['audience_type' => 'location', 'audience_loc_slot' => 'origin', 'aud_loc_country' => 'NG', 'aud_loc_state' => 'ZZ']);
    ok(isset($err['audience']));
    [, $err] = audience_validate(['audience_type' => 'location', 'audience_loc_slot' => 'evil', 'aud_loc_country' => 'NG']);
    ok(isset($err['audience']), 'slot whitelist');
    [$where, $params] = members_filter_sql(['loc' => $value]);
    $ids = array_map('intval', array_column(q_all("SELECT m.id FROM members m JOIN users u ON u.id = m.user_id WHERE $where", $params), 'id'));
    same([$in['member_id']], $ids);
    [$where] = members_filter_sql(['loc' => "origin|NG|' OR 1=1 --|"]);
    not_contains('OR 1=1', $where, 'invalid filters are ignored, never put in SQL');
});

test('C4 ID card: chosen location lines (any level) and location custom fields', function (): void {
    c4_reset();
    setting_set('members.origin', ['enabled' => true, 'levels' => 3]);
    setting_set('members', ['card_locations' => ['origin_admin1', 'res_country']]);
    settings_all([]);
    $f = fixture_full_member();
    member_locations_save($f['member_id'], ['origin' => geo_from_names('Nigeria', 'Ekiti', 'Ado-Ekiti'), 'residence' => geo_from_names('United Kingdom', 'England')]);
    $card = card_data(q_one('SELECT * FROM members WHERE id = ?', [$f['member_id']]));
    same([['label' => 'State of origin', 'value' => 'Ekiti'], ['label' => 'Country of residence', 'value' => 'United Kingdom']], $card['extra']);
    c4_reset();
});

test('C4 CSV: export has codes and names; import accepts either and keeps levels the file leaves out', function (): void {
    c4_reset();
    $r = fixture_signup('geo-' . bin2hex(random_bytes(3)) . '@example.test', ['res_country' => 'NG', 'res_state' => c4_ekiti()[0], 'res_city' => 'Ado', 'nat_country' => 'GH']);
    account_verify($r['token']);
    $rows = members_csv_rows(['q' => 'geo-']);
    $row = array_combine($rows[0], $rows[1]);
    same(['NG', 'Nigeria', c4_ekiti()[0], 'Ekiti', 'Ado', 'GH', 'Ghana'], [$row['country_code'], $row['country'], $row['region_code'], $row['region'], $row['city'], $row['nationality_code'], $row['nationality']]);
    $email = (string) q_val('SELECT email FROM users WHERE id = ?', [$r['user_id']]);
    // Names only (older spreadsheets), and an update that only has a city column.
    $rep = members_import_csv(fixture_file("email,first_name,last_name,country,region\nnew-geo@example.test,Ngozi,New,Nigeria,Lagos State\nbad-geo@example.test,Bad,Geo,Nigeria,Atlantis\n", 'csv'));
    same(1, $rep['created'], json_encode($rep));
    contains('region:', implode(' ', $rep['errors']));
    $loc = member_locations((int) q_val('SELECT m.id FROM members m JOIN users u ON u.id = m.user_id WHERE u.email = ?', ['new-geo@example.test']))['residence'];
    same(['NG', 'Lagos'], [$loc['country_code'], $loc['admin1_name']]);
    $rep = members_import_csv(fixture_file("email,first_name,last_name,city\n$email,Ngozi,Adeyemi,Ikere\n", 'csv'), true);
    same(1, $rep['updated'], json_encode($rep));
    $loc = member_locations((int) member_for_user($r['user_id'])['id'])['residence'];
    same(['NG', c4_ekiti()[0], 'Ikere'], [$loc['country_code'], $loc['admin1_code'], $loc['locality']], 'country and state kept, city changed');
    same('GH', member_locations((int) member_for_user($r['user_id'])['id'])['nationality']['country_code'], 'nationality untouched');
});

test('C4 custom fields: Location type validates the cascade; a choice field converts to it', function (): void {
    c4_reset();
    q('DELETE FROM custom_fields');
    $id = db_insert('custom_fields', ['field_key' => 'home_state', 'label' => 'Home state', 'type' => 'select', 'options' => json_encode(['Ekiti', 'Lagos State', 'Somewhere else']), 'visible_to_members' => 1]);
    $a = fixture_full_member();
    $b = fixture_full_member();
    $c = fixture_full_member();
    custom_values_save('member_field_values', 'member_id', $a['member_id'], [$id => 'Ekiti']);
    custom_values_save('member_field_values', 'member_id', $b['member_id'], [$id => 'Lagos State']);
    custom_values_save('member_field_values', 'member_id', $c['member_id'], [$id => 'Somewhere else']);
    db_update('custom_fields', $id, ['type' => 'location', 'field_config' => json_encode(['levels' => 3, 'country' => 'NG']), 'options' => null]);
    $field = q_one('SELECT * FROM custom_fields WHERE id = ?', [$id]);
    same(['matched' => 2, 'kept' => 1], custom_field_location_convert($field));
    same(c4_ekiti()[0], member_locations($a['member_id'])['cf' . $id]['admin1_code']);
    same('Lagos, Nigeria', custom_values_for_member($b['member_id'])[$id]);
    same([null, 'Somewhere else'], [member_locations($c['member_id'])['cf' . $id]['admin1_code'], member_locations($c['member_id'])['cf' . $id]['admin1_name']], 'kept as typed');
    $fields = custom_fields('member');
    [$vals, $e, $locs] = custom_fields_validate($fields, ['cf_home_state_state' => c4_ekiti()[0], 'cf_home_state_area' => c4_ekiti()[1]]);
    same([], $e);
    same('Ado-Ekiti, Ekiti, Nigeria', $vals[$id]);
    same('NG', $locs['cf' . $id]['country_code']);
    [, $e] = custom_fields_validate($fields, ['cf_home_state_state' => 'nope']);
    ok(isset($e['cf_home_state_state']));
    q('DELETE FROM custom_fields');
});

test('C4 country hint: CDN headers only, validated', function (): void {
    same('NG', geo_header_country(['HTTP_CF_IPCOUNTRY' => 'ng']));
    same('GB', geo_header_country(['HTTP_CLOUDFRONT_VIEWER_COUNTRY' => 'GB']));
    same(null, geo_header_country(['HTTP_CF_IPCOUNTRY' => 'XX']));
    same(null, geo_header_country(['HTTP_CF_IPCOUNTRY' => 'T1']));
    same(null, geo_header_country([]));
});

test('C4 schema: PHP migrations run in order and the upgrade file is listed', function (): void {
    $files = migration_files();
    ok(in_array('020_locations.sql', $files, true) && in_array('021_locations_upgrade.php', $files, true));
    ok(array_search('020_locations.sql', $files, true) < array_search('021_locations_upgrade.php', $files, true));
    same('enum(\'text\',\'select\',\'date\',\'checkbox\',\'location\')', strtolower((string) q_one("SHOW COLUMNS FROM custom_fields LIKE 'type'")['Type']));
});
