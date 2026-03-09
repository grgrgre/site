#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BASE_URL="${BASE_URL:-http://127.0.0.1:8000}"
RUN_HTTP_CHECKS="${RUN_HTTP_CHECKS:-1}"
RUN_BOOKING_SMOKE="${RUN_BOOKING_SMOKE:-0}"
TEMP_SERVER_PID=""
TEMP_SERVER_LOG="${ROOT}/.local/predeploy-php-server.log"

PHP_BIN=""
if command -v php >/dev/null 2>&1; then
  PHP_BIN="$(command -v php)"
elif [[ -x "${ROOT}/.local/php/runtime/usr/bin/php8.1" ]]; then
  PHP_BIN="${ROOT}/.local/php/runtime/usr/bin/php8.1"
else
  echo "[ERROR] PHP binary not found (php or .local/php/runtime/usr/bin/php8.1)." >&2
  exit 1
fi

echo "[INFO] Root: ${ROOT}"
echo "[INFO] PHP : ${PHP_BIN}"
echo "[INFO] URL : ${BASE_URL}"

cleanup_temp_server() {
  if [[ -n "${TEMP_SERVER_PID}" ]] && kill -0 "${TEMP_SERVER_PID}" 2>/dev/null; then
    kill "${TEMP_SERVER_PID}" >/dev/null 2>&1 || true
    wait "${TEMP_SERVER_PID}" 2>/dev/null || true
  fi
}
trap cleanup_temp_server EXIT

is_local_base_url() {
  [[ "${BASE_URL}" =~ ^http://(127\.0\.0\.1|localhost)(:[0-9]+)?(/.*)?$ ]]
}

extract_local_host_port() {
  local target="${BASE_URL#http://}"
  target="${target%%/*}"
  local host
  local port

  if [[ "${target}" == *:* ]]; then
    host="${target%%:*}"
    port="${target##*:}"
  else
    host="${target}"
    port="8000"
  fi

  printf '%s %s\n' "${host}" "${port}"
}

base_url_reachable() {
  curl -s -o /dev/null --max-time 2 "${BASE_URL}/"
}

start_temp_php_server() {
  local host="$1"
  local port="$2"

  mkdir -p "${ROOT}/.local"
  echo "[INFO] Starting temporary PHP server at http://${host}:${port}"
  "${PHP_BIN}" -S "${host}:${port}" -t "${ROOT}" "${ROOT}/router.php" >"${TEMP_SERVER_LOG}" 2>&1 &
  TEMP_SERVER_PID="$!"

  local i
  for i in $(seq 1 40); do
    if base_url_reachable; then
      echo "[OK] Temporary PHP server is ready"
      return 0
    fi
    if ! kill -0 "${TEMP_SERVER_PID}" 2>/dev/null; then
      break
    fi
    sleep 0.25
  done

  echo "[ERROR] Failed to start temporary PHP server for ${BASE_URL}" >&2
  if [[ -f "${TEMP_SERVER_LOG}" ]]; then
    echo "[INFO] Last server log lines:" >&2
    tail -n 80 "${TEMP_SERVER_LOG}" >&2 || true
  fi
  exit 1
}

check_http_status() {
  local url="$1"
  local expected="$2"
  local actual
  actual="$(curl -sS -o /dev/null -w '%{http_code}' "${url}")"
  if [[ "${actual}" != "${expected}" ]]; then
    echo "[HTTP ERROR] ${url} expected ${expected}, got ${actual}" >&2
    exit 1
  fi
}

echo "[STEP] PHP syntax lint"
while IFS= read -r -d '' file; do
  "${PHP_BIN}" -l "${file}" >/dev/null
done < <(find "${ROOT}" -type f -name '*.php' -print0)

echo "[STEP] Policy consistency test"
"${PHP_BIN}" "${ROOT}/tests/policy_consistency.php" >/dev/null

echo "[STEP] Sitemap refresh"
"${PHP_BIN}" "${ROOT}/tools/generate-sitemap.php" >/dev/null

echo "[STEP] JSON validation"
python3 - <<'PY'
import json
from pathlib import Path
bad = []
for p in sorted(Path('.').rglob('*.json')):
    try:
        json.loads(p.read_text(encoding='utf-8'))
    except Exception as exc:
        bad.append((str(p), str(exc)))
if bad:
    for path, error in bad:
        print(f"[JSON ERROR] {path}: {error}")
    raise SystemExit(1)
print("[OK] JSON files are valid")
PY

echo "[STEP] Python compile check"
python3 - <<'PY'
import py_compile
from pathlib import Path
for p in sorted(Path('.').rglob('*.py')):
    py_compile.compile(str(p), doraise=True)
print("[OK] Python files compile")
PY

echo "[STEP] Shell syntax check"
while IFS= read -r -d '' file; do
  bash -n "${file}"
done < <(find "${ROOT}" -type f -name '*.sh' -print0)

echo "[STEP] Sensitive artifact guard"
mapfile -d '' ROOT_ARTIFACTS < <(find "${ROOT}" -maxdepth 1 -type f \
  \( -iname '*.zip' -o -iname '*.sql' -o -iname '*.sqlite' -o -iname '*.db' \) -print0)
if (( ${#ROOT_ARTIFACTS[@]} > 0 )); then
  echo "[SECURITY ERROR] Remove sensitive artifacts from web root:" >&2
  for item in "${ROOT_ARTIFACTS[@]}"; do
    rel="${item#${ROOT}/}"
    echo "  - ${rel}" >&2
  done
  exit 1
fi

echo "[STEP] HTML structure check"
python3 - <<'PY'
from pathlib import Path
bad = []
for p in sorted(Path('.').rglob('*.html')):
    raw = p.read_text(encoding='utf-8', errors='ignore').lower()
    if raw.count('<style') != raw.count('</style>'):
        bad.append((str(p), 'style tag mismatch'))
    if raw.count('<script') != raw.count('</script>'):
        bad.append((str(p), 'script tag mismatch'))
if bad:
    for path, error in bad:
        print(f"[HTML ERROR] {path}: {error}")
    raise SystemExit(1)
print("[OK] HTML tag structure looks consistent")
PY

if [[ "${RUN_HTTP_CHECKS}" == "1" ]]; then
  if ! base_url_reachable; then
    if is_local_base_url; then
      read -r local_host local_port < <(extract_local_host_port)
      if [[ ! "${local_port}" =~ ^[0-9]+$ ]]; then
        echo "[ERROR] Cannot parse port from BASE_URL=${BASE_URL}" >&2
        exit 1
      fi
      start_temp_php_server "${local_host}" "${local_port}"
    else
      echo "[ERROR] HTTP checks require a reachable BASE_URL=${BASE_URL}" >&2
      exit 1
    fi
  fi

  echo "[STEP] HTTP smoke checks"
  check_http_status "${BASE_URL}/" "200"
  check_http_status "${BASE_URL}/rooms/" "200"
  check_http_status "${BASE_URL}/gallery/" "200"
  check_http_status "${BASE_URL}/reviews/" "200"
  check_http_status "${BASE_URL}/svh-ctrl-x7k9" "200"
  check_http_status "${BASE_URL}/api/health.php" "200"
  check_http_status "${BASE_URL}/api/telegram.php" "200"
  check_http_status "${BASE_URL}/storage/data/reviews.json" "403"
  check_http_status "${BASE_URL}/storage/data/content-changes.json" "403"
  check_http_status "${BASE_URL}/storage/data/room-images.json" "403"
  check_http_status "${BASE_URL}/storage/data/rooms/room-1.json" "403"
  check_http_status "${BASE_URL}/storage/data/svityazhome.db" "403"
  check_http_status "${BASE_URL}/storage/data/demo.json" "403"
  check_http_status "${BASE_URL}/storage/backups/smoke-test.zip" "403"
  check_http_status "${BASE_URL}/accidental-leak.zip" "403"
  echo "[OK] Core pages are reachable"
fi

if [[ "${RUN_BOOKING_SMOKE}" == "1" ]]; then
  echo "[STEP] Booking API smoke test"
  BASE_URL="${BASE_URL}" "${PHP_BIN}" "${ROOT}/tests/booking_smoke.php" "${BASE_URL}" >/dev/null
  echo "[OK] Booking smoke test passed"
fi

echo "[DONE] Pre-deploy checks passed"
