# Local development

Run Gather on your own computer with PHP's built-in web server. You do not
need Apache or Nginx.

## What you need

- PHP 8.2+ on the command line with the [required extensions](requirements.md#php-extensions)
  (macOS: `brew install php`; Ubuntu: the `php8.3-*` packages from
  [the VPS guide](install-vps.md#2-install-php-the-database-and-tools); Windows:
  WSL with Ubuntu is easiest).
- MySQL 8 or MariaDB 10.6+ running locally (macOS: `brew install mariadb`
  then `brew services start mariadb`).
- An empty database and a user for it:

  ```sql
  CREATE DATABASE gather_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'gather'@'localhost' IDENTIFIED BY 'dev-password';
  GRANT ALL PRIVILEGES ON gather_dev.* TO 'gather'@'localhost';
  ```

## Start it

From the Gather folder:

```bash
php -r 'echo bin2hex(random_bytes(16)), PHP_EOL;' > storage/install-token
php -S 127.0.0.1:8080 -t public public/index.php
```

Open <http://127.0.0.1:8080/install> and go through the installer (token,
site address `http://127.0.0.1:8080`, host `127.0.0.1`, the database above,
then the super admin, a theme, and the demo content).

The last argument, `public/index.php`, makes it the router script. It serves
real files from `public/`, gzips text like `.htaccess` does on Apache, and
sends everything else to Gather.

**Without the installer:**

```bash
cp config.example.php config.php        # fill in 'db', and 'app_key' (32+ characters)
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'      # a suitable app_key
php bin/migrate
GATHER_ADMIN_PASSWORD='choose-a-long-one' php bin/make-admin you@example.org --name="Your Name"
php bin/demo you@example.org            # optional demo content
php -S 127.0.0.1:8080 -t public public/index.php
```

## Email while developing

Until you switch **Email settings** to SMTP, every email is written to
`storage/mail/*.eml`. Open those files in any mail program to see exactly
what members would receive.

## Cron while developing

Run the jobs by hand when you need them:

```bash
php bin/cron            # whatever is due now
php bin/cron --force    # every job now
php bin/cron --list     # the jobs and how often they run
```

## Command-line tools

| Command | Does |
|---|---|
| `php bin/migrate [--status]` | Apply new database migrations (or list them) |
| `php bin/make-admin <email> [--name="Name"] [--role=super_admin\|admin] [--perm=content,events,...]` | Create or update an admin (asks for the password, or reads `GATHER_ADMIN_PASSWORD`) |
| `php bin/demo <admin-email>` | Load the demo content into a site with no posts yet |
| `php bin/cron [--force\|--list]` | Run due scheduled jobs |
| `php bin/backup [--uploads]` | Write a backup into `storage/backups` |
| `php bin/restore <file.sql.gz> [--yes]` | Replace every table with a Gather backup (asks you to type the database name) |
| `bin/check` | Lint, secrets check, tests and the end-to-end smoke test |
| `bin/package [--zip [--flat]] [ref]` | Build upload archives in `dist/` |

Permission keys for `--perm`: `content`, `pages_menus`, `members`,
`communications`, `radio`, `events`, `jobs`, `gallery_media`.

## Tests

```bash
cp tests/config.test.example.php tests/config.test.php   # point it at an EMPTY throwaway database
bin/check                     # everything (set PHP=/path/to/php to choose the binary)
php tests/run.php [filter]    # unit and database tests only
php tests/smoke.php           # end-to-end through php -S
```

The tests drop and recreate every table in the test database. **Never**
point `tests/config.test.php` at a database you care about.

## Developer tools (never deployed)

`dev-tools/` holds scripts for checks that need a browser or a real web
server. `bin/package` leaves the folder out.

| Script | What it checks |
|---|---|
| `dev-tools/audit.mjs` | Accessibility (axe-core) and Lighthouse; `cd dev-tools && npm install` first |
| `dev-tools/htaccess-check.sh` | Starts a throwaway Apache + PHP-FPM and checks the `.htaccess` rules with the document root at `public/`, at the project folder (the fallback), and without `mod_rewrite` |
| `dev-tools/doc-shots.mjs` | Takes the screenshots in `docs/images/` from a running demo site |
| `dev-tools/shots.mjs`, `touch-reorder.mjs` | Visual checks and the builder's touch reordering |

The browser scripts use Google Chrome through `playwright-core`.

## Code layout

```
public/            web root: index.php (front controller), .htaccess, assets/, uploads/
src/               bootstrap, lib/ (helpers), Core/, Sections/ (page-builder sections),
                   Site/, Account/, Admin/, Install/ (request handlers)
templates/         layouts/, site/, account/, admin/, sections/, install/, partials/
themes/<name>/     theme.json, tokens.css, overrides.css, preview.png
migrations/        numbered SQL files
storage/           originals/, variants/, mail/, logs/, cache/, backups/, bounces/ (private)
bin/               command-line tools
vendor/phpmailer/  PHPMailer (LGPL 2.1), the only third-party PHP library
```

Plain PHP 8.2, no framework and no Composer at runtime. Developer notes are
in [`BUILD-NOTES.md`](../BUILD-NOTES.md).
