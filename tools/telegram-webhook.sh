#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

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

TELEGRAM_BOT_TOKEN="${TELEGRAM_BOT_TOKEN:-}"
TELEGRAM_WEBHOOK_SECRET="${TELEGRAM_WEBHOOK_SECRET:-}"
TELEGRAM_WEBHOOK_URL="${TELEGRAM_WEBHOOK_URL:-}"

if [[ -z "${TELEGRAM_BOT_TOKEN}" ]]; then
  echo "[ERROR] TELEGRAM_BOT_TOKEN is empty. Set it in .env"
  exit 1
fi

api_url() {
  local method="$1"
  printf 'https://api.telegram.org/bot%s/%s' "${TELEGRAM_BOT_TOKEN}" "${method}"
}

set_webhook() {
  local url="${1:-${TELEGRAM_WEBHOOK_URL}}"
  if [[ -z "${url}" ]]; then
    echo "[ERROR] Missing webhook URL. Pass it as arg or set TELEGRAM_WEBHOOK_URL in .env"
    exit 1
  fi

  if [[ "${url}" != https://* ]]; then
    echo "[ERROR] Webhook URL must be HTTPS"
    exit 1
  fi

  echo "[INFO] Setting webhook: ${url}"
  if [[ -n "${TELEGRAM_WEBHOOK_SECRET}" ]]; then
    curl -fsS -X POST "$(api_url setWebhook)" \
      --data-urlencode "url=${url}" \
      --data-urlencode "secret_token=${TELEGRAM_WEBHOOK_SECRET}" \
      --data-urlencode 'allowed_updates=["message","callback_query"]'
  else
    curl -fsS -X POST "$(api_url setWebhook)" \
      --data-urlencode "url=${url}" \
      --data-urlencode 'allowed_updates=["message","callback_query"]'
  fi
  echo
}

delete_webhook() {
  echo "[INFO] Deleting webhook"
  curl -fsS -X POST "$(api_url deleteWebhook)" \
    --data-urlencode 'drop_pending_updates=false'
  echo
}

webhook_info() {
  curl -fsS "$(api_url getWebhookInfo)"
  echo
}

set_commands() {
  local commands='[
    {"command":"menu","description":"Показати кнопки меню"},
    {"command":"help","description":"Список команд"},
    {"command":"status","description":"Статус сайту"},
    {"command":"today","description":"Звіт за сьогодні"},
    {"command":"pending","description":"Pending відгуки"},
    {"command":"approve","description":"Схвалити відгук: /approve ID"},
    {"command":"reject","description":"Відхилити відгук: /reject ID"},
    {"command":"add_review","description":"Додати відгук"},
    {"command":"bookings","description":"Нові заявки"},
    {"command":"latest","description":"Остання заявка"},
    {"command":"booking","description":"Деталі заявки"},
    {"command":"find","description":"Пошук заявки"},
    {"command":"reply","description":"Відповідь на заявку"},
    {"command":"change_room","description":"Змінити номер у заявці"},
    {"command":"arrivals","description":"Заїзди: today|tomorrow"},
    {"command":"departures","description":"Виїзди: today"},
    {"command":"actions","description":"Останні дії адмінки"},
    {"command":"login","description":"Вхід з нового пристрою"},
    {"command":"logout","description":"Вийти з поточного пристрою"}
  ]'

  curl -fsS -X POST "$(api_url setMyCommands)" \
    --data-urlencode "commands=${commands}"
  echo
}

usage() {
  cat <<EOF
Usage: ./tools/telegram-webhook.sh <command> [args]

Commands:
  info                 Show current webhook info
  set [url]            Set webhook to URL or TELEGRAM_WEBHOOK_URL from .env
  delete               Delete webhook
  set-commands         Register bot command hints

Required:
  TELEGRAM_BOT_TOKEN in .env

Optional:
  TELEGRAM_WEBHOOK_URL
  TELEGRAM_WEBHOOK_SECRET
EOF
}

command="${1:-info}"
case "${command}" in
  info)
    webhook_info
    ;;
  set)
    set_webhook "${2:-}"
    ;;
  delete)
    delete_webhook
    ;;
  set-commands)
    set_commands
    ;;
  *)
    usage
    exit 1
    ;;
esac
