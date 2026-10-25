# Requirements

Gather is plain PHP with a MySQL or MariaDB database. It runs on most
shared hosting plans, on a small VPS, and on control panels such as cPanel,
Plesk and aaPanel. It needs no Composer, Node.js or build step on the server.

After installing, **Admin → System health** checks everything on this page
for you and marks anything missing.

## Checklist

| Need | Minimum | Notes |
|---|---|---|
| PHP | **8.2** or newer (8.3 recommended) | PHP-FPM, LSAPI (LiteSpeed) or Apache's PHP module all work |
| Database | **MySQL 8** or **MariaDB 10.6+** | One empty database with the `utf8mb4` character set; Gather creates its own tables |
| Web server | Apache 2.4 with `mod_rewrite`, **or** Nginx, **or** LiteSpeed | The document root should point at the `public/` folder (see below) |
| Cron | One job running **every minute** | Sends emails, publishes scheduled posts, runs backups |
| HTTPS | A certificate for your domain | Let's Encrypt / AutoSSL is fine and free |
| Disk space | About 25 MB for the code, plus your uploads | Radio programmes and photos are what take space; backups need room for a copy |

## PHP extensions

| Extension | Required? | Used for |
|---|---|---|
| `pdo_mysql` | required | the database |
| `gd` **with WebP support** | required | resizing images into WebP versions |
| `fileinfo` | required | checking what kind of file was uploaded |
| `mbstring` | required | text handling |
| `sodium` | required | encrypting the stored SMTP and mailbox passwords |
| `openssl` | required | sending email over TLS, reading the bounce mailbox |
| `zlib` | required | compressed database backups |
| `json` | required | settings and the radio's live data |
| `intl` | recommended | cleaner web addresses from accented titles |
| `exif` | recommended | turning phone photos the right way up |
| `curl` | optional | not used by Gather; handy for your own checks |

You do **not** need `exec()`, `shell_exec()` or other shell functions (Gather
never uses them), and you do **not** need the `imap` extension. The bounce
mailbox is read with Gather's own code.

## PHP settings

| Setting | Recommended | Why |
|---|---|---|
| `upload_max_filesize` | `320M` | Radio video can be up to 300 MB, audio up to 100 MB |
| `post_max_size` | `330M` | A little more than the upload limit |
| `memory_limit` | `256M` (at least 128M) | Resizing large photos |
| `max_execution_time` | `300` | Big uploads and backup downloads |
| `max_input_time` | `300` | Slow uploads |
| `display_errors` | `Off` | Errors are written to `storage/logs/php-error.log` instead |

If you will never upload radio video, `120M` / `128M` is enough. Without the
radio, the largest file is a 20 MB document, so `32M` / `40M` works. Many
shared hosts cap these values; your host can tell you the maximum.

File size limits inside Gather: images 10 MB, documents 20 MB, audio 100 MB,
video 300 MB.

## The document root

Visitors must only be able to reach the `public/` folder. Everything else
(`src/`, `storage/`, `config.php`) holds code, private files and passwords.

- **Best:** point the domain's document root at `…/gather/public`. Every
  install guide shows how.
- **Fallback (Apache or LiteSpeed only):** if your host does not let you
  change the document root (common for the main domain of a shared hosting
  account), put the whole Gather folder in the document root. The `.htaccess`
  file at the top of Gather then sends every request into `public/`. Every
  private folder also has its own "deny" rule as a second layer. System
  health shows which of the two setups it detects.
- **Nginx** ignores `.htaccess` files, so the document root *must* be
  `public/`. Use [`nginx.conf.example`](../nginx.conf.example).

Gather must have its own domain or subdomain (for example
`www.example.org` or `community.example.org`). Installing it in a sub-folder
of another site (`example.org/gather/`) is not supported.

## Network

- **Outgoing SMTP** (ports 587 or 465) to your mail server, unless you use the
  host's own mail server. Some shared hosts block outgoing SMTP to outside
  providers. Ask, or use the mail server that comes with the hosting.
- **Outgoing IMAP or POP3** (ports 993 or 995) if you use a bounce mailbox.
- **DNS lookups** from PHP (`dns_get_record`). Gather checks that email
  domains exist before sending. If lookups are blocked, addresses are
  accepted without the check.
- **Outgoing HTTPS** to Amazon only if you connect Amazon SES webhooks (to
  fetch the signing certificate; needs `allow_url_fopen`).

No requests go to third parties when visitors browse the site. Fonts, icons
and scripts are all served from your own domain.

## Browsers

The public site and the admin area work in current versions of Chrome, Edge,
Firefox and Safari, on desktop and mobile. Pages still work, in a simpler
form, with JavaScript switched off. The page builder and the campaign
preview need JavaScript.
