#!/usr/bin/env bash
# Runs LOCALLY. Builds both artifacts, ships them to the VPS, and provisions.
#
#   OPENAI_API_KEY=... DB_PASSWORD=... bash deploy/release.sh
#
# OPENAI_API_KEY is only needed the first time (or when it changes); it is written
# straight into the server .env and never printed.
set -euo pipefail

HOST="${HOST:-root@62.171.175.172}"
DOMAIN="${DOMAIN:-aman.baraaelbaba.com}"
API_URL="https://${DOMAIN}/api/v1/demo"
ROOT="/var/www/aman"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

log() { printf '\n== %s\n' "$*"; }

log "Operator console build"
( cd "${REPO}/frontend"
  export NVM_DIR="$HOME/.nvm"; . "$NVM_DIR/nvm.sh"; nvm use 24 >/dev/null
  VITE_API_URL="${API_URL}" npm run build )

log "Responder APK build"
( cd "${REPO}/responder-mobile"
  flutter build apk --release --dart-define="API_URL=${API_URL}" --dart-define=ALLOW_SERVER_CONFIGURATION=false )

# The database password only has to be supplied on the first deploy; after that the
# server's own .env is the record, so redeploys need neither a stored copy nor a reset.
if [ -z "${DB_PASSWORD:-}" ]; then
  DB_PASSWORD="$(ssh "${HOST}" "grep -m1 '^DB_PASSWORD=' ${ROOT}/backend/.env 2>/dev/null | cut -d= -f2-" || true)"
  export DB_PASSWORD
fi
: "${DB_PASSWORD:?no DB_PASSWORD given and none found on the server}"

log "Upload backend"
ssh "${HOST}" "mkdir -p ${ROOT}/backend ${ROOT}/web/downloads ${ROOT}/deploy"
rsync -az --delete \
  --exclude '.env' --exclude 'vendor/' --exclude 'node_modules/' \
  --exclude 'storage/logs/*' --exclude 'storage/framework/cache/*' \
  --exclude 'storage/framework/sessions/*' --exclude 'storage/framework/views/*' \
  --exclude 'storage/app/private/*' \
  "${REPO}/backend/" "${HOST}:${ROOT}/backend/"

log "Upload deploy scripts, console build, APK"
rsync -az "${REPO}/deploy/" "${HOST}:${ROOT}/deploy/"
rsync -az --delete --exclude 'downloads/' "${REPO}/frontend/dist/" "${HOST}:${ROOT}/web/"
rsync -az "${REPO}/responder-mobile/build/app/outputs/flutter-apk/app-release.apk" \
  "${HOST}:${ROOT}/web/downloads/aman-responder.apk"

log "Server environment"
ssh "${HOST}" "bash -s" <<EOF
set -euo pipefail
cd ${ROOT}/backend
if [ ! -f .env ]; then
  cp .env.example .env
fi
set_env() { grep -q "^\$1=" .env && sed -i "s#^\$1=.*#\$1=\$2#" .env || printf '%s=%s\n' "\$1" "\$2" >> .env; }
set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL https://${DOMAIN}
set_env FRONTEND_URL https://${DOMAIN}
set_env FRONTEND_ORIGINS https://${DOMAIN}
set_env LOG_LEVEL info
set_env DB_CONNECTION mysql
set_env DB_HOST 127.0.0.1
set_env DB_PORT 3306
set_env DB_DATABASE aman
set_env DB_USERNAME aman
set_env DB_PASSWORD '${DB_PASSWORD:?set DB_PASSWORD}'
set_env QUEUE_CONNECTION database
set_env CACHE_STORE database
set_env SESSION_DRIVER database
set_env AMAN_DEMO_ENABLED true
set_env NOKIA_REACHABILITY_ENABLED true
$( [ -n "${OPENAI_API_KEY:-}" ] && echo "set_env OPENAI_API_KEY '${OPENAI_API_KEY}'" )
$( [ -n "${CAMARA_API_KEY:-}" ] && echo "set_env CAMARA_API_KEY '${CAMARA_API_KEY}'" )
EOF

if [ -f "${REPO}/backend/storage/app/private/firebase-service-account.json" ]; then
  log "Upload Firebase service account"
  # Piped through ssh rather than scp: the server's sftp subsystem rejects the write.
  ssh "${HOST}" "mkdir -p ${ROOT}/backend/storage/app/private && rm -f ${ROOT}/backend/storage/app/private/firebase-service-account.json && cat > ${ROOT}/backend/storage/app/private/firebase-service-account.json" \
    < "${REPO}/backend/storage/app/private/firebase-service-account.json"
fi

log "Provision"
ssh "${HOST}" "DB_PASSWORD='${DB_PASSWORD}' DOMAIN='${DOMAIN}' bash ${ROOT}/deploy/provision.sh"

log "Verify"
curl -sS -o /dev/null -w 'console  %{http_code}\n' "https://${DOMAIN}/"
curl -sS -o /dev/null -w 'api      %{http_code}\n' "https://${DOMAIN}/api/v1/demo/responders"
curl -sSI "https://${DOMAIN}/downloads/aman-responder.apk" | head -1
