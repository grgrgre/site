#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PID_FILE="${ROOT}/.local/frankenphp-server.pid"

stopped=0

if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
  docker compose -f docker-compose.server.yml down --remove-orphans >/dev/null 2>&1 || true
  stopped=1
fi

if [[ -f "$PID_FILE" ]]; then
  pid="$(cat "$PID_FILE" 2>/dev/null || true)"
  if [[ -n "${pid:-}" ]] && kill -0 "$pid" 2>/dev/null; then
    kill "$pid" >/dev/null 2>&1 || true
    for _ in {1..10}; do
      if kill -0 "$pid" 2>/dev/null; then
        sleep 0.3
      else
        break
      fi
    done
    if kill -0 "$pid" 2>/dev/null; then
      kill -9 "$pid" >/dev/null 2>&1 || true
    fi
  fi
  rm -f "$PID_FILE"
  stopped=1
fi

if [[ "$stopped" -eq 1 ]]; then
  echo "[OK] Server stack stopped"
else
  echo "[WARN] Nothing to stop"
fi
