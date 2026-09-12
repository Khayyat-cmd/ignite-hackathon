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
ssh "${HOST}" "mkdir -p ${ROOT}/backend ${ROOT}/web/downloads ${ROOT}/deploy ${ROOT}/agent"
rsync -az --delete \
  --exclude '.env' --exclude 'vendor/' --exclude 'node_modules/' \
  --exclude 'storage/logs/*' --exclude 'storage/framework/cache/*' \
  --exclude 'storage/framework/sessions/*' --exclude 'storage/framework/views/*' \
  --exclude 'storage/app/private/*' \
  "${REPO}/backend/" "${HOST}:${ROOT}/backend/"

log "Upload CAMARA agent"
rsync -az --delete \
  --exclude '.venv/' --exclude '__pycache__/' --exclude '.env' \
  "${REPO}/ml/agent/" "${HOST}:${ROOT}/agent/"

log "Upload deploy scripts, console build, APK"
rsync -az "${REPO}/deploy/" "${HOST}:${ROOT}/deploy/"
rsync -az --delete --exclude 'downloads/' "${REPO}/frontend/dist/" "${HOST}:${ROOT}/web/"
rsync -az "${REPO}/responder-mobile/build/app/outputs/flutter-apk/app-release.apk" \
  "${HOST}:${ROOT}/web/downloads/aman-responder.apk"

log "Agent and gateway tokens"
# Generated on the server on first deploy and reused after that, so neither
# token is ever typed, printed, or committed.
read -r AGENT_TOKEN GATEWAY_TOKEN <<<"$(ssh "${HOST}" "bash -s" <<'EOF'
set -euo pipefail
ENV_FILE=/var/www/aman/agent/agent.env
read_env() { [ -f "$ENV_FILE" ] && grep -m1 "^$1=" "$ENV_FILE" | cut -d= -f2- || true; }
AGENT_TOKEN="$(read_env AMAN_AGENT_SERVICE_TOKEN)"
GATEWAY_TOKEN="$(read_env AMAN_CAMARA_TOOL_TOKEN)"
[ -n "$AGENT_TOKEN" ] || AGENT_TOKEN="$(openssl rand -hex 32)"
[ -n "$GATEWAY_TOKEN" ] || GATEWAY_TOKEN="$(openssl rand -hex 32)"
printf '%s %s\n' "$AGENT_TOKEN" "$GATEWAY_TOKEN"
EOF
)"
: "${AGENT_TOKEN:?failed to obtain an agent service token}"
: "${GATEWAY_TOKEN:?failed to obtain a CAMARA gateway token}"

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
set_env AMAN_AGENT_URL http://127.0.0.1:8091
set_env AMAN_AGENT_SERVICE_TOKEN '${AGENT_TOKEN}'
set_env AMAN_CAMARA_GATEWAY_TOKEN '${GATEWAY_TOKEN}'
$( [ -n "${OPENAI_API_KEY:-}" ] && echo "set_env OPENAI_API_KEY '${OPENAI_API_KEY}'" )
$( [ -n "${CAMARA_API_KEY:-}" ] && echo "set_env CAMARA_API_KEY '${CAMARA_API_KEY}'" )
EOF

log "Agent environment"
ssh "${HOST}" "bash -s" <<EOF
set -euo pipefail
ENV_FILE=${ROOT}/agent/agent.env
touch "\${ENV_FILE}"
set_env() { grep -q "^\$1=" "\${ENV_FILE}" && sed -i "s#^\$1=.*#\$1=\$2#" "\${ENV_FILE}" || printf '%s=%s\n' "\$1" "\$2" >> "\${ENV_FILE}"; }
set_env AMAN_AGENT_HOST 127.0.0.1
set_env AMAN_AGENT_PORT 8091
set_env AMAN_AGENT_SERVICE_TOKEN '${AGENT_TOKEN}'
set_env AMAN_CAMARA_MODE backend
set_env AMAN_CAMARA_TOOL_URL http://127.0.0.1:8127/api/internal/agent/camara
set_env AMAN_CAMARA_TOOL_TOKEN '${GATEWAY_TOKEN}'
set_env AMAN_AGENT_TIMEOUT_SECONDS 35
$( [ -n "${OPENAI_API_KEY:-}" ] && echo "set_env OPENAI_API_KEY '${OPENAI_API_KEY}'" )
$( [ -n "${OPENAI_MODEL:-}" ] && echo "set_env OPENAI_MODEL '${OPENAI_MODEL}'" )
grep -q '^OPENAI_API_KEY=..' "\${ENV_FILE}" || { echo 'OPENAI_API_KEY is not set on the server; pass it once to this script' >&2; exit 1; }
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
printf 'agent    '; ssh "${HOST}" "curl -fsS --max-time 4 http://127.0.0.1:8091/health" || echo 'unreachable'
printf '\ngateway  '; curl -sS -o /dev/null -w '%{http_code} (403 expected: loopback only)\n' \
  "https://${DOMAIN}/api/internal/agent/camara"
