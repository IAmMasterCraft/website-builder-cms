# Gather

A self-hosted community website builder and CMS in plain PHP 8.2+ (no
framework, no Composer at runtime) for charities, diaspora unions, clubs and
alumni bodies. Clean-room build from
`projects/website-builder-re/specs/website-builder.md` (spec only; the study's
`targets/` and `evidence/` are never read).

Status: phases 1 (skeleton), 2 (media, content types, menus), 3 (page builder,
themes, design controls), 4 (membership, ID cards), 5 (communications, birthdays,
jobs, likes and comments), 6 (online radio) and 7 (users and roles, audit log,
backups, system health, hardening, accessibility pass, deployment kit) are built.
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

Emails are written to `storage/mail/*.eml` (open them in any mail client) until
the super admin switches Email settings to SMTP (vendored PHPMailer). Campaigns,
notice copies and birthday emails are sent by cron, so run `php bin/cron`
every minute (or by hand while testing).

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
| `php bin/cron [--force\|--list]` | Run due scheduled jobs: publishing, cleanup, purge of unverified sign-ups (14 days), scheduled campaigns, the email queue (per-minute limit), birthdays (daily at the configured time), job expiry; crontab: `* * * * * php /path/bin/cron` |
| `php bin/backup [--uploads]` | Write a database backup (and optionally an uploads zip) into `storage/backups` |
| `php bin/restore <file.sql.gz> [--yes]` | Replace every table with a Gather backup (asks for the database name) |
| `bin/check` | Lint, secrets check, tests and smoke test |
| `bin/package [ref]` | Build `dist/gather-<date>-<commit>.tar.gz` for upload (no config, storage, tests or dev tools) |

## Layout

```
public/            web root: index.php (front controller), .htaccess, assets/, uploads/ (WebP variants only)
src/               bootstrap, lib/ (helpers), Core/ (router, sanitiser, mail), Sections/ (page-builder section types),
                   Site/, Account/, Admin/, Install/ handlers
templates/         layouts/, site/, account/, admin/, sections/, install/, email/, partials/  (print only)
themes/<name>/     civic, heritage, harvest, monochrome: theme.json (design defaults), tokens.css, overrides.css, preview.png
public/assets/     css/, js/ (ES modules), img/ (icon + section-thumbnail sprites), fonts/ (OFL, self-hosted), vendor/ (qrcode-generator, MIT)
vendor/phpmailer/  PHPMailer 6.9.3 (LGPL 2.1, unmodified src/ files + LICENSE), loaded by src/Core/Mail/SmtpMailer.php
demo-assets/       original demo radio media (synthesised music, speech, generated video) + the dev script that made them
migrations/        numbered SQL files
storage/           originals/, variants/ (private), mail/, logs/, cache/, backups/  (not web-reachable)
bin/               migrate, make-admin, demo, cron, backup, restore, check, package
dev-tools/         dev only (never deployed): axe-core + Lighthouse (npm install here), audit.mjs, touch-reorder.mjs
tests/             run.php (runner), *_test.php, smoke.php
```

## Deploy

Target: the aaPanel server (Apache + PHP-FPM + MySQL). Follow
[DEPLOY.md](DEPLOY.md): `bin/check`, commit, `bin/package`, create the site
with running directory `/public`, upload and unpack, enable HTTPS, run
`/install`, add the one cron line, then check Admin -> System health.

## Accessibility audit (dev only)

```bash
cd dev-tools && npm install && cd ..        # axe-core and Lighthouse, local to this folder
php -S 127.0.0.1:8080 -t public public/index.php &
PUBLIC_PAGES="/,/join" node dev-tools/audit.mjs http://127.0.0.1:8080 admin@email adminpass member@email memberpass
```
