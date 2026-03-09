#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOCAL_DIR="${ROOT}/.local"
mkdir -p "${LOCAL_DIR}"

load_env_file() {
  local env_file="$1"
  if [[ -f "${env_file}" ]]; then
    set -a
    # shellcheck disable=SC1090
    source "${env_file}"
    set +a
  fi
}

load_env_file "${ROOT}/.env"

BASE_URL="${BASE_URL:-${AUTONOMY_BASE_URL:-http://127.0.0.1:${SERVER_HTTP_PORT:-8080}}}"
TELEGRAM_BOT_TOKEN="${TELEGRAM_BOT_TOKEN:-}"
TELEGRAM_CHAT_ID="${TELEGRAM_CHAT_ID:-}"
AUTONOMY_NOTIFY_SUCCESS="${AUTONOMY_NOTIFY_SUCCESS:-0}"

if command -v php >/dev/null 2>&1; then
  PHP_BIN="$(command -v php)"
elif [[ -x "${ROOT}/.local/php/runtime/usr/bin/php8.1" ]]; then
  PHP_BIN="${ROOT}/.local/php/runtime/usr/bin/php8.1"
else
  echo "[ERROR] PHP not found. Install php or runtime in .local/php."
  exit 1
fi

now_utc() {
  date -u +"%Y-%m-%dT%H:%M:%SZ"
}

log_line() {
  local level="$1"
  local message="$2"
  printf '[%s] [%s] %s\n' "$(now_utc)" "${level}" "${message}"
}

send_telegram() {
  local message="$1"
  if [[ -z "${TELEGRAM_BOT_TOKEN}" || -z "${TELEGRAM_CHAT_ID}" ]]; then
    return 0
  fi
  curl -fsS -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" \
    --data-urlencode "chat_id=${TELEGRAM_CHAT_ID}" \
    --data-urlencode "text=${message}" \
    --data-urlencode "disable_web_page_preview=true" >/dev/null || true
}

notify() {
  local level="$1"
  local message="$2"
  log_line "${level}" "${message}"
  if [[ "${level}" != "OK" || "${AUTONOMY_NOTIFY_SUCCESS}" == "1" ]]; then
    send_telegram "SvityazHOME ${level}: ${message}"
  fi
}

json_field() {
  local json="$1"
  local path="$2"
  printf '%s' "${json}" | "${PHP_BIN}" -r '
    $data = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($data)) { exit(1); }
    $path = $argv[1] ?? "";
    if ($path === "") { exit(1); }
    $segments = explode(".", $path);
    $current = $data;
    foreach ($segments as $segment) {
      if (!is_array($current) || !array_key_exists($segment, $current)) {
        exit(2);
      }
      $current = $current[$segment];
    }
    if (is_array($current)) {
      echo json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
      echo (string) $current;
    }
  ' "${path}" 2>/dev/null || true
}

run_php_task() {
  local output=""
  if ! output="$("${PHP_BIN}" "${ROOT}/tools/autonomy-tasks.php" "$@" 2>&1)"; then
    printf '%s\n' "${output}"
    return 1
  fi
  printf '%s' "${output}"
}

run_http_health() {
  local health_url="${BASE_URL%/}/api/health.php"
  curl -fsS --max-time 12 "${health_url}"
}

command_check() {
  local silent_success="${1:-0}"
  local local_json
  if ! local_json="$(run_php_task health)"; then
    notify "ERROR" "local health task failed: ${local_json}"
    return 1
  fi

  local local_status
  local_status="$(json_field "${local_json}" "status")"
  if [[ "${local_status}" != "ok" ]]; then
    notify "ERROR" "local health status=${local_status:-unknown}. payload=${local_json}"
    return 1
  fi

  local http_json
  if ! http_json="$(run_http_health)"; then
    notify "ERROR" "HTTP health endpoint unreachable at ${BASE_URL%/}/api/health.php"
    return 1
  fi

  local http_status
  http_status="$(json_field "${http_json}" "status")"
  if [[ "${http_status}" != "ok" ]]; then
    notify "ERROR" "HTTP health degraded: ${http_json}"
    return 1
  fi

  if [[ "${silent_success}" != "1" ]]; then
    notify "OK" "health checks passed for ${BASE_URL}"
  fi
  return 0
}

command_nightly() {
  local backup_json
  if ! backup_json="$(run_php_task backup "scheduled-nightly")"; then
    notify "ERROR" "nightly backup failed: ${backup_json}"
    return 1
  fi

  local backup_name backup_size
  backup_name="$(json_field "${backup_json}" "backup.name")"
  backup_size="$(json_field "${backup_json}" "backup.size")"

  local vacuum_json
  if ! vacuum_json="$(run_php_task vacuum)"; then
    notify "ERROR" "database vacuum failed: ${vacuum_json}"
    return 1
  fi

  if ! command_check "1"; then
    return 1
  fi

  notify "OK" "nightly maintenance done. backup=${backup_name:-unknown} size=${backup_size:-0}B"
  return 0
}

cron_block() {
  cat <<EOF
# BEGIN SVH AUTONOMY
*/10 * * * * cd "${ROOT}" && /usr/bin/env bash "${ROOT}/tools/autonomy.sh" check >> "${ROOT}/.local/autonomy-check.log" 2>&1
17 3 * * * cd "${ROOT}" && /usr/bin/env bash "${ROOT}/tools/autonomy.sh" nightly >> "${ROOT}/.local/autonomy-nightly.log" 2>&1
# END SVH AUTONOMY
EOF
}

command_install_cron() {
  local existing filtered block
  existing="$(crontab -l 2>/dev/null || true)"
  filtered="$(printf '%s\n' "${existing}" | awk '
    BEGIN { skip = 0 }
    /^# BEGIN SVH AUTONOMY$/ { skip = 1; next }
    /^# END SVH AUTONOMY$/ { skip = 0; next }
    skip == 0 { print }
  ')"
  block="$(cron_block)"
  printf '%s\n%s\n' "${filtered}" "${block}" | crontab -
  notify "OK" "cron installed (check each 10 min, nightly at 03:17)"
}

usage() {
  cat <<EOF
Usage: ./tools/autonomy.sh <command>

Commands:
  check         Run local + HTTP health checks
  nightly       Run backup + vacuum + health checks
  print-cron    Print recommended cron block
  install-cron  Install cron block into current user crontab
EOF
}

command="${1:-check}"

case "${command}" in
  check)
    command_check "0"
    ;;
  nightly)
    command_nightly
    ;;
  print-cron)
    cron_block
    ;;
  install-cron)
    command_install_cron
    ;;
  *)
    usage
    exit 1
    ;;
esac
