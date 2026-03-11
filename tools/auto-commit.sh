#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
LOCK_FILE="/tmp/site-auto-commit.lock"
BRANCH="${AUTO_COMMIT_BRANCH:-main}"

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  exit 0
fi

cd "$REPO_DIR"

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  exit 0
fi

if ! git diff --quiet || ! git diff --cached --quiet || [ -n "$(git ls-files --others --exclude-standard)" ]; then
  git add -A

  if ! git diff --cached --quiet; then
    timestamp="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
    git commit -m "chore: auto-commit $timestamp"
    git push origin "$BRANCH"
  fi
fi
