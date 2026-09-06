#!/usr/bin/env bash
#
# AMAN local development runner.
#
# Prepares the backend and the Electron renderer, then runs every process the
# prototype needs in one terminal with prefixed, per-service logs. Ctrl-C stops
# all of them.
#
#   ./dev.sh                 backend services + Electron control-room app
#   ./dev.sh --backend-only  Laravel processes only
#   ./dev.sh --frontend-only Electron app only, against a backend you started
#   ./dev.sh --help          all options
#
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND="$ROOT/backend"
FRONTEND="$ROOT/frontend"
LOG_DIR="$ROOT/.dev-logs"
NODE_MAJOR=24

RUN_BACKEND=1
RUN_FRONTEND=1
DO_INSTALL=1
FRESH=0
ASSUME_YES=0

SERVICE_PIDS=()
SERVICE_NAMES=()
TAIL_PIDS=()

# ---------------------------------------------------------------- output ----

if [[ -t 1 ]]; then
  B=$'\033[1m'; DIM=$'\033[2m'; R=$'\033[0m'
  RED=$'\033[31m'; GRN=$'\033[32m'; YLW=$'\033[33m'; BLU=$'\033[34m'
  MAG=$'\033[35m'; CYN=$'\033[36m'
else
  B=""; DIM=""; R=""; RED=""; GRN=""; YLW=""; BLU=""; MAG=""; CYN=""
fi
TAG_COLORS=("$CYN" "$MAG" "$BLU" "$GRN" "$YLW")

step() { printf '%s==>%s %s\n' "$B$GRN" "$R" "$*"; }
info() { printf '    %s\n' "$*"; }
warn() { printf '%s !! %s%s\n' "$YLW" "$*" "$R" >&2; }
die()  { printf '%s !! %s%s\n' "$RED" "$*" "$R" >&2; exit 1; }

usage() {
  cat <<'TXT'
AMAN local development runner

Usage: ./dev.sh [options]

Options:
  -b, --backend-only    Run only the Laravel processes (serve, reverb, queue, scheduler).
  -f, --frontend-only   Run only the Electron app; assumes a backend is already up.
  -s, --skip-install    Do not run "composer install" or "npm ci".
      --fresh           Rebuild the schema with "migrate:fresh". Destroys all local data.
  -y, --yes             Do not prompt for confirmation (used with --fresh).
  -h, --help            Show this help.

Processes started (native, no containers):
  api        php artisan serve            http://localhost:8000
  reverb     php artisan reverb:start     ws://localhost:8080
  queue      php artisan queue:work
  scheduler  php artisan schedule:work
  desktop    npm run dev                  Vite on :3000 + the Electron window

Logs are written to .dev-logs/<service>.log and mirrored to this terminal.
TXT
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    -b|--backend-only)  RUN_FRONTEND=0 ;;
    -f|--frontend-only) RUN_BACKEND=0 ;;
    -s|--skip-install)  DO_INSTALL=0 ;;
    --fresh)            FRESH=1 ;;
    -y|--yes)           ASSUME_YES=1 ;;
    -h|--help)          usage; exit 0 ;;
    *)                  usage >&2; die "Unknown option: $1" ;;
  esac
  shift
done

# --------------------------------------------------------------- helpers ----

port_busy() { (exec 3<>"/dev/tcp/127.0.0.1/$1") >/dev/null 2>&1; }

require_free_port() {
  local port=$1 who=$2
  if port_busy "$port"; then
    die "Port $port is already in use, but $who needs it. Stop the other process (ss -ltnp 'sport = :$port') and retry."
  fi
}

wait_for_http() {
  local url=$1 tries=${2:-60} i
  for ((i = 0; i < tries; i++)); do
    if curl -fs -o /dev/null --max-time 2 "$url"; then return 0; fi
    sleep 1
  done
  return 1
}

# Resolve a Node that satisfies frontend/.nvmrc. Prefers the current node, then
# nvm, then any installed nvm v24 directory.
ensure_node() {
  local current=""
  if command -v node >/dev/null 2>&1; then
    current="$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0)"
    if [[ ${current:-0} -ge $NODE_MAJOR ]]; then return 0; fi
  fi

  local nvm_dir="${NVM_DIR:-$HOME/.nvm}"
  if [[ -s "$nvm_dir/nvm.sh" ]]; then
    set +eu
    # shellcheck disable=SC1090,SC1091
    . "$nvm_dir/nvm.sh"
    cd "$FRONTEND" && nvm use >/dev/null 2>&1 || nvm use "$NODE_MAJOR" >/dev/null 2>&1
    cd "$ROOT"
    set -eu
  fi

  if ! command -v node >/dev/null 2>&1 || [[ $(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0) -lt $NODE_MAJOR ]]; then
    local candidate
    candidate="$(ls -d "$nvm_dir"/versions/node/v"$NODE_MAJOR".* 2>/dev/null | sort -V | tail -1 || true)"
    if [[ -n $candidate && -x "$candidate/bin/node" ]]; then
      PATH="$candidate/bin:$PATH"
      export PATH
    fi
  fi

  local have
  have="$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0)"
  [[ ${have:-0} -ge $NODE_MAJOR ]] || die "Node $NODE_MAJOR+ is required (frontend/.nvmrc), found ${have:-none}. Install it with: nvm install $NODE_MAJOR"
}

start_service() {
  local name=$1 dir=$2; shift 2
  local log="$LOG_DIR/$name.log"
  local color="${TAG_COLORS[${#SERVICE_PIDS[@]} % ${#TAG_COLORS[@]}]}"

  # Job control (set -m, enabled below) puts each service in its own process
  # group, so cleanup can kill the whole tree by negating the pid. Without it,
  # "php artisan serve" and npm's children outlive the parent we tracked.
  : >"$log"
  ( cd "$dir" && exec "$@" ) </dev/null >"$log" 2>&1 &
  local pid=$!
  SERVICE_PIDS+=("$pid")
  SERVICE_NAMES+=("$name")

  tail -n +1 -F "$log" 2>/dev/null | sed -u "s/^/${color}$(printf '%-9s' "$name")${R}│ /" &
  TAIL_PIDS+=("$!")

  info "$(printf '%-9s' "$name") pid $pid  ${DIM}$log${R}"
}

cleanup() {
  local code=$?
  trap - INT TERM HUP EXIT
  set +m   # stop bash announcing "[1]+ Terminated" for every service we kill

  if [[ ${#SERVICE_PIDS[@]} -gt 0 ]]; then
    local noun="services"
    if [[ ${#SERVICE_PIDS[@]} -eq 1 ]]; then noun="service"; fi
    printf '\n'
    step "Stopping ${#SERVICE_PIDS[@]} $noun"
    local pid
    for pid in "${TAIL_PIDS[@]}"; do kill "$pid" 2>/dev/null || true; done
    for pid in "${SERVICE_PIDS[@]}"; do
      kill -TERM -- -"$pid" 2>/dev/null || kill -TERM "$pid" 2>/dev/null || true
      pkill -TERM -P "$pid" 2>/dev/null || true
    done
    sleep 1
    for pid in "${SERVICE_PIDS[@]}"; do
      kill -KILL -- -"$pid" 2>/dev/null || kill -KILL "$pid" 2>/dev/null || true
    done
    info "Stopped."
  fi
  exit "$code"
}

# ------------------------------------------------------------- preflight ----

step "Checking tools"
command -v php >/dev/null 2>&1 || die "php is not installed. AMAN needs PHP 8.3+."
PHP_OK="$(php -r 'echo PHP_VERSION_ID >= 80300 ? 1 : 0;')"
[[ $PHP_OK == 1 ]] || die "PHP 8.3+ is required, found $(php -r 'echo PHP_VERSION;')."
command -v composer >/dev/null 2>&1 || die "composer is not installed."
command -v curl >/dev/null 2>&1 || die "curl is not installed."
info "php $(php -r 'echo PHP_VERSION;') · composer $(composer --version --no-ansi 2>/dev/null | awk '{print $3}')"

if [[ $RUN_FRONTEND == 1 ]]; then
  ensure_node
  info "node $(node -v) · npm $(npm -v)"
  if [[ -z ${DISPLAY:-} && -z ${WAYLAND_DISPLAY:-} ]]; then
    warn "No DISPLAY or WAYLAND_DISPLAY is set, so the Electron window cannot open. Use ./dev.sh --backend-only on a headless machine."
  fi
fi

if [[ $RUN_BACKEND == 1 ]]; then
  require_free_port 8000 "the Laravel dev server"
  require_free_port 8080 "Reverb"
fi
if [[ $RUN_FRONTEND == 1 ]]; then require_free_port 3000 "the Vite renderer"; fi

mkdir -p "$LOG_DIR"

# ----------------------------------------------------------------- setup ----

if [[ $RUN_BACKEND == 1 ]]; then
  step "Preparing the backend"

  if [[ ! -f "$BACKEND/.env" ]]; then
    cp "$BACKEND/.env.example" "$BACKEND/.env"
    info "Created backend/.env from the example."
    ( cd "$BACKEND" && php artisan key:generate --ansi --no-interaction )
    warn "backend/.env is new: set DB_* for your MySQL, AMAN_DEMO_ENABLED=true, and OPENAI_API_KEY before the response brief will work."
  fi

  if [[ $DO_INSTALL == 1 && ! -d "$BACKEND/vendor" ]]; then
    info "Installing PHP dependencies…"
    ( cd "$BACKEND" && composer install --no-interaction )
  fi

  ( cd "$BACKEND" && php artisan config:clear --quiet )

  if ! ( cd "$BACKEND" && php artisan db:show >/dev/null 2>&1 ); then
    die "Cannot reach the database. Start MySQL 8+, create the database named in backend/.env (DB_DATABASE, default 'aman'), and confirm DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD."
  fi

  if [[ $FRESH == 1 ]]; then
    if [[ $ASSUME_YES == 0 ]]; then
      read -r -p "$(printf '%s' "${YLW}Drop every table and re-migrate? All local rehearsal data is lost. [y/N] ${R}")" reply
      [[ ${reply,,} == y* ]] || die "Aborted."
    fi
    ( cd "$BACKEND" && php artisan migrate:fresh --force --no-interaction )
  else
    ( cd "$BACKEND" && php artisan migrate --force --no-interaction )
  fi

  if ! grep -qE '^AMAN_DEMO_ENABLED=true' "$BACKEND/.env"; then
    warn "AMAN_DEMO_ENABLED is not true in backend/.env. The simulation endpoints stay disabled and the console will have no events to show."
  fi
fi

if [[ $RUN_FRONTEND == 1 && $DO_INSTALL == 1 && ! -d "$FRONTEND/node_modules" ]]; then
  step "Installing renderer dependencies"
  ( cd "$FRONTEND" && npm ci )
fi

# ------------------------------------------------------------------- run ----

trap cleanup INT TERM HUP EXIT
set -m   # each background service becomes its own process-group leader

if [[ $RUN_BACKEND == 1 ]]; then
  step "Starting backend services"
  start_service api       "$BACKEND" php artisan serve --host=127.0.0.1 --port=8000
  start_service reverb    "$BACKEND" php artisan reverb:start
  start_service queue     "$BACKEND" php artisan queue:work --sleep=1 --tries=3 --timeout=65
  start_service scheduler "$BACKEND" php artisan schedule:work

  step "Waiting for the backend"
  if wait_for_http "http://127.0.0.1:8000/up" 60; then
    info "http://localhost:8000/up is healthy."
  else
    warn "The backend did not answer /up within 60s. Check the api log above; leaving the services running."
  fi
fi

if [[ $RUN_FRONTEND == 1 ]]; then
  step "Starting the control-room app"
  start_service desktop "$FRONTEND" npm run dev
fi

cat <<TXT

${B}AMAN is running.${R}
  Demo API    ${DIM}http://localhost:8000/api/v1/demo/simulations${R}
  Health      ${DIM}http://localhost:8000/up${R}
  Renderer    ${DIM}http://localhost:3000${R}
  Reverb      ${DIM}ws://localhost:8080${R}
  Logs        ${DIM}$LOG_DIR${R}

Press ${B}Ctrl-C${R} to stop everything.

TXT

wait -n "${SERVICE_PIDS[@]}" 2>/dev/null || true
warn "A service exited. Shutting the rest down."
