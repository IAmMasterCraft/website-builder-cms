# Install on aaPanel

This guide puts Gather on a domain of a server managed with **aaPanel**
running **Apache** (Nginx in aaPanel works too: see the note in step 2). Menu
names differ a little between aaPanel versions. If a label does not match,
look for the nearest one.

Replace throughout:

| Placeholder | Example |
|---|---|
| `<domain>` | `community.example.org` |
| `<site>` | the folder aaPanel creates, `/www/wwwroot/community.example.org` |
| `<php>` | the PHP command-line binary of the site's version, e.g. `/www/server/php/83/bin/php` |

Check the [requirements](requirements.md) first, and point a DNS **A record**
for `<domain>` at the server.

## 1. Get the code onto your computer

Download a release `.tar.gz` (or build one with `bin/package`; see
[Getting the code](getting-the-code.md)). It contains one folder, `gather/`.

## 2. Create the site

aaPanel → **Website → Add site**:

- **Domain**: `<domain>`.
- **PHP version**: 8.2 or newer (install it from the **App Store** first if it
  is missing).
- **Database**: MySQL; create one here (for example `gather`), with the
  password aaPanel generates and character set **utf8mb4**.

Open the new site's **Settings**:

- **Site directory → Running directory**: choose `/public` and save. Only
  `public/` is then reachable from the web.
- Keep **anti-cross-site attack (open_basedir)** on. Gather reads only inside
  the site folder and the system temporary folder.
- **URL rewrite**: leave empty on Apache (Gather's `public/.htaccess` does it).
  On an Nginx site, paste the `location` blocks from
  [`nginx.conf.example`](../nginx.conf.example) instead.
- **SSL → Let's Encrypt**: issue a certificate, then switch on **Force
  HTTPS**. Do it before installing.

## 3. Upload and unpack

aaPanel → **Files** → open `<site>` → upload the archive. Delete aaPanel's
placeholder files (`index.html`, `404.html`, maybe `.htaccess`), but **keep
`.user.ini`**. It is aaPanel's open_basedir file and is deliberately locked.

aaPanel → **Terminal** (or SSH):

```bash
cd <site>
tar -xzf gather-*.tar.gz --strip-components=1 && rm gather-*.tar.gz
# code: owned by root, read-only for the web server (skip the locked .user.ini)
find . -path ./.user.ini -prune -o -exec chown root:root {} +
find . -path ./.user.ini -prune -o -type d -exec chmod 755 {} +
find . -path ./.user.ini -prune -o -type f -exec chmod 644 {} +
chmod 755 bin/*
# only these are writable by the web server user (www)
chown -R www:www storage public/uploads
```

## 4. PHP settings

aaPanel → **App Store** → your PHP version → **Settings**:

- **Install extensions**: `fileinfo`, `intl` and `exif` if missing. The rest
  are normally built in.
- **Configuration**: `upload_max_filesize 320M`, `post_max_size 330M`,
  `memory_limit 256M`, `max_execution_time 300`, `max_input_time 300`,
  `display_errors Off`, `expose_php Off`. Then reload PHP.
- **Disabled functions**: keep aaPanel's defaults. Gather never uses shell
  functions.

## 5. Install

```bash
cd <site>
<php> -r 'echo bin2hex(random_bytes(16)), PHP_EOL;' > storage/install-token
cat storage/install-token                     # copy it for the form
chown www:www storage/install-token && chmod 600 storage/install-token
chown root:www . && chmod 775 .               # let the installer create config.php
```

Open `https://<domain>/install`: the install token, the site address
(`https://<domain>`), host `localhost`, port `3306`, and the database name,
user and password from step 2. Then create the super admin, pick a theme, and
choose demo content or an empty site.

Afterwards:

```bash
cd <site>
chown root:root . && chmod 755 .
chown root:www config.php && chmod 640 config.php
```

## 6. Cron

Copy the **Cron line** from **Admin → System health → Server and paths**.

aaPanel → **Cron → Add task**: type **Shell script**, every **1 minute**.
Run it as `www`, so files it creates stay writable by the site:

```bash
su -s /bin/sh -c "<php> <site>/bin/cron >> <site>/storage/logs/cron.log 2>&1" www
```

Use the full PHP path (plain `php` can be an older version). After two
minutes, **System health → Cron** shows the last run.

## 7. After the install

1. **Admin → Email settings**: SMTP and a test email. See
   [Email deliverability](email-deliverability.md).
2. **Admin → Backups**: switch on the daily backup. Also add aaPanel
   **Cron → Backup site** and **Backup database** tasks, and keep copies off
   the server.
3. **Admin → System health**: fix anything marked **Problem**.

## Updating

```bash
cd <site>
# back up first (Admin → Backups → Back up to the server now)
tar -xzf gather-*.tar.gz --strip-components=1 && rm gather-*.tar.gz
chown -R root:root src templates themes migrations bin vendor demo-assets VERSION
find public -path public/uploads -prune -o -exec chown root:root {} +
chmod 755 bin/*
chown -R www:www storage public/uploads
su -s /bin/sh -c "<php> bin/migrate" www
```

`config.php`, `storage/`, `public/uploads/` and `.user.ini` are not in the
archive, so they survive. Reload PHP if OPcache runs with
`validate_timestamps=0`.

## aaPanel-specific troubleshooting

| Symptom | Look at |
|---|---|
| 404 on every page except the home page | Running directory is not `/public`, or (Nginx) the rewrite rules are missing |
| "open_basedir restriction in effect" | `.user.ini` must allow `<site>/` and `/tmp/` |
| Files in `storage/` owned by root | The cron task ran as root: use the `su … www` form, then `chown -R www:www storage public/uploads` |
| Emails not arriving | **Send a test email**, the **Delivery log**, and whether your server's provider blocks outgoing SMTP |

More: [Troubleshooting](manager-guide/troubleshooting.md).
