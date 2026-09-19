#!/usr/bin/env bash
# Chengyu: opt-in bootstrap for a NEW Debian 12+/Ubuntu 24.04+ VPS.
# Run only after reviewing this file. Never use it on a hosting control panel.
# Usage: sudo bash deploy/install-vps.sh example.com
set -Eeuo pipefail
umask 027
trap 'printf "Installation stopped at line %s. Inspect the host; do not blindly rerun.\n" "$LINENO" >&2' ERR
fail() { printf '%s\n' "$*" >&2; exit 1; }
[[ ${EUID} -eq 0 ]] || fail 'Run as root on a new VPS.'
[[ $# -eq 1 ]] || fail 'Usage: sudo bash deploy/install-vps.sh example.com'
domain="${1,,}"
[[ "$domain" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]([a-z0-9-]{0,61}[a-z0-9])?$ ]] || fail 'Supply a DNS hostname only, without protocol, path, port or wildcard.'
[[ ${#domain} -le 253 ]] || fail 'Hostname too long.'
[[ -r /etc/os-release ]] || fail 'Cannot detect OS.'
# shellcheck source=/dev/null
. /etc/os-release
case "$ID" in
  debian) [[ ${VERSION_ID%%.*} -ge 12 ]] || fail 'Debian 12+ required.' ;;
  ubuntu) [[ ${VERSION_ID%%.*} -ge 24 ]] || fail 'Ubuntu 24.04+ required.' ;;
  *) fail 'Only Debian / Ubuntu are supported by this helper.' ;;
esac
[[ -d /run/systemd/system ]] || fail 'A running systemd host is required.'
source_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
[[ -f "$source_dir/site/index.php" && -f "$source_dir/tools/prepare_install.php" ]] || fail 'Run from an extracted complete delivery package.'
target=/var/www/chengyu
[[ ! -e "$target" ]] || fail 'Target already exists. Use a reviewed manual deployment or backup/restore plan.'
for path in /www/server/panel /usr/local/psa /usr/local/cpanel; do
  [[ ! -e "$path" ]] || fail 'Hosting panel detected. Use its site creation UI instead.'
done
for service in caddy nginx apache2; do
  systemctl is-active --quiet "$service" && fail "Existing $service service detected. Refusing to replace a running web stack."
done
[[ ! -e /etc/caddy/Caddyfile ]] || fail 'Existing Caddy configuration detected. Refusing to replace it.'
if command -v ss >/dev/null 2>&1; then
  [[ -z "$(ss -H -ltn 'sport = :80 or sport = :443')" ]] || fail 'Port 80 or 443 is already occupied.'
fi
printf 'This installs OS packages and a new site at %s for %s.\n' "$target" "$domain"
printf 'First point A/AAAA records here and permit TCP 80/443 in your cloud firewall.\n'
read -r -p 'Type INSTALL to continue: ' reply
[[ "$reply" == INSTALL ]] || fail 'Cancelled.'
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y caddy php-cli php-fpm php-sqlite3 php-mysql php-mbstring ca-certificates
php -r 'exit(PHP_INT_SIZE === 8 && version_compare(PHP_VERSION,"7.4.0",">=") && extension_loaded("openssl") && in_array("sqlite", PDO::getAvailableDrivers(), true) ? 0 : 1);' || fail 'Required PHP capabilities are missing.'
phpver="$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')"
fpm_service="php${phpver}-fpm"
socket="/run/php/php${phpver}-fpm.sock"
systemctl enable --now "$fpm_service"
[[ -S "$socket" ]] || fail "Expected PHP-FPM socket missing: $socket. Review your FPM pool configuration."
install -d -m 0755 "$target"
cp -R -- "$source_dir/site" "$target/site"
find "$target/site" -type d -exec chmod 0755 {} +
find "$target/site" -type f -exec chmod 0644 {} +
chown -R root:www-data "$target/site"
chown www-data:www-data "$target/site/app" "$target/site/storage"
chmod 0750 "$target/site/app" "$target/site/storage"
install -d -o www-data -g www-data -m 0700 "$target/private"
php "$source_dir/tools/prepare_install.php" "$target/site"
# FPM's distribution socket is normally owned by www-data:www-data, mode 0660.
usermod -a -G www-data caddy
[[ ! -f /etc/caddy/Caddyfile ]] || cp /etc/caddy/Caddyfile /etc/caddy/Caddyfile.distribution-backup
cat > /etc/caddy/Caddyfile <<CADDY
$domain {
    root * $target/site
    encode zstd gzip
    @private path /app /app/* /storage /storage/* /uploads /uploads/* /.git* /.env* /.ht* /web.config /INSTALL_KEY.txt
    handle @private {
        respond 404
    }
    handle {
        php_fastcgi unix/$socket
        file_server
    }
}
CADDY
chmod 0644 /etc/caddy/Caddyfile
caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
systemctl enable caddy
systemctl restart caddy
printf '\nOpen https://%s/install/ once DNS and the certificate are ready.\n' "$domain"
printf 'Select SQLite and enter %s/private/chengyu.sqlite\n' "$target"
printf 'Read the private installation key with: sudo cat %s/INSTALL_KEY.txt\n' "$target"
printf 'Choose your own administrator account. No default account is installed.\n'
printf 'After verifying the website, delete %s/site/install and make app read-only to PHP.\n' "$target"
printf 'Review docs/DEPLOYMENT.md and docs/SECURITY.md before accepting payments.\n'
