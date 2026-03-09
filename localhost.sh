#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOST="${HOST:-127.0.0.1}"
PORT="${PORT:-8000}"
URL="http://${HOST}:${PORT}/"
START_PAGE="${START_PAGE:-local.html}"
OPEN_URL="${URL}${START_PAGE#/}"
ENV_PATH="${ENV_PATH:-}"
LOCAL_PHP_ROOT="${ROOT}/.local/php/runtime"
LOCAL_PHP_DEB_DIR="${ROOT}/.local/php/debs"
LOCAL_PHP_BIN="${LOCAL_PHP_ROOT}/usr/bin/php8.1"
LOCAL_PHP_EXT_DIR="${LOCAL_PHP_ROOT}/usr/lib/php/20210902"
LOCAL_PHP_LIB_DIR="${LOCAL_PHP_ROOT}/usr/lib/x86_64-linux-gnu"
LOCAL_PHP_PACKAGES=(
  php8.1-cli
  php8.1-common
  php8.1-curl
  php8.1-mbstring
  php8.1-opcache
  php8.1-readline
  php8.1-sqlite3
  php8.1-gd
  libonig5
)
LOCAL_PHP_EXTENSIONS=(
  pdo
  pdo_sqlite
  sqlite3
  curl
  mbstring
  fileinfo
  gd
  exif
  iconv
  readline
)
PHP_BIN=""
PHP_LOCAL_MODE=0
ENV_LOADED=()

load_env_file() {
  local env_file="$1"
  if [[ ! -f "${env_file}" ]]; then
    return 0
  fi

  # shellcheck disable=SC1090
  set -a
  source "${env_file}"
  set +a
  ENV_LOADED+=("${env_file}")
}

if [[ -n "${ENV_PATH}" ]]; then
  load_env_file "${ENV_PATH}"
else
  load_env_file "${ROOT}/.env"
fi

bootstrap_local_php() {
  local pkg=""
  local deb_file=""

  if ! command -v apt >/dev/null 2>&1 || ! command -v dpkg-deb >/dev/null 2>&1; then
    return 1
  fi

  mkdir -p "${LOCAL_PHP_DEB_DIR}" "${LOCAL_PHP_ROOT}"

  for pkg in "${LOCAL_PHP_PACKAGES[@]}"; do
    if ! ls "${LOCAL_PHP_DEB_DIR}/${pkg}"_*_amd64.deb >/dev/null 2>&1; then
      (
        cd "${LOCAL_PHP_DEB_DIR}"
        apt download "${pkg}" >/dev/null
      ) || return 1
    fi

    deb_file="$(ls "${LOCAL_PHP_DEB_DIR}/${pkg}"_*_amd64.deb 2>/dev/null | head -n1 || true)"
    if [[ -z "${deb_file}" ]]; then
      return 1
    fi

    dpkg-deb -x "${deb_file}" "${LOCAL_PHP_ROOT}" || return 1
  done

  [[ -x "${LOCAL_PHP_BIN}" && -d "${LOCAL_PHP_EXT_DIR}" ]]
}

if command -v php >/dev/null 2>&1; then
  PHP_BIN="$(command -v php)"
elif [[ -x "${LOCAL_PHP_BIN}" ]]; then
  PHP_BIN="${LOCAL_PHP_BIN}"
  PHP_LOCAL_MODE=1
elif bootstrap_local_php; then
  PHP_BIN="${LOCAL_PHP_BIN}"
  PHP_LOCAL_MODE=1
else
  echo "[ERROR] php не знайдено. Встановіть PHP CLI або запустіть: sudo apt install php-cli"
  exit 1
fi

echo
echo "Root: ${ROOT}"
echo "URL : ${URL}"
echo "Open: ${OPEN_URL}"
echo "PHP : ${PHP_BIN}"
if [[ -n "${OPENAI_API_KEY:-}" ]]; then
  echo "AI  : OPENAI_API_KEY loaded"
else
  echo "AI  : OPENAI_API_KEY missing (offline fallback mode)"
fi
if ((${#ENV_LOADED[@]} > 0)); then
  echo "ENV : ${ENV_LOADED[*]}"
fi
echo

if command -v xdg-open >/dev/null 2>&1; then
  (xdg-open "${OPEN_URL}" >/dev/null 2>&1 &) || true
fi

if [[ "${PHP_LOCAL_MODE}" -eq 1 ]]; then
  mkdir -p "${LOCAL_PHP_ROOT}/var/sessions" "${LOCAL_PHP_ROOT}/var/tmp"
  php_args=(-n "-d" "extension_dir=${LOCAL_PHP_EXT_DIR}")
  php_args+=("-d" "session.save_path=${LOCAL_PHP_ROOT}/var/sessions")
  php_args+=("-d" "sys_temp_dir=${LOCAL_PHP_ROOT}/var/tmp")
  php_args+=("-d" "upload_tmp_dir=${LOCAL_PHP_ROOT}/var/tmp")
  for ext in "${LOCAL_PHP_EXTENSIONS[@]}"; do
    php_args+=("-d" "extension=${ext}")
  done
  exec env LD_LIBRARY_PATH="${LOCAL_PHP_LIB_DIR}:${LD_LIBRARY_PATH:-}" "${PHP_BIN}" "${php_args[@]}" -S "${HOST}:${PORT}" -t "${ROOT}" "${ROOT}/router.php"
fi

exec "${PHP_BIN}" -S "${HOST}:${PORT}" -t "${ROOT}" "${ROOT}/router.php"
