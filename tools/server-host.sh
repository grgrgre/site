#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

HTTP_PORT="${SERVER_HTTP_PORT:-8080}"
BASE_URL="http://127.0.0.1:${HTTP_PORT}"
PID_FILE="${ROOT}/.local/frankenphp-server.pid"
LOG_FILE="${ROOT}/.local/frankenphp-server.log"
FRANKENPHP_BIN="${FRANKENPHP_BIN:-${HOME}/.local/bin/frankenphp}"
PHPRC_FILE="${PHPRC_FILE:-${ROOT}/docker/php/php.ini}"

load_env_file() {
  local env_file="$1"
  if [[ -f "$env_file" ]]; then
    # shellcheck disable=SC1090
    set -a
    source "$env_file"
    set +a
  fi
}

wait_http_ok() {
  local url="$1"
  local attempts="${2:-30}"
  local i code
  for ((i=1; i<=attempts; i++)); do
    code="$(curl -sS -o /dev/null -w '%{http_code}' "${url}/" || true)"
    if [[ "$code" == "200" || "$code" == "301" || "$code" == "302" ]]; then
      return 0
    fi
    sleep 1
  done
  return 1
}

start_with_docker() {
  echo "[INFO] Mode: docker (nginx + php-fpm)"
  docker compose -f docker-compose.server.yml up -d --build
  if ! wait_http_ok "$BASE_URL" 40; then
    echo "[ERROR] Stack started but HTTP is not responding at ${BASE_URL}"
    docker compose -f docker-compose.server.yml logs --tail=120
    exit 1
  fi
  echo "[OK] Stack started: ${BASE_URL}/"
  echo "[INFO] Stop: ./tools/server-host-stop.sh"
  echo "[INFO] Logs: docker compose -f docker-compose.server.yml logs -f"
}

start_with_frankenphp() {
  mkdir -p "${ROOT}/.local"

  if [[ ! -x "$FRANKENPHP_BIN" ]]; then
    echo "[ERROR] Docker не знайдено, і FrankenPHP теж відсутній: ${FRANKENPHP_BIN}"
    echo "[HINT] Встановіть Docker або FrankenPHP."
    exit 1
  fi

  if [[ ! -f "$PHPRC_FILE" ]]; then
    echo "[ERROR] PHP ini файл не знайдено: ${PHPRC_FILE}"
    exit 1
  fi

  if [[ -f "$PID_FILE" ]]; then
    old_pid="$(cat "$PID_FILE" 2>/dev/null || true)"
    if [[ -n "${old_pid:-}" ]] && kill -0 "$old_pid" 2>/dev/null; then
      echo "[WARN] FrankenPHP вже працює (PID: $old_pid)."
      echo "[INFO] URL: ${BASE_URL}/"
      exit 0
    fi
    rm -f "$PID_FILE"
  fi

  export SVH_ROOT="$ROOT"
  export SERVER_HTTP_PORT="$HTTP_PORT"
  export PHPRC="$PHPRC_FILE"

  echo "[INFO] Mode: frankenphp (server-level fallback)"
  echo "[INFO] PHPRC: ${PHPRC_FILE}"
  "$FRANKENPHP_BIN" start \
    --config "${ROOT}/Caddyfile.server" \
    --adapter caddyfile \
    --pidfile "$PID_FILE" \
    >"$LOG_FILE" 2>&1 || {
      echo "[ERROR] FrankenPHP start failed"
      tail -n 120 "$LOG_FILE" || true
      exit 1
    }

  if ! wait_http_ok "$BASE_URL" 40; then
    echo "[ERROR] FrankenPHP не підняв HTTP на ${BASE_URL}"
    echo "[INFO] Last logs:"
    tail -n 120 "$LOG_FILE" || true
    exit 1
  fi

  echo "[OK] FrankenPHP started: ${BASE_URL}/"
  echo "[INFO] PID: $(cat "$PID_FILE")"
  echo "[INFO] Stop: ./tools/server-host-stop.sh"
  echo "[INFO] Logs: tail -f ${LOG_FILE}"
}

echo "[INFO] Root: ${ROOT}"
echo "[INFO] Target URL: ${BASE_URL}/"

load_env_file "${ROOT}/.env"

if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
  start_with_docker
else
  start_with_frankenphp
fi
