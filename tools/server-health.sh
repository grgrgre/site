#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:${SERVER_HTTP_PORT:-8080}}"

check() {
  local path="$1"
  local code
  code="$(curl -sS -o /dev/null -w '%{http_code}' "${BASE_URL}${path}")"
  if [[ "$code" != "200" ]]; then
    echo "[FAIL] ${path} -> HTTP ${code}"
    return 1
  fi
  echo "[OK] ${path}"
}

check "/"
check "/about/"
check "/gallery/"
check "/api/policy.php"
check "/api/health.php"

health_json="$(curl -sS "${BASE_URL}/api/health.php" || true)"
if ! printf '%s' "${health_json}" | grep -q '"status":"ok"'; then
  echo "[FAIL] /api/health.php status is not ok"
  echo "[INFO] Raw health payload: ${health_json}"
  exit 1
fi
echo "[OK] health payload status=ok"

echo "[DONE] Health checks passed for ${BASE_URL}"
