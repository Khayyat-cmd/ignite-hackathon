#!/usr/bin/env bash
# Runs ON the VPS as root. Idempotent. Provisions the AMAN demo stack next to the
# other projects already hosted here: nothing outside /var/www/aman, the `aman`
# MySQL database, the two aman-* systemd units, and the aman vhost is touched.
#
#   DB_PASSWORD=... bash provision.sh
#
set -euo pipefail

DOMAIN="${DOMAIN:-aman.baraaelbaba.com}"
ROOT="/var/www/aman"
DB_NAME="${DB_NAME:-aman}"
DB_USER="${DB_USER:-aman}"
: "${DB_PASSWORD:?set DB_PASSWORD before running}"

log() { printf '\n== %s\n' "$*"; }

log "PHP-FPM"
PHP_SOCK="$(ls /run/php/php*-fpm.sock | sort -V | tail -1)"
php -v | head -1
echo "socket: ${PHP_SOCK}"

log "Directories"
mkdir -p "${ROOT}/web/downloads"
mkdir -p "${ROOT}/backend"

log "Database"
mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';"
mysql -e "ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';"
mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;"

log "Composer dependencies"
cd "${ROOT}/backend"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction

log "Application key and schema"
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan event:cache

log "Permissions"
chown -R www-data:www-data "${ROOT}/backend/storage" "${ROOT}/backend/bootstrap/cache"
chmod -R ug+rw "${ROOT}/backend/storage" "${ROOT}/backend/bootstrap/cache"
chown www-data:www-data "${ROOT}/backend/.env"
chmod 640 "${ROOT}/backend/.env"
if [ -f "${ROOT}/backend/storage/app/private/firebase-service-account.json" ]; then
  chown www-data:www-data "${ROOT}/backend/storage/app/private/firebase-service-account.json"
  chmod 600 "${ROOT}/backend/storage/app/private/firebase-service-account.json"
fi

log "Nginx"
sed "s#__PHP_FPM_SOCK__#${PHP_SOCK}#" "${ROOT}/deploy/nginx-aman.conf" > "/etc/nginx/sites-available/${DOMAIN}"
ln -sfn "/etc/nginx/sites-available/${DOMAIN}" "/etc/nginx/sites-enabled/${DOMAIN}"
nginx -t
systemctl reload nginx

log "TLS"
# The vhost is rewritten from the template above every run, which drops the TLS
# directives certbot added, so the certificate is re-installed on every run too.
if [ -d "/etc/letsencrypt/live/${DOMAIN}" ]; then
  certbot install --nginx --cert-name "${DOMAIN}" --non-interactive --redirect
else
  certbot --nginx -d "${DOMAIN}" --non-interactive --agree-tos --redirect
fi
nginx -t && systemctl reload nginx

log "Background services"
cp "${ROOT}/deploy/systemd/aman-queue.service" "${ROOT}/deploy/systemd/aman-schedule.service" /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now aman-queue.service aman-schedule.service
systemctl restart aman-queue.service aman-schedule.service
systemctl --no-pager --lines=3 status aman-queue.service aman-schedule.service || true

log "Done: https://${DOMAIN}"
