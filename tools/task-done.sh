#!/usr/bin/env bash

set -u

try_play_file() {
  local file_path="$1"
  [[ -f "$file_path" ]] || return 1

  if command -v paplay >/dev/null 2>&1; then
    paplay "$file_path" >/dev/null 2>&1 && return 0
  fi

  if command -v aplay >/dev/null 2>&1; then
    aplay "$file_path" >/dev/null 2>&1 && return 0
  fi

  return 1
}

play_sound() {
  local sound_type="$1"

  if [[ -n "${DONE_SOUND_FILE:-}" ]]; then
    try_play_file "$DONE_SOUND_FILE" && return 0
  fi

  if command -v canberra-gtk-play >/dev/null 2>&1; then
    if [[ "$sound_type" == "success" ]]; then
      canberra-gtk-play -i complete >/dev/null 2>&1 && return 0
    else
      canberra-gtk-play -i dialog-error >/dev/null 2>&1 && return 0
    fi
  fi

  if [[ "$sound_type" == "success" ]]; then
    try_play_file "/usr/share/sounds/freedesktop/stereo/complete.oga" && return 0
  else
    try_play_file "/usr/share/sounds/freedesktop/stereo/dialog-error.oga" && return 0
  fi

  try_play_file "/usr/share/sounds/alsa/Front_Center.wav" && return 0

  # Terminal bell fallback
  printf '\a'
}

send_notification() {
  local title="$1"
  local body="$2"
  local kind="${3:-success}"
  local urgency="normal"
  local icon="dialog-information"
  local timeout="7000"

  case "$kind" in
    start)
      urgency="${DONE_NOTIFY_URGENCY_START:-low}"
      icon="${DONE_NOTIFY_ICON_START:-system-run}"
      timeout="${DONE_NOTIFY_TIMEOUT_START:-3500}"
      ;;
    success)
      urgency="${DONE_NOTIFY_URGENCY_SUCCESS:-normal}"
      icon="${DONE_NOTIFY_ICON_SUCCESS:-dialog-information}"
      timeout="${DONE_NOTIFY_TIMEOUT_SUCCESS:-7000}"
      ;;
    error)
      urgency="${DONE_NOTIFY_URGENCY_ERROR:-critical}"
      icon="${DONE_NOTIFY_ICON_ERROR:-dialog-error}"
      timeout="${DONE_NOTIFY_TIMEOUT_ERROR:-12000}"
      ;;
  esac

  if [[ "${DONE_NOTIFY:-1}" == "0" ]]; then
    return 0
  fi

  if command -v notify-send >/dev/null 2>&1; then
    local desktop_entry="${DONE_NOTIFY_DESKTOP_ENTRY:-org.gnome.Terminal}"
    notify-send -a "SvityazHOME Tasks" -u "$urgency" -i "$icon" -t "$timeout" \
      -h "string:desktop-entry:${desktop_entry}" \
      "$title" "$body" >/dev/null 2>&1 || true
    return 0
  fi

  if command -v zenity >/dev/null 2>&1; then
    zenity --notification --text="${title}: ${body}" >/dev/null 2>&1 || true
  fi
}

format_command() {
  local escaped=""
  printf -v escaped '%q ' "$@"
  printf '%s' "${escaped% }"
}

show_usage() {
  cat <<'EOF'
Usage:
  ./tools/task-done.sh --test
  ./tools/task-done.sh <command> [args...]

Examples:
  ./tools/task-done.sh --test
  ./tools/task-done.sh ./localhost.sh
  ./tools/task-done.sh bash -lc "php -S 127.0.0.1:8000 router.php"

Optional:
  DONE_SOUND_FILE=/path/to/sound.oga ./tools/task-done.sh <command>
  DONE_NOTIFY_START=1 ./tools/task-done.sh <command>
EOF
}

if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
  show_usage
  exit 0
fi

if [[ "${1:-}" == "--test" || $# -eq 0 ]]; then
  play_sound "success"
  send_notification "SvityazHOME" "Тестовий сигнал завершення" "success"
  exit 0
fi

start_ts="$(date +%s)"
command_text="$(format_command "$@")"
if [[ "${DONE_NOTIFY_START:-1}" == "1" ]]; then
  send_notification "Запуск дії" "$command_text" "start"
fi
"$@"
exit_code=$?
end_ts="$(date +%s)"
elapsed="$((end_ts - start_ts))"

if [[ $exit_code -eq 0 ]]; then
  play_sound "success"
  send_notification "Завдання завершено" "${command_text} (${elapsed}s)" "success"
else
  play_sound "error"
  send_notification "Команда завершилась з помилкою" "Код ${exit_code}: ${command_text} (${elapsed}s)" "error"
fi

exit "$exit_code"
