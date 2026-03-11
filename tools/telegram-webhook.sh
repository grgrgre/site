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
TELEGRAM_MINIAPP_URL="${TELEGRAM_MINIAPP_URL:-https://svityazhome.com.ua/telegram-app-v4/}"

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
  local commands
  commands="$(cat <<'JSON'
[
  {"command":"menu","description":"Головне меню з кнопками"},
  {"command":"app","description":"Відкрити Telegram App заявок"},
  {"command":"bookings","description":"Останні заявки"},
  {"command":"latest","description":"Відкрити останню заявку"},
  {"command":"pending","description":"Відгуки на модерації"},
  {"command":"reply","description":"Відповісти гостю по заявці"},
  {"command":"change_room","description":"Змінити номер у заявці"},
  {"command":"today","description":"Підсумок на сьогодні"},
  {"command":"tomorrow","description":"Підсумок на завтра"},
  {"command":"free_rooms","description":"Вільні номери на дати"},
  {"command":"find","description":"Пошук заявки"},
  {"command":"status","description":"Стан бота і сайту"},
  {"command":"help","description":"Повна довідка"},
  {"command":"login","description":"Вхід з нового пристрою"},
  {"command":"logout","description":"Вийти з цього пристрою"}
]
JSON
)"

  echo "[INFO] Updating bot commands"
  curl -fsS -X POST "$(api_url setMyCommands)" \
    --data-urlencode "commands=${commands}"
  echo

  echo "[INFO] Updating bot menu button"
  curl -fsS -X POST "$(api_url setChatMenuButton)" \
    --data-urlencode "menu_button={\"type\":\"web_app\",\"text\":\"Заявки\",\"web_app\":{\"url\":\"${TELEGRAM_MINIAPP_URL}\"}}"
  echo

  echo "[INFO] Updating bot descriptions"
  curl -fsS -X POST "$(api_url setMyShortDescription)" \
    --data-urlencode 'short_description=Заявки SvityazHOME в Telegram App'
  echo
  curl -fsS -X POST "$(api_url setMyDescription)" \
    --data-urlencode 'description=SvityazHOME: Telegram App для заявок, деталей бронювання, пошуку і швидких дій.'
  echo
}

usage() {
  cat <<EOF
Usage: ./tools/telegram-webhook.sh <command> [args]

Commands:
  info                 Show current webhook info
  set [url]            Set webhook to URL or TELEGRAM_WEBHOOK_URL from .env
  delete               Delete webhook
  set-commands         Register commands, menu button and bot descriptions
  setup-ui             Same as set-commands

Required:
  TELEGRAM_BOT_TOKEN in .env

Optional:
  TELEGRAM_WEBHOOK_URL
  TELEGRAM_WEBHOOK_SECRET
  TELEGRAM_MINIAPP_URL
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
  setup-ui)
    set_commands
    ;;
  *)
    usage
    exit 1
    ;;
esac
