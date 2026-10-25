#!/usr/bin/env bash
# Dev only (never deployed). Checks the Apache rules for real: starts a
# throwaway Apache (mod_rewrite + mod_proxy_fcgi) and PHP-FPM against this
# checkout and requests public and private URLs in both layouts:
#   1. document root = public/            (recommended)
#   2. document root = the project folder  (fallback: root .htaccess -> public/)
# In both, AllowOverride is on for the project folder too, as on most hosts.
# Runs against a temporary copy of the checkout (code, config.php and one
# uploaded image; macOS does not let /usr/sbin/httpd read ~/Desktop), using
# the same database. Needs an installed site, httpd, php-fpm and rsync.
#   dev-tools/htaccess-check.sh
# Overrides: HTTPD=/usr/sbin/httpd APACHE_MODULES=/usr/libexec/apache2 PHP_FPM=/opt/homebrew/sbin/php-fpm
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HTTPD="${HTTPD:-$(command -v httpd || true)}"
MODS="${APACHE_MODULES:-/usr/libexec/apache2}"
PHP_FPM="${PHP_FPM:-$(command -v php-fpm || ls /opt/homebrew/sbin/php-fpm /usr/sbin/php-fpm* 2>/dev/null | head -1 || true)}"
[ -x "$HTTPD" ] || { echo "httpd not found (set HTTPD=)"; exit 2; }
[ -x "$PHP_FPM" ] || { echo "php-fpm not found (set PHP_FPM=)"; exit 2; }
[ -f "$ROOT/config.php" ] || { echo "config.php missing: install the site first"; exit 2; }
APORT="${APORT:-18091}"
FPORT="${FPORT:-19091}"
T=$(mktemp -d "${TMPDIR:-/tmp}/gather-htaccess.XXXXXX")
cleanup() {
  [ -f "$T/httpd.pid" ] && kill "$(cat "$T/httpd.pid")" 2>/dev/null || true
  [ -n "${FPM_PID:-}" ] && kill "$FPM_PID" 2>/dev/null || true
  sleep 0.3
  rm -rf "$T"
}
trap cleanup EXIT

SITE="$T/site"
rsync -a --exclude '/dev-tools' --exclude '/demo-assets' --exclude '/.git' --exclude '/dist' \
  --exclude '/public/uploads/*' --exclude '/storage/*/*' "$ROOT/" "$SITE/"
upload=$(cd "$ROOT/public" && find uploads -name '*.webp' -type f | head -1 || true)
if [ -n "$upload" ]; then mkdir -p "$SITE/public/$(dirname "$upload")" && cp "$ROOT/public/$upload" "$SITE/public/$upload"; fi
cp "$ROOT/public/uploads/.htaccess" "$SITE/public/uploads/"
mkdir -p "$SITE/.git" && echo 'ref: refs/heads/main' > "$SITE/.git/HEAD"
echo 'not-a-real-token-0000' > "$SITE/storage/install-token"
for d in "$SITE" "$SITE/public"; do mkdir -p "$d/.well-known/acme-challenge" && echo ok > "$d/.well-known/acme-challenge/check-token_1"; done
mkdir -p "$SITE/.well-known/pki-validation" && echo ok > "$SITE/.well-known/pki-validation/ABC123.txt"

cat > "$T/fpm.conf" <<EOF
[global]
error_log = $T/fpm.log
daemonize = no
[www]
listen = 127.0.0.1:$FPORT
pm = static
pm.max_children = 4
clear_env = no
EOF
"$PHP_FPM" -y "$T/fpm.conf" -p "$T" >/dev/null 2>&1 &
FPM_PID=$!

write_conf() { # $1 = document root, $2 = 'norewrite' to leave mod_rewrite out
  cat > "$T/httpd.conf" <<EOF
ServerRoot "$T"
Listen 127.0.0.1:$APORT
ServerName localhost
PidFile "$T/httpd.pid"
DefaultRuntimeDir "$T"
Mutex file:$T default
ErrorLog "$T/error.log"
LogLevel warn
LoadModule mpm_prefork_module $MODS/mod_mpm_prefork.so
LoadModule unixd_module $MODS/mod_unixd.so
LoadModule authz_core_module $MODS/mod_authz_core.so
LoadModule authz_host_module $MODS/mod_authz_host.so
LoadModule dir_module $MODS/mod_dir.so
LoadModule mime_module $MODS/mod_mime.so
$( [ "${2:-}" = norewrite ] || echo "LoadModule rewrite_module $MODS/mod_rewrite.so")
LoadModule headers_module $MODS/mod_headers.so
LoadModule filter_module $MODS/mod_filter.so
LoadModule deflate_module $MODS/mod_deflate.so
LoadModule proxy_module $MODS/mod_proxy.so
LoadModule proxy_fcgi_module $MODS/mod_proxy_fcgi.so
LoadModule log_config_module $MODS/mod_log_config.so
TypesConfig /dev/null
AddType text/css .css
AddType text/javascript .js
AddType image/webp .webp
AddType image/svg+xml .svg
DocumentRoot "$1"
<Directory />
  AllowOverride None
  Require all denied
</Directory>
<Directory "$SITE">
  AllowOverride All
  Options FollowSymLinks
  Require all granted
</Directory>
<FilesMatch "\.php$">
  SetHandler "proxy:fcgi://127.0.0.1:$FPORT"
</FilesMatch>
EOF
}

fail=0
expect() { # $1 = label, $2 = path, $3 = allowed codes (regex)
  local code
  code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$APORT$2")
  if [[ "$code" =~ ^($3)$ ]]; then
    printf '  ok    %-44s %s\n' "$1" "$code"
  else
    printf '  FAIL  %-44s %s (want %s)\n' "$1" "$code" "$3"
    fail=1
  fi
}

private_checks() {
  for p in /config.php /config.example.php /src/bootstrap.php /storage/logs/php-error.log /storage/install-token /bin/cron \
           /vendor/phpmailer/src/PHPMailer.php /migrations/001_core.sql /templates/layouts/site.php /README.md /VERSION /.git/HEAD /.htaccess; do
    expect "private: $p" "$p" '403|404'
  done
}

run_checks() {
  expect 'home page' / 200
  expect 'events list (clean URL)' /events 200
  expect 'search with query string' '/search?q=community' 200
  expect 'static CSS' /assets/css/site.css 200
  expect 'theme.css (made by PHP)' /theme.css 200
  expect 'feed.xml (made by PHP)' /feed.xml 200
  [ -n "$upload" ] && expect 'uploaded WebP variant' "/$upload" 200
  expect 'unknown page' /no-such-page-here 404
  expect 'ACME challenge file (AutoSSL)' /.well-known/acme-challenge/check-token_1 200
  expect 'installer locked' /install 404
  expect 'config.php' /config.php '403|404'
  expect 'config.example.php' /config.example.php '403|404'
  expect 'src/bootstrap.php' /src/bootstrap.php '403|404'
  expect 'storage log' /storage/logs/php-error.log '403|404'
  expect 'storage install-token' /storage/install-token '403|404'
  expect 'bin/cron' /bin/cron '403|404'
  expect 'vendor PHPMailer' /vendor/phpmailer/src/PHPMailer.php '403|404'
  expect 'migrations SQL' /migrations/001_core.sql '403|404'
  expect 'README.md' /README.md '403|404'
  expect 'VERSION' /VERSION '403|404'
  expect '.git' /.git/HEAD '403|404'
  expect '.htaccess' /.htaccess '403|404'
  expect 'uploads .htaccess' /uploads/.htaccess '403|404'
  expect 'PHP in uploads' /uploads/x.php '403|404'
  expect 'directory listing' /assets/ '403|404'
}


for mode in public project norewrite; do
  if [ "$mode" = public ]; then docroot="$SITE/public"; else docroot="$SITE"; fi
  write_conf "$docroot" "$mode"
  "$HTTPD" -t -f "$T/httpd.conf" >/dev/null 2>&1 || { "$HTTPD" -t -f "$T/httpd.conf"; exit 1; }
  "$HTTPD" -f "$T/httpd.conf" -k start || { tail -n 5 "$T/error.log"; exit 1; }
  for _ in 1 2 3 4 5 6 7 8 9 10; do curl -s -o /dev/null "http://127.0.0.1:$APORT/assets/" && break; sleep 0.3; done
  note=""; [ "$mode" = norewrite ] && note=" (mod_rewrite off: only the private files are checked)"
  echo "== document root: $docroot$note"
  if [ "$mode" = norewrite ]; then
    private_checks
    "$HTTPD" -f "$T/httpd.conf" -k stop
    for _ in 1 2 3 4 5 6 7 8 9 10; do [ -f "$T/httpd.pid" ] || break; sleep 0.3; done
    continue
  fi
  run_checks
  [ "$mode" = project ] && expect 'cPanel DCV file (pki-validation)' /.well-known/pki-validation/ABC123.txt 200
  hdr=$(curl -sI "http://127.0.0.1:$APORT/assets/css/site.css" | tr -d '\r')
  if grep -qi '^cache-control: public, max-age=31536000, immutable' <<<"$hdr"; then echo "  ok    static files cached (immutable)"; else echo "  FAIL  static cache header"; fail=1; fi
  "$HTTPD" -f "$T/httpd.conf" -k stop
  for _ in 1 2 3 4 5 6 7 8 9 10; do [ -f "$T/httpd.pid" ] || break; sleep 0.3; done
done

if [ "$fail" != 0 ]; then
  echo "Apache error log:"; tail -n 20 "$T/error.log" || true
  exit 1
fi
echo "all Apache checks passed (public/ and project-folder document roots, and without mod_rewrite)"
