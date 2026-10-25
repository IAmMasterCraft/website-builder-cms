# Install on a VPS (Ubuntu 24.04)

This guide puts Gather on your own virtual server running **Ubuntu 24.04
LTS**, with **Apache or Nginx**, **PHP-FPM 8.3** (Ubuntu's default),
**MariaDB**, a free **Let's Encrypt** certificate, cron, a firewall and
off-server backups. You need SSH access as a user that can run `sudo`.

Replace these throughout:

| Placeholder | Example |
|---|---|
| `community.example.org` | your site's domain |
| `/var/www/gather` | where Gather lives |
| `deploy` | your own (non-root) login user |

Commands are typed on the server unless they say otherwise.

## 1. Before you start

- A DNS **A record** (and **AAAA** for IPv6) for `community.example.org`
  pointing at the server's IP address. Certbot needs it in step 9.
- Keep the system up to date, and let security updates install themselves:

  ```bash
  sudo apt update && sudo apt upgrade -y
  sudo apt install -y unattended-upgrades
  ```

## 2. Install PHP, the database and tools

```bash
sudo apt install -y mariadb-server git unzip \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-gd php8.3-mbstring php8.3-intl php8.3-curl
```

Install `php8.3-fpm`, not the `php` meta-package. That one would pull in
Apache's old `mod_php`. `fileinfo`, `exif`, `sodium`, `openssl`, `zlib` and
`json` come with `php8.3-common`, which is installed along with the others.
Check:

```bash
php8.3 -m | grep -Ei '^(gd|intl|exif|fileinfo|mbstring|pdo_mysql|sodium|openssl|zlib|json)$'
php8.3 -r 'var_dump(gd_info()["WebP Support"]);'      # bool(true)
```

## 3. Install the web server

Pick **one**: Apache (3a) or Nginx (3b).

### 3a. Apache with PHP-FPM

```bash
sudo apt install -y apache2
sudo a2enmod proxy_fcgi setenvif rewrite headers deflate
sudo a2enconf php8.3-fpm
```

### 3b. Nginx with PHP-FPM

```bash
sudo apt install -y nginx
```

## 4. Create the database

```bash
sudo mysql
```

Then, in the MariaDB prompt (choose your own strong password):

```sql
CREATE DATABASE gather CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'gather'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT ALL PRIVILEGES ON gather.* TO 'gather'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

MariaDB on Ubuntu listens only on `localhost` by default. Keep it that way.

## 5. Put the code in place

The code belongs to your own user and is read-only for the web server
(`www-data`). The web server can write only to `storage/` and
`public/uploads/`. If PHP were ever tricked into writing a file, it still
could not change Gather's code.

**With Git** (easiest to update):

```bash
sudo mkdir -p /var/www/gather && sudo chown deploy:deploy /var/www/gather
git clone https://github.com/YOUR-ACCOUNT/gather.git /var/www/gather
```

**Or with a release package**: copy it to the server (from your computer:
`scp gather-*.tar.gz deploy@server:`), then:

```bash
sudo mkdir -p /var/www/gather && sudo chown deploy:deploy /var/www/gather
tar -xzf ~/gather-*.tar.gz -C /var/www/gather --strip-components=1
```

Then set the permissions:

```bash
cd /var/www/gather
find . -path ./.git -prune -o -type d -exec chmod 755 {} +
find . -path ./.git -prune -o -type f -exec chmod 644 {} +
chmod 755 bin/*
sudo chown -R www-data:www-data storage public/uploads
```

## 6. Configure the site

### Apache

Create `/etc/apache2/sites-available/gather.conf`:

```apache
<VirtualHost *:80>
    ServerName community.example.org
    DocumentRoot /var/www/gather/public

    <Directory /var/www/gather/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/gather-error.log
    CustomLog ${APACHE_LOG_DIR}/gather-access.log combined
</VirtualHost>
```

`AllowOverride All` lets Gather's `public/.htaccess` do the clean URLs, file
protection and caching. Then:

```bash
sudo a2ensite gather
sudo a2dissite 000-default
sudo apache2ctl configtest && sudo systemctl reload apache2
```

### Nginx

```bash
sudo cp /var/www/gather/nginx.conf.example /etc/nginx/sites-available/gather
sudo nano /etc/nginx/sites-available/gather    # set server_name, root and the PHP-FPM socket
sudo ln -s /etc/nginx/sites-available/gather /etc/nginx/sites-enabled/gather
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

On Ubuntu 24.04 the socket is `/run/php/php8.3-fpm.sock`, which the example
already uses. The example serves only `public/`, runs PHP only through
`index.php`, serves only WebP images from `uploads/`, and allows uploads up
to 330 MB (`client_max_body_size`).

## 7. PHP settings

Create `/etc/php/8.3/fpm/conf.d/99-gather.ini`:

```ini
upload_max_filesize = 320M
post_max_size = 330M
memory_limit = 256M
max_execution_time = 300
max_input_time = 300
display_errors = Off
expose_php = Off
```

```bash
sudo systemctl reload php8.3-fpm
```

(Use the same values in `/etc/php/8.3/cli/conf.d/99-gather.ini` if you like.
The command-line tools do not need them.)

## 8. Firewall

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Apache Full'      # or: sudo ufw allow 'Nginx Full'
sudo ufw enable
sudo ufw status
```

This opens only SSH, HTTP and HTTPS. The database stays private.

## 9. HTTPS with Certbot

```bash
sudo apt install -y certbot python3-certbot-apache   # Apache
sudo certbot --apache -d community.example.org

sudo apt install -y certbot python3-certbot-nginx    # Nginx
sudo certbot --nginx -d community.example.org
```

Let Certbot redirect HTTP to HTTPS when it asks. Renewal is automatic (a
systemd timer); `sudo certbot renew --dry-run` tests it.

Do this before step 10, so the installer records `https://` as the site
address.

## 10. Install

Create the one-time install token, and let the installer write `config.php`
into the Gather folder (only for the length of the install):

```bash
cd /var/www/gather
php8.3 -r 'echo bin2hex(random_bytes(16)), PHP_EOL;' | sudo tee storage/install-token
sudo chown www-data:www-data storage/install-token && sudo chmod 600 storage/install-token
sudo chgrp www-data . && sudo chmod 775 .
```

Open `https://community.example.org/install`:

1. **Database**: the install token (printed above), the site address
   (`https://community.example.org`), host `localhost`, port `3306`, database
   `gather`, user `gather` and the password from step 4.
2. **Admin**: organisation name and the first super admin.
3. **Theme**.
4. **Content**: demo content or an empty site → **Finish installation**.

Then take the write access away again, and make `config.php` readable (but
not writable) by the web server:

```bash
cd /var/www/gather
sudo chmod 755 . && sudo chgrp deploy .
sudo chown deploy:www-data config.php && sudo chmod 640 config.php
ls -l config.php                 # -rw-r----- deploy www-data
```

**Installing without the browser** (for example when scripting):

```bash
cp config.example.php config.php && nano config.php      # DB details, base_url, 'installed' => true
php8.3 -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'      # paste as 'app_key' (32+ characters)
sudo chown deploy:www-data config.php && sudo chmod 640 config.php
sudo -u www-data php8.3 bin/migrate
sudo -u www-data php8.3 bin/make-admin you@example.org --name="Your Name"   # asks for the password
sudo -u www-data php8.3 bin/demo you@example.org                            # optional demo content
```

Gather refuses to run while `app_key` is empty, `change-me`, or shorter than
32 characters.

**Behind a proxy or CDN** (Cloudflare, a load balancer): list the proxy's
IP addresses in `config.php` as `'trusted_proxies' => ['203.0.113.10']`.
Otherwise Gather ignores `X-Forwarded-For` / `X-Forwarded-Proto`: rate limits
see the proxy's address, and HTTPS is judged from the direct connection only.

## 11. Cron

Open **Admin → System health → Server and paths** and copy the **Cron line**.
On this setup it is:

```
* * * * * /usr/bin/php8.3 /var/www/gather/bin/cron >> /var/www/gather/storage/logs/cron.log 2>&1
```

Add it to the **web server user's** crontab, so files cron creates stay
writable by the site:

```bash
sudo crontab -u www-data -e      # paste the line, save
```

**Or with a systemd timer** instead of cron. Create
`/etc/systemd/system/gather-cron.service`:

```ini
[Unit]
Description=Gather scheduled jobs

[Service]
Type=oneshot
User=www-data
Group=www-data
ExecStart=/usr/bin/php8.3 /var/www/gather/bin/cron
```

and `/etc/systemd/system/gather-cron.timer`:

```ini
[Unit]
Description=Run Gather scheduled jobs every minute

[Timer]
OnCalendar=*-*-* *:*:00
AccuracySec=1s
Persistent=true

[Install]
WantedBy=timers.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now gather-cron.timer
systemctl list-timers gather-cron.timer
journalctl -u gather-cron.service -n 20     # the output of the last runs
```

Use one or the other, not both. After two minutes, **System health → Cron**
shows the last run.

## 12. Email

Set up SMTP and, ideally, a bounce mailbox under **Admin → Email settings**.
[Email deliverability](email-deliverability.md) explains the choices,
including the DNS records (SPF, DKIM, DMARC) your sending domain needs.

You do not need a mail server on the VPS: use your email provider's SMTP
server or a transactional service. Many cloud providers block outgoing port
25. Gather uses 587 or 465.

## 13. Check it

```bash
curl -s -o /dev/null -w 'home %{http_code}\n'        https://community.example.org/
curl -s -o /dev/null -w 'config %{http_code} (want 403/404)\n'  https://community.example.org/config.php
curl -s -o /dev/null -w 'src %{http_code} (want 403/404)\n'     https://community.example.org/src/bootstrap.php
curl -s -o /dev/null -w 'install %{http_code} (want 404)\n'     https://community.example.org/install
curl -sI https://community.example.org/ | grep -iE 'content-security|strict-transport|x-content-type|x-frame'
```

Then **Admin → System health** should show no **Problem** items.

## 14. Backups off the server

Turn on Gather's daily backup (**Admin → Backups → Make a backup every day**,
keep 7–14). Files land in `/var/www/gather/storage/backups/`. That protects
you against mistakes, but not against losing the server, so copy them
somewhere else as well.

Simplest: pull them from another machine (your office computer, a NAS, a
second server) every night. On the server, let your user read them:

```bash
sudo usermod -aG www-data deploy        # log out and in again
```

On the other machine (crontab, 03:30 every night):

```
30 3 * * * rsync -a deploy@community.example.org:/var/www/gather/storage/backups/ /backups/gather/
```

Or push them to object storage with a tool such as `rclone`. Backups contain
members' personal data: keep the copies encrypted and private, and do a
test restore once (see [Backups and restore](manager-guide/backups.md)).

Your VPS provider's snapshot feature is a good extra layer.

## Updating

```bash
cd /var/www/gather
# 1. Backup: Admin → Backups → Back up to the server now (with uploads)
git pull                                           # or extract a new package as in step 5
sudo chown -R www-data:www-data storage public/uploads
sudo -u www-data php8.3 bin/migrate
sudo systemctl reload php8.3-fpm                   # clears OPcache
```

Then **System health**: Migrations *none pending*. More in
[Getting the code → Updating later](getting-the-code.md#updating-later).

## Troubleshooting

| Symptom | Look at |
|---|---|
| 502 Bad Gateway (Nginx) / 503 (Apache) | `systemctl status php8.3-fpm`; the socket path in the site config |
| 404 on every page except the home page | Apache: `AllowOverride All` and `a2enmod rewrite`; Nginx: the `try_files` lines |
| "Something went wrong" | `storage/logs/php-error.log`, then `/var/log/apache2/gather-error.log` or `/var/log/nginx/error.log` |
| Upload fails or "413 Request Entity Too Large" | Step 7; Nginx `client_max_body_size` |
| Files in `storage/` owned by root | Cron ran as root: use `crontab -u www-data`, then `sudo chown -R www-data:www-data storage public/uploads` |
| Cron line not running | `sudo crontab -u www-data -l`; `tail storage/logs/cron.log` |

More: [Troubleshooting](manager-guide/troubleshooting.md).
