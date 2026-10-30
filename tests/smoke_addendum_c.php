<?php
declare(strict_types=1);

// Addendum C smoke checks through php -S: C1 site icons on every page type,
// C2 the organisation's name in the admin, C4 cascading location fields.
// Included by tests/smoke.php after smoke_addendum_b.php (uses Browser,
// check(), status_is(), guard_tokens(), $admin (super admin), $visitor,
// $impMember (an active member), $impMid, $base, login_as(), meta_csrf()).

$siteName = (string) q_val("SELECT JSON_UNQUOTE(`value`) FROM settings WHERE `key` = 'site.name'") ?: 'Gather';

// ---- C1: icons -----------------------------------------------------------------------
$home = $visitor->get('/')['body'];
check('C1: public pages link the generated favicon set and manifest', (bool) preg_match('#<link rel="icon" href="/icons/([a-f0-9]{16})/favicon\.ico" sizes="48x48">#', $home, $sm)
    && str_contains($home, 'rel="apple-touch-icon" href="/icons/' . ($sm[1] ?? 'x') . '/apple-touch-icon.png"') && str_contains($home, '<link rel="manifest" href="/site.webmanifest?v='));
$sig = $sm[1] ?? '';
$pages = ['/login' => $visitor, '/help' => $visitor, '/no-such-page-c1' => $visitor, '/admin' => $admin, '/admin/help' => $admin, '/account' => $impMember, '/account/card/print' => $impMember, '/join' => $visitor];
$missing = [];
foreach ($pages as $p => $b) {
    if (!str_contains($b->get($p)['body'], '/icons/' . $sig . '/favicon.ico')) {
        $missing[] = $p;
    }
}
check('C1: the icon is on site, member, admin, help, error and card print pages', $missing === [] && $sig !== '', implode(', ', $missing));
$r = $visitor->get('/icons/' . $sig . '/icon-192.png');
check('C1: icon files are PNGs with a long, immutable cache for the current version', $r['status'] === 200 && $r['type'] === 'image/png' && str_contains($r['headers'], 'max-age=31536000, immutable') && getimagesizefromstring($r['body'])[0] === 192);
$r = $visitor->get('/icons/0000000000000000/icon-192.png');
check('C1: an old version still gets the current icon, briefly cached', $r['status'] === 200 && str_contains($r['headers'], 'max-age=3600'));
check('C1: unknown icon names are 404', $visitor->get('/icons/' . $sig . '/config.php')['status'] === 404 && $visitor->get('/icons/' . $sig . '/..%2Fconfig.php')['status'] === 404);
$r = $visitor->get('/favicon.ico');
check('C1: /favicon.ico is the generated ICO (16/32/48)', $r['status'] === 200 && $r['type'] === 'image/x-icon' && substr($r['body'], 0, 4) === "\x00\x00\x01\x00" && unpack('v', substr($r['body'], 4, 2))[1] === 3);
check('C1: /apple-touch-icon.png is 180 px', getimagesizefromstring($visitor->get('/apple-touch-icon.png')['body'])[0] === 180);
$r = $visitor->get('/site.webmanifest');
$manifest = json_decode($r['body'], true);
check('C1: site.webmanifest has the site name, brand colour and icons', str_contains($r['type'], 'application/manifest+json') && ($manifest['name'] ?? '') === $siteName
    && preg_match('/^#[0-9a-f]{6}$/', (string) ($manifest['theme_color'] ?? '')) === 1 && count($manifest['icons'] ?? []) === 2);
// Upload: too small refused, a big square accepted (new version), then back to the letter tile.
$tmpC = sys_get_temp_dir() . '/gather-c1-' . bin2hex(random_bytes(3));
mkdir($tmpC);
foreach ([300 => 'small', 640 => 'big'] as $px => $name) {
    $im = imagecreatetruecolor($px, $px);
    imagefilledrectangle($im, 0, 0, $px, $px, imagecolorallocate($im, 20, 140, 90));
    imagepng($im, "$tmpC/$name.png");
}
$settingsForm = static function () use ($admin): array {
    $html = $admin->get('/admin/settings')['body'];
    preg_match_all('/<input([^>]+)>/', $html, $m, PREG_SET_ORDER);
    $f = [];
    foreach ($m as [, $attrs]) {
        if (!preg_match('/name="([a-z_0-9]+)"/', $attrs, $nm) || !preg_match('/value="([^"]*)"/', $attrs, $vm)
            || (preg_match('/type="(checkbox|radio)"/', $attrs) && !str_contains($attrs, ' checked')) || str_contains($attrs, 'type="file"')) {
            continue;
        }
        $f[$nm[1]] = html_entity_decode($vm[1], ENT_QUOTES);
    }
    preg_match_all('/<select[^>]+name="([a-z_]+)"[^>]*>(.*?)<\/select>/s', $html, $sm2, PREG_SET_ORDER);
    foreach ($sm2 as [, $n, $opts]) {
        $f[$n] = preg_match('/<option value="([^"]*)" selected/', $opts, $om) ? html_entity_decode($om[1], ENT_QUOTES) : '';
    }
    preg_match_all('/<textarea[^>]+name="([a-z_]+)"[^>]*>(.*?)<\/textarea>/s', $html, $tm, PREG_SET_ORDER);
    foreach ($tm as [, $n, $v]) {
        $f[$n] = html_entity_decode($v, ENT_QUOTES);
    }
    $f['site_name'] = $f['site_name'] ?? 'Smoke site';
    unset($f['favicon_remove'], $f['logo_remove']);
    return $f;
};
$f = $settingsForm();
$r = $admin->post('/admin/settings', $f + ['_csrf' => meta_csrf($admin, '/admin/settings'), 'favicon_upload' => new CURLFile("$tmpC/small.png", 'image/png', 'small.png')]);
check('C1: an icon under 512 × 512 is refused with a clear message', $r['status'] === 422 && str_contains($r['body'], 'at least 512'));
$r = $admin->post('/admin/settings', $f + ['_csrf' => meta_csrf($admin, '/admin/settings'), 'favicon_upload' => new CURLFile("$tmpC/big.png", 'image/png', 'big.png')]);
preg_match('#/icons/([a-f0-9]{16})/favicon\.ico#', $visitor->get('/')['body'], $sm3);
check('C1: uploading an icon makes a new versioned set used everywhere (audited)', $r['status'] === 303 && ($sm3[1] ?? $sig) !== $sig
    && str_contains((string) q_val("SELECT summary FROM audit_log WHERE action = 'update' AND item_type = 'settings' ORDER BY id DESC LIMIT 1"), 'site icon uploaded'),
    $r['status'] . ' ' . implode(' | ', array_map('strip_tags', preg_match_all('/<p class="error"[^>]*>(.*?)<\/p>/', $r['body'], $em) ? $em[1] : [])));
$r = $admin->post('/admin/settings', $settingsForm() + ['_csrf' => meta_csrf($admin, '/admin/settings'), 'favicon_remove' => '1']);
preg_match('#/icons/([a-f0-9]{16})/favicon\.ico#', $visitor->get('/')['body'], $sm4);
check('C1: "use the letter tile instead" goes back to the generated tile', $r['status'] === 303 && ($sm4[1] ?? '') === $sig);
array_map('unlink', glob("$tmpC/*") ?: []);
rmdir($tmpC);

// ---- C2: organisation name in the admin -------------------------------------------------
$h = $admin->get('/admin/members')['body'];
check('C2: the sidebar header shows the site name and its icon tile (full name as tooltip)', str_contains($h, '<span class="logo-text">' . e($siteName) . '</span>') && str_contains($h, 'title="' . e($siteName) . ' admin"')
    && (bool) preg_match('#<img class="logo-mark logo-img" src="/icons/[a-f0-9]{16}/icon-192\.png"#', $h));
check('C2: browser titles use the site name; "Gather" only in the footer credit', str_contains($h, '<title>Members · Admin · ' . e($siteName) . '</title>') && str_contains($h, 'Powered by <a href="/admin/help">Gather</a>')
    && !str_contains($h, '<span class="logo-text">Gather</span>'));

// ---- C4: sign-up cascade -------------------------------------------------------------------
$joinC = new Browser($base);
$r = $joinC->get('/join', ['CF-IPCountry: NG']);
check('C4: sign-up has Country of residence -> Region/State -> City and Nationality as location fields', str_contains($r['body'], 'name="res_country"') && str_contains($r['body'], 'name="res_state"')
    && str_contains($r['body'], 'name="res_city"') && str_contains($r['body'], 'name="nat_country"') && str_contains($r['body'], 'assets/js/location.js'));
check('C4: a CDN country header is offered under "Suggested" (never pre-selected by the server)', (bool) preg_match('#<optgroup label="Suggested" data-loc-suggested><option value="NG">Nigeria</option></optgroup>#', $r['body'])
    && !preg_match('#<option value="NG" selected>#', $r['body']) && str_contains($r['body'], 'data-geo-header="NG"'));
check('C4: without a country the next level waits (hidden) and "Update the lists" is offered', (bool) preg_match('#data-loc-level="1" hidden#', $r['body']) && str_contains($r['body'], 'name="_loc_refresh"'));
$t = guard_tokens($joinC, '/join');
$r = $joinC->post('/join', $t + ['first_name' => 'Ade', 'res_country' => 'NG', '_loc_refresh' => 'res']);
$ekiti = (string) geo_match_admin1('NG', 'Ekiti');
check('C4: without JavaScript, "Update the lists" re-shows the form with Nigeria\'s states (labelled State), nothing saved', $r['status'] === 200 && str_contains($r['body'], '<option value="' . $ekiti . '">Ekiti</option>')
    && str_contains($r['body'], '<span data-loc-label-text>State</span>') && str_contains($r['body'], 'value="Ade"') && !str_contains($r['body'], 'class="flash error"'));
// Origin block on: Country -> State -> LGA.
$msForm = ['prefix' => 'HCA', 'group_prefix' => 'HCG', 'number_format' => '{PREFIX}-{YY}-{0000}', 'card_months' => '12', 'free_first_n' => '0', 'terms_url' => '/privacy-policy', 'signatory' => 'Secretary',
    'genders' => "Female\nMale", 'types' => "Standard\nLife", 'origin_enabled' => '1', 'origin_legend' => 'Where you are from', 'origin_levels' => '3', 'origin_required' => '2', 'origin_country' => '',
    'origin_country_label' => 'Country of origin', 'origin_l1_label' => '', 'origin_l2_label' => 'LGA', 'card_location_1' => 'origin_admin1', 'card_location_2' => ''];
$r = $admin->submit('/admin/settings/membership', '/admin/settings/membership', ['origin_levels' => '9'] + $msForm);
check('C4: membership settings validate the origin block', $r['status'] === 422 && str_contains($r['body'], 'Choose how many levels'));
$r = $admin->submit('/admin/settings/membership', '/admin/settings/membership', $msForm);
check('C4: the super admin switches on the origin block with its own labels (audited)', $r['status'] === 303 && str_contains($joinC->get('/join')['body'], 'name="origin_country"')
    && str_contains($joinC->get('/join')['body'], '<legend>Where you are from</legend>'));
$t = guard_tokens($joinC, '/join');
$r = $joinC->post('/join', $t + ['origin_country' => 'NG', 'origin_state' => $ekiti, '_loc_refresh' => 'origin']);
$ado = (string) geo_match_admin2('NG', $ekiti, 'Ado-Ekiti');
check('C4: choosing a state lists its local areas (label from the setting)', str_contains($r['body'], '<option value="' . $ado . '">Ado-Ekiti</option>') && str_contains($r['body'], '<span data-loc-label-text>LGA</span>')
    && str_contains($r['body'], '<span data-loc-label-text>State of origin</span>'));
sleep(3);
$photoC = sys_get_temp_dir() . '/c4-photo-' . bin2hex(random_bytes(3)) . '.jpg';
$im = imagecreatetruecolor(600, 600);
imagefilledrectangle($im, 0, 0, 600, 600, imagecolorallocate($im, 90, 60, 160));
imagejpeg($im, $photoC);
$joinGeo = ['first_name' => 'Bisi', 'last_name' => 'Origin', 'birth_day' => '5', 'birth_month' => '6', 'email' => 'bisi.origin@example.test', 'password' => 'bisi-password-12', 'password_confirm' => 'bisi-password-12', 'consent' => '1',
    'res_country' => 'GB', 'res_state' => 'ENG', 'res_city' => 'Leeds', 'nat_country' => 'NG', 'origin_country' => 'NG', 'origin_state' => $ekiti, 'origin_area' => $ado];
$r = $joinC->post('/join', ['res_state' => 'XYZ'] + $joinGeo + $t + ['photo' => new CURLFile($photoC, 'image/jpeg', 'me.jpg')]);
check('C4: a region code that is not in the list is refused (422)', $r['status'] === 422 && str_contains($r['body'], 'Choose a nation from the list'));
$t = guard_tokens($joinC, '/join');
sleep(3);
$r = $joinC->post('/join', $joinGeo + $t + ['photo' => new CURLFile($photoC, 'image/jpeg', 'me.jpg')]);
$bisi = q_one("SELECT m.* FROM members m JOIN users u ON u.id = m.user_id WHERE u.email = 'bisi.origin@example.test'");
$locs = $bisi !== null ? member_locations((int) $bisi['id']) : [];
check('C4: sign-up stores ISO code + GeoNames codes + names for residence, nationality and origin', $r['status'] === 303 && $bisi !== null
    && ($locs['residence']['admin1_code'] ?? '') === 'ENG' && ($locs['residence']['locality'] ?? '') === 'Leeds' && ($locs['nationality']['country_code'] ?? '') === 'NG'
    && ($locs['origin']['admin2_code'] ?? '') === $ado && ($locs['origin']['admin2_name'] ?? '') === 'Ado-Ekiti' && $bisi['region'] === 'England' && $bisi['nationality'] === 'Nigeria');
unlink($photoC);

// ---- C4: profile, admin edit, filters, audiences, location lists ----------------------------
$r = $impMember->submit('/account/profile', '/account/profile', ['first_name' => 'Imani', 'last_name' => 'View', 'birth_day' => '3', 'birth_month' => '4', 'res_country' => 'NG', '_loc_refresh' => 'res']);
$prof = $impMember->get('/account/profile')['body'];
check('C4: profile "Update the lists" keeps the typed values and shows the states', $r['status'] === 303 && str_contains($prof, '<option value="NG" selected>Nigeria</option>') && str_contains($prof, '>Ekiti</option>'));
$r = $impMember->submit('/account/profile', '/account/profile', ['first_name' => 'Imani', 'last_name' => 'View', 'birth_day' => '3', 'birth_month' => '4', 'res_country' => 'NG', 'res_state' => $ekiti, 'origin_country' => 'NG', 'origin_state' => $ekiti, 'origin_area' => $ado]);
check('C4: members save their locations on the profile', $r['status'] === 303 && (member_locations($impMid)['origin']['admin2_code'] ?? '') === $ado);
$r = $admin->get('/admin/members?loc_slot=origin&lf_country=NG&lf_state=' . rawurlencode($ekiti));
check('C4: admins filter members by origin state', $r['status'] === 200 && str_contains($r['body'], 'Bisi Origin') && str_contains($r['body'], 'Imani View') && str_contains($r['body'], 'Filter by location: Origin: Ekiti (Nigeria)'));
$r = $admin->get('/admin/members?loc_slot=origin&lf_country=NG&lf_state=' . rawurlencode((string) geo_match_admin1('NG', 'Lagos')));
check('C4: ... and a different state excludes them', $r['status'] === 200 && !str_contains($r['body'], 'Bisi Origin'));
$r = $admin->get('/admin/members.csv?loc=' . rawurlencode('origin|NG|' . $ekiti . '|'));
check('C4: CSV export has the location columns and follows the filter', str_contains($r['body'], 'origin_state_code') && str_contains($r['body'], 'bisi.origin@example.test') && str_contains($r['body'], 'Ado-Ekiti'));
$bisiId = (int) $bisi['id'];
$edit = $admin->get('/admin/members/' . $bisiId)['body'];
check('C4: admin member page shows the saved cascade', str_contains($edit, '<option value="' . $ado . '" selected>Ado-Ekiti</option>') && str_contains($edit, '<option value="ENG" selected>England</option>'));
$r = $admin->submit('/admin/members/' . $bisiId, '/admin/members/' . $bisiId, ['email' => 'bisi.origin@example.test', 'first_name' => 'Bisi', 'last_name' => 'Origin', 'birth_day' => '5', 'birth_month' => '6', 'membership_type' => 'Standard',
    'res_country' => 'GB', 'res_state' => 'SCT', 'res_city' => 'Glasgow', 'origin_country' => 'NG', 'origin_state' => $ekiti, 'origin_area' => $ado]);
check('C4: admin edit updates the location and the snapshot', $r['status'] === 303 && q_val('SELECT region FROM members WHERE id = ?', [$bisiId]) === 'Scotland' && (member_locations($bisiId)['residence']['locality'] ?? '') === 'Glasgow');
$admin->submit('/admin/campaigns', '/admin/campaigns', ['subject' => 'Ekiti picnic']);
$campId = (int) q_val("SELECT id FROM campaigns WHERE subject = 'Ekiti picnic' ORDER BY id DESC LIMIT 1");
$admin->submit('/admin/campaigns/' . $campId, '/admin/campaigns/' . $campId, ['subject' => 'Ekiti picnic', 'blocks[0][type]' => 'heading', 'blocks[0][text]' => 'Picnic', 'blocks[0][size]' => 'large',
    'audience_type' => 'location', 'audience_loc_slot' => 'origin', 'aud_loc_country' => 'NG', 'aud_loc_state' => $ekiti, 'op' => 'save']);
$camp = q_one('SELECT * FROM campaigns WHERE id = ?', [$campId]);
check('C4: a campaign audience "members whose origin state is Ekiti"', $camp !== null && audience_decode((string) $camp['audience'])['value'] === 'origin|NG|' . $ekiti . '|'
    && str_contains($admin->get('/admin/campaigns/' . (int) ($camp['id'] ?? 0))['body'], '<option value="' . $ekiti . '" selected>Ekiti</option>'));
// Location lists screen.
status_is($admin, '/admin/locations?country=NG', 200, 'Federal Capital Territory');
$r = $admin->submit('/admin/locations?country=NG', '/admin/locations/areas', ['country' => 'NG', 'state' => $ekiti, 'name' => 'Smoke Development Area']);
$areaId = (int) q_val("SELECT id FROM geo_custom_areas WHERE name = 'Smoke Development Area'");
$custom = json_decode($visitor->get('/geo/custom.json?v=1')['body'], true);
check('C4: admins add a missing local area; it is served to the form and offered after a refresh', $r['status'] === 303 && $areaId > 0 && ($custom['areas']['NG'][$ekiti][0][1] ?? '') === 'Smoke Development Area'
    && str_contains($joinC->post('/join', guard_tokens($joinC, '/join') + ['origin_country' => 'NG', 'origin_state' => $ekiti, '_loc_refresh' => 'origin'])['body'], '>Smoke Development Area</option>'));
$r = $admin->submit('/admin/locations?country=NG', '/admin/locations/labels', ['country' => 'NG', 'l1_label' => '', 'l2_label' => 'LGA']);
check('C4: per-country labels can be renamed (audited)', $r['status'] === 303 && str_contains($admin->get('/admin/locations?country=NG')['body'], 'State and LGA'));
$admin->submit('/admin/locations?country=NG', '/admin/locations/labels', ['country' => 'NG', 'l1_label' => '', 'l2_label' => '']);
$r = $admin->submit('/admin/locations?country=NG', '/admin/locations/areas/' . $areaId . '/delete', []);
check('C4: an unused added area can be removed', $r['status'] === 303 && q_val('SELECT id FROM geo_custom_areas WHERE id = ?', [$areaId]) === null);
// Custom field of type Location.
$r = $admin->submit('/admin/custom-fields/new', '/admin/custom-fields', ['label' => 'Home town', 'type' => 'location', 'loc_levels' => '3', 'loc_country' => 'NG', 'visible_to_members' => '1', 'applies_to' => 'member', 'sort' => '5']);
check('C4: custom fields gain a Location type (fixed country, three levels)', $r['status'] === 303 && str_contains($joinC->get('/join')['body'], 'name="cf_home_town_state"'));
$r = $admin->submit('/admin/custom-fields/new', '/admin/custom-fields', ['label' => 'Group place', 'type' => 'location', 'loc_levels' => '2', 'applies_to' => 'group', 'sort' => '5']);
check('C4: Location fields are for members only', $r['status'] === 422);
// Turn the origin block off again so later checks see the default form.
$admin->submit('/admin/settings/membership', '/admin/settings/membership', ['origin_enabled' => '', 'card_location_1' => ''] + $msForm);
