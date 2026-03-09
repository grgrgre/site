#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
DEFAULT_ENV_FILE="${PROJECT_ROOT}/.env.ftps"
ENV_FILE="${FTPS_ENV_FILE:-${DEFAULT_ENV_FILE}}"

CURL_COMMON_OPTS=(
  --ftp-ssl
  --ssl-reqd
  --tlsv1.2
  --fail
  --show-error
  --disable-epsv
  --connect-timeout 15
  --max-time 180
)

die() {
  printf '[ERROR] %s\n' "$*" >&2
  exit 1
}

info() {
  printf '[INFO] %s\n' "$*" >&2
}

usage() {
  cat <<'USAGE'
Usage:
  ./tools/ftps.sh check
  ./tools/ftps.sh ls [remote_dir]
  ./tools/ftps.sh put <local_file> [remote_rel_path]
  ./tools/ftps.sh get <remote_rel_path> <local_file>

Config:
  By default reads .env.ftps from project root.
  Override with FTPS_ENV_FILE=/path/to/.env.ftps
USAGE
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "Required command not found: $1"
}

load_env() {
  [[ -f "${ENV_FILE}" ]] || die "FTPS config not found: ${ENV_FILE}. Create it from the README section 'FTPS deploy'."
  [[ -r "${ENV_FILE}" ]] || die "FTPS config is not readable: ${ENV_FILE}"

  set -a
  # shellcheck source=/dev/null
  . "${ENV_FILE}"
  set +a
}

validate_env() {
  local required=(FTPS_HOST FTPS_PORT FTPS_PROTOCOL FTPS_USER FTPS_PASSWORD FTPS_REMOTE_ROOT)
  local key
  for key in "${required[@]}"; do
    [[ -n "${!key:-}" ]] || die "Missing required variable in ${ENV_FILE}: ${key}"
  done

  [[ "${FTPS_PORT}" =~ ^[0-9]+$ ]] || die "FTPS_PORT must be numeric, got: ${FTPS_PORT}"

  local protocol
  protocol="$(printf '%s' "${FTPS_PROTOCOL}" | tr '[:upper:]' '[:lower:]')"
  case "${protocol}" in
    ftps|ftp) ;;
    *) die "Unsupported FTPS_PROTOCOL='${FTPS_PROTOCOL}'. Use 'ftps' (recommended) or 'ftp'." ;;
  esac

  if [[ "${FTPS_REMOTE_ROOT}" != /* ]]; then
    die "FTPS_REMOTE_ROOT must be an absolute path starting with '/': ${FTPS_REMOTE_ROOT}"
  fi

  local insecure="${FTPS_INSECURE:-0}"
  case "${insecure}" in
    0|1) ;;
    *) die "FTPS_INSECURE must be 0 or 1 when set (current: ${insecure})" ;;
  esac
}

canonical_path() {
  if command -v realpath >/dev/null 2>&1; then
    realpath "$1"
    return
  fi
  readlink -f "$1"
}

normalize_remote_path() {
  local input="${1:-.}"
  local root="${FTPS_REMOTE_ROOT%/}"

  if [[ -z "${root}" ]]; then
    root='/'
  fi

  if [[ "${input}" == '/' ]]; then
    printf '/'
    return
  fi

  if [[ -z "${input}" || "${input}" == '.' || "${input}" == './' ]]; then
    printf '%s' "${root}"
    return
  fi

  if [[ "${input}" == /* ]]; then
    printf '%s' "${input}"
    return
  fi

  input="${input#./}"
  if [[ "${root}" == "/" ]]; then
    printf '/%s' "${input}"
  else
    printf '%s/%s' "${root}" "${input}"
  fi
}

as_dir_path() {
  local path="$1"
  if [[ "${path}" == */ ]]; then
    printf '%s' "${path}"
  else
    printf '%s/' "${path}"
  fi
}

build_url() {
  local remote_path="$1"
  # Explicit FTPS over port 21 is enforced via CURL_COMMON_OPTS; ftp:// URL is expected here.
  printf 'ftp://%s:%s%s' "${FTPS_HOST}" "${FTPS_PORT}" "${remote_path}"
}

handle_curl_error() {
  local code="$1"
  case "${code}" in
    67)
      die "FTPS authentication failed. Check FTPS_USER/FTPS_PASSWORD in ${ENV_FILE}."
      ;;
    9)
      die "FTPS remote path denied. Check FTPS_REMOTE_ROOT in ${ENV_FILE} (many hosts require '/')."
      ;;
    7|28)
      die "Cannot connect to ${FTPS_HOST}:${FTPS_PORT}. Check host/port and network access."
      ;;
    35|58|60)
      die "FTPS TLS/SSL error. Verify FTPS mode/certificate on hosting side."
      ;;
    *)
      die "curl failed with exit code ${code}."
      ;;
  esac
}

run_curl() {
  local code=0
  local -a opts=("${CURL_COMMON_OPTS[@]}")

  if [[ "${FTPS_INSECURE:-0}" == "1" ]]; then
    opts+=(--insecure)
  fi

  set +e
  curl "${opts[@]}" --user "${FTPS_USER}:${FTPS_PASSWORD}" "$@"
  code=$?
  set -e
  if [[ ${code} -ne 0 ]]; then
    handle_curl_error "${code}"
  fi
}

is_forbidden_upload() {
  local file_abs="$1"
  local base
  base="$(basename "${file_abs}")"

  case "${base}" in
    *.env*|.env*)
      return 0
      ;;
  esac

  case "${file_abs}" in
    "${PROJECT_ROOT}/.git"|\
    "${PROJECT_ROOT}/.git/"*|\
    "${PROJECT_ROOT}/.local"|\
    "${PROJECT_ROOT}/.local/"*|\
    "${PROJECT_ROOT}/storage/backups"|\
    "${PROJECT_ROOT}/storage/backups/"*|\
    "${PROJECT_ROOT}/storage/logs"|\
    "${PROJECT_ROOT}/storage/logs/"*|\
    "${PROJECT_ROOT}/deploy"|"${PROJECT_ROOT}/deploy/"*)
      return 0
      ;;
  esac

  return 1
}

cmd_check() {
  local remote_path url
  remote_path="$(as_dir_path "$(normalize_remote_path ".")")"
  url="$(build_url "${remote_path}")"

  run_curl --silent --list-only "${url}" >/dev/null
  info "FTPS check passed for ${FTPS_HOST}:${FTPS_PORT} (${FTPS_REMOTE_ROOT})"
}

cmd_ls() {
  local requested_path="${1:-.}"
  local remote_path url
  remote_path="$(as_dir_path "$(normalize_remote_path "${requested_path}")")"
  url="$(build_url "${remote_path}")"

  run_curl --silent --list-only "${url}"
}

cmd_put() {
  local local_file="${1:-}"
  local remote_target="${2:-}"
  local local_abs remote_path url

  [[ -n "${local_file}" ]] || die "put requires <local_file>"
  [[ -f "${local_file}" ]] || die "Local file does not exist: ${local_file}"
  [[ -r "${local_file}" ]] || die "Local file is not readable: ${local_file}"

  local_abs="$(canonical_path "${local_file}")"
  if is_forbidden_upload "${local_abs}"; then
    die "Upload blocked by security guard for file: ${local_abs}"
  fi

  if [[ -z "${remote_target}" ]]; then
    remote_target="$(basename "${local_abs}")"
  fi

  remote_path="$(normalize_remote_path "${remote_target}")"
  url="$(build_url "${remote_path}")"

  run_curl --silent --upload-file "${local_abs}" "${url}" >/dev/null
  info "Uploaded: ${local_abs} -> ${remote_path}"
}

cmd_get() {
  local remote_source="${1:-}"
  local local_file="${2:-}"
  local remote_path url local_dir

  [[ -n "${remote_source}" ]] || die "get requires <remote_rel_path>"
  [[ -n "${local_file}" ]] || die "get requires <local_file>"

  remote_path="$(normalize_remote_path "${remote_source}")"
  url="$(build_url "${remote_path}")"

  local_dir="$(dirname "${local_file}")"
  mkdir -p "${local_dir}"

  run_curl --silent --output "${local_file}" "${url}"
  info "Downloaded: ${remote_path} -> ${local_file}"
}

main() {
  require_cmd curl

  local command="${1:-help}"
  case "${command}" in
    help|-h|--help)
      usage
      exit 0
      ;;
  esac

  load_env
  validate_env

  shift || true
  case "${command}" in
    check)
      cmd_check "$@"
      ;;
    ls)
      cmd_ls "$@"
      ;;
    put)
      cmd_put "$@"
      ;;
    get)
      cmd_get "$@"
      ;;
    *)
      usage
      die "Unknown command: ${command}"
      ;;
  esac
}

main "$@"
