<?php
declare(strict_types=1);

/** Defaults for every setting; stored values override them. */
function settings_defaults(): array
{
    return [
        'site.name'            => 'Gather',
        'site.tagline'         => 'Bringing our community together',
        'site.timezone'        => 'Europe/London',
        'site.theme'           => 'civic',
        'site.logo_media_id'   => null,
        'site.footer_about'    => 'A volunteer-run community organisation.',
        'contact.email'        => '',
        'contact.phone'        => '',
        'contact.address'      => '',
        'org.charity_number'   => '',
        'social'               => ['facebook' => '', 'x' => '', 'instagram' => '', 'youtube' => '', 'linkedin' => ''],
        'header.topbar'        => true,
        'header.show_auth'     => true,
        'header.sticky'        => true,
        'home.page_id'         => null,
        'cron.last_run'        => null,
    ];
}

/** @param array<string,mixed>|null $reset Pass [] to clear the cache (tests). */
function settings_all(?array $reset = null): array
{
    static $cache = null;
    if ($reset !== null) {
        $cache = null;
    }
    if ($cache === null) {
        $cache = settings_defaults();
        try {
            foreach (q_all('SELECT `key`, `value` FROM settings') as $row) {
                $cache[$row['key']] = json_decode((string) $row['value'], true);
            }
        } catch (PDOException) {
            // Before migrations have run there is no settings table: defaults apply.
        }
    }
    return $cache;
}

function setting(string $key, mixed $default = null): mixed
{
    $all = settings_all();
    return array_key_exists($key, $all) && $all[$key] !== null ? $all[$key] : $default;
}

function setting_set(string $key, mixed $value): void
{
    q(
        'INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
        [$key, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]
    );
    settings_all([]);
}
