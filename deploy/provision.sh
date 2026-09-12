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
mkdir -p "${ROOT}/agent"

log "Database"
mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';"
mysql -e "ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';"
mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;"

log "CAMARA agent service"
# Its own virtualenv so the agent's Python dependencies never touch the system
# interpreter the other projects on this VPS share. Created once and reused, so
# a redeploy only reconciles requirements.
AGENT_PY="${ROOT}/agent/.venv/bin/python"
if [ ! -x "${AGENT_PY}" ]; then
  python3 -m venv "${ROOT}/agent/.venv" || {
    echo "python3 -m venv failed. Install the venv module first: apt-get install -y python3-venv" >&2
    exit 1
  }
  "${AGENT_PY}" -m pip install -q --upgrade pip
fi
python3 --version
"${AGENT_PY}" -m pip install -q -r "${ROOT}/agent/requirements.txt"
"${AGENT_PY}" -m compileall -q "${ROOT}/agent"

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
chown -R www-data:www-data "${ROOT}/agent"
if [ -f "${ROOT}/agent/agent.env" ]; then
  chown root:www-data "${ROOT}/agent/agent.env"
  chmod 640 "${ROOT}/agent/agent.env"
fi
if [ -f "${ROOT}/backend/storage/app/private/firebase-service-account.json" ]; then
  chown www-data:www-data "${ROOT}/backend/storage/app/private/firebase-service-account.json"
  chmod 600 "${ROOT}/backend/storage/app/private/firebase-service-account.json"
fi

log "Nginx"
VHOST="/etc/nginx/sites-available/${DOMAIN}"
INTERNAL_VHOST="/etc/nginx/sites-available/aman-internal"
# This box is shared. The agent's loopback listener must not land on a port
# another project already holds, and a vhost that fails to parse must never be
# left enabled for someone else's reload to trip over.
AGENT_VHOST_PORT=8127
if ss -ltn 2>/dev/null | grep -q "127.0.0.1:${AGENT_VHOST_PORT} " \
   && ! grep -q "listen 127.0.0.1:${AGENT_VHOST_PORT};" "${INTERNAL_VHOST}" 2>/dev/null; then
  echo "127.0.0.1:${AGENT_VHOST_PORT} is already in use by another service." >&2
  echo "Pick a free port in deploy/nginx-aman-internal.conf and AMAN_CAMARA_TOOL_URL." >&2
  exit 1
fi

# Write both vhosts, keeping a copy of whatever was enabled so a config that
# fails to parse can be rolled back instead of reloaded.
BACKUP_DIR="$(mktemp -d)"
for name in "${DOMAIN}" aman-internal; do
  [ -f "/etc/nginx/sites-available/${name}" ] && cp "/etc/nginx/sites-available/${name}" "${BACKUP_DIR}/${name}"
done
sed "s#__PHP_FPM_SOCK__#${PHP_SOCK}#" "${ROOT}/deploy/nginx-aman.conf" > "${VHOST}"
sed "s#__PHP_FPM_SOCK__#${PHP_SOCK}#" "${ROOT}/deploy/nginx-aman-internal.conf" > "${INTERNAL_VHOST}"
ln -sfn "${VHOST}" "/etc/nginx/sites-enabled/${DOMAIN}"
ln -sfn "${INTERNAL_VHOST}" "/etc/nginx/sites-enabled/aman-internal"
if ! nginx -t; then
  for name in "${DOMAIN}" aman-internal; do
    if [ -f "${BACKUP_DIR}/${name}" ]; then
      cp "${BACKUP_DIR}/${name}" "/etc/nginx/sites-available/${name}"
    else
      rm -f "/etc/nginx/sites-enabled/${name}" "/etc/nginx/sites-available/${name}"
    fi
  done
  rm -rf "${BACKUP_DIR}"
  echo "nginx rejected the new config; the previous vhosts were restored and nginx was not reloaded." >&2
  exit 1
fi
rm -rf "${BACKUP_DIR}"
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
cp "${ROOT}/deploy/systemd/aman-queue.service" "${ROOT}/deploy/systemd/aman-schedule.service" \
   "${ROOT}/deploy/systemd/aman-agent.service" /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now aman-agent.service aman-queue.service aman-schedule.service
systemctl restart aman-agent.service aman-queue.service aman-schedule.service
systemctl --no-pager --lines=3 status aman-agent.service aman-queue.service aman-schedule.service || true

log "Agent health"
# The queue worker cannot produce advice until the agent answers, so a failure
# here is reported now rather than as a silent 'advice unavailable' in the demo.
for attempt in 1 2 3 4 5 6 7 8 9 10; do
  if curl -fsS --max-time 3 http://127.0.0.1:8091/health; then
    echo
    break
  fi
  [ "${attempt}" = "10" ] && echo "WARNING: the agent service did not answer /health" >&2
  sleep 2
done

log "Done: https://${DOMAIN}"
