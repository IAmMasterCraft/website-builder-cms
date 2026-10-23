# Gather

A self-hosted community website builder and CMS in plain PHP 8.2+ (no
framework, no Composer at runtime) for charities, diaspora unions, clubs and
alumni bodies. Clean-room build from
`projects/website-builder-re/specs/website-builder.md` (spec only; the study's
`targets/` and `evidence/` are never read).

Status: phases 1 (skeleton), 2 (media, content types, menus), 3 (page builder,
themes, design controls) and 4 (membership, ID cards) are built.
See [BUILD-NOTES.md](BUILD-NOTES.md) for what is done and what is left.

## Requirements

- PHP 8.2+ with `pdo_mysql`, `gd` (with WebP), `fileinfo`, `mbstring`, `intl` (optional, better slugs), `exif` (optional, photo orientation)
- MySQL 8 or MariaDB 10.6+ (utf8mb4)
- Apache with `mod_rewrite` (document root = `public/`), or `php -S` for development

## Run locally

```bash
# Option A: the web installer
php -S 127.0.0.1:8080 -t public public/index.php
# open http://127.0.0.1:8080/install  (DB details -> admin -> theme -> demo content)

# Option B: by hand
cp config.example.php config.php          # fill in the DB section, set 'installed' => true
php bin/migrate
GATHER_ADMIN_PASSWORD='choose-a-long-one' php bin/make-admin you@example.org --name="Your Name"
php bin/demo you@example.org              # optional demo content
php -S 127.0.0.1:8080 -t public public/index.php
```

Emails are written to `storage/mail/*.eml` (open them in any mail client).

## Test

```bash
cp tests/config.test.example.php tests/config.test.php   # point at an EMPTY throwaway database
bin/check                     # lint + unit/DB tests + php -S smoke test
php tests/run.php [filter]    # unit and database tests only
php tests/smoke.php           # end-to-end: installs through /install, then exercises the site
```

The tests drop and recreate every table in the test database.

## Command line

| Command | Does |
|---|---|
| `php bin/migrate [--status]` | Apply pending SQL migrations in `migrations/` |
| `php bin/make-admin <email> [--role=super_admin\|admin] [--perm=content,events,...]` | Create or update an admin |
| `php bin/demo <admin-email>` | Load demo content into an empty site |
| `php bin/cron [--force\|--list]` | Run due scheduled jobs (publishing, cleanup, purge of unverified sign-ups after 14 days); crontab: `* * * * * php /path/bin/cron` |
| `bin/check` | Lint, tests and smoke test |

## Layout

```
public/            web root: index.php (front controller), .htaccess, assets/, uploads/ (WebP variants only)
src/               bootstrap, lib/ (helpers), Core/ (router, sanitiser, mail), Sections/ (page-builder section types),
                   Site/, Account/, Admin/, Install/ handlers
templates/         layouts/, site/, account/, admin/, sections/, install/, email/, partials/  (print only)
themes/<name>/     civic, heritage, harvest, monochrome: theme.json (design defaults), tokens.css, overrides.css, preview.png
public/assets/     css/, js/ (ES modules), img/ (icon + section-thumbnail sprites), fonts/ (OFL, self-hosted), vendor/ (qrcode-generator, MIT)
migrations/        numbered SQL files
storage/           originals/, variants/ (private), mail/, logs/, cache/, backups/  (not web-reachable)
bin/               migrate, make-admin, demo, cron, check
tests/             run.php (runner), *_test.php, smoke.php
```

## Deploy

Target: aaPanel (Apache + PHP-FPM + MySQL); see the suite skill
`php-aapanel-deploy`. Point the site root at `public/`, make `storage/`,
`public/uploads/` and the project folder (for `config.php`) writable by the
web user during install, then add the cron line. Deployment is phase 7.
