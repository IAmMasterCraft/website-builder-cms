<?php
declare(strict_types=1);

/*
 * Addendum C4 data upgrade: members' typed nationality, country, region and
 * city become structured locations (ISO code + GeoNames codes + names).
 * Names are matched case- and accent-insensitively (also "UK", "Nigerian",
 * "Lagos State"...); anything that does not match is kept as typed, without
 * a code, so the member or an admin can pick it from the list later. When
 * anything did not match exactly, the typed values are also kept in
 * members.location_legacy (JSON) and shown on the admin member page.
 * Safe to run on a database that already has location rows (skipped).
 */
return static function (PDO $pdo): void {
    $select = $pdo->prepare(
        'SELECT m.id, m.nationality, m.country, m.region, m.city FROM members m
          WHERE m.id > ? AND (m.nationality IS NOT NULL OR m.country IS NOT NULL OR m.region IS NOT NULL OR m.city IS NOT NULL)
            AND NOT EXISTS (SELECT 1 FROM member_locations l WHERE l.member_id = m.id AND l.slot IN (\'residence\', \'nationality\'))
          ORDER BY m.id LIMIT 500'
    );
    $insert = $pdo->prepare(
        'INSERT INTO member_locations (member_id, slot, country_code, country_name, admin1_code, admin1_name, admin2_code, admin2_name, locality)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $update = $pdo->prepare('UPDATE members SET location_legacy = ?, nationality = ?, country = ?, region = ?, city = ? WHERE id = ?');
    $last = 0;
    do {
        $select->execute([$last]);
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $m) {
            $last = (int) $m['id'];
            $legacy = array_filter(['nationality' => $m['nationality'], 'country' => $m['country'], 'region' => $m['region'], 'city' => $m['city']], static fn ($v): bool => $v !== null && trim((string) $v) !== '');
            $locs = [
                'residence' => geo_from_names((string) $m['country'], (string) $m['region'], '', (string) $m['city']),
                'nationality' => geo_from_names((string) $m['nationality']),
            ];
            foreach ($locs as $slot => $loc) {
                if ($loc !== null) {
                    $insert->execute([$last, $slot, $loc['country_code'], $loc['country_name'], $loc['admin1_code'], $loc['admin1_name'], $loc['admin2_code'], $loc['admin2_name'], $loc['locality']]);
                }
            }
            $snap = member_location_snapshot($locs);
            // Keep the typed values only when something was not matched exactly (no code, or a different spelling).
            $exact = ($m['country'] === null || ($locs['residence']['country_code'] ?? null) !== null && $snap['country'] === $m['country'])
                && ($m['region'] === null || ($locs['residence']['admin1_code'] ?? null) !== null && $snap['region'] === $m['region'])
                && ($m['nationality'] === null || ($locs['nationality']['country_code'] ?? null) !== null && $snap['nationality'] === $m['nationality']);
            $legacy = $exact ? [] : $legacy;
            $update->execute([$legacy ? json_encode($legacy, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
                $snap['nationality'] ?? null, $snap['country'] ?? null, $snap['region'] ?? null, $snap['city'] ?? null, $last]);
        }
    } while (count($rows) === 500);
};
