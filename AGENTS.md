# AGENTS.md

## Project Brief

This repository contains the production website for `SvityazHOME`, a lodging property near Lake Svityaz.
The stack is mostly static frontend files plus a small PHP backend with SQLite-backed storage.

## Core Stack

- Frontend: plain `HTML`, `CSS`, `Vanilla JS`
- Backend: `PHP` endpoints in `api/`
- Data: `SQLite` plus JSON files in `storage/data/`
- Local hosting:
  - quick mode: `./localhost.sh` or `localhost.bat`
  - server-like mode: `./tools/server-host.sh`

There is no `package.json` or `composer.json` in the repo root. Do not assume a Node or Composer workflow exists.

## Important Directories

- `index.html`, `about/`, `rooms/`, `booking/`, `gallery/`, `reviews/`, `ozero-svityaz/`: public site pages
- `assets/css/`, `assets/js/`, `assets/images/`: frontend assets
- `api/`: PHP API endpoints used by forms, admin features, AI/chat, files, Telegram, health checks
- `includes/`: shared PHP helpers for admin/site content
- `svh-ctrl-x7k9/`: admin panel under a non-public path; treat the path as intentional
- `storage/data/`: persistent content and operational data
- `storage/uploads/`: uploaded/public media used by the site
- `tools/`: deploy, hosting, backup, health-check, Telegram, and automation scripts
- `tests/`: small PHP smoke/consistency checks
- `docker/`, `docker-compose.server.yml`, `Caddyfile.server`: local server-like environment

## Safety Rules

- Do not delete or bulk-rewrite `storage/` unless the task explicitly requires it.
- Do not commit secrets from `.env`, `.env.ftps`, auth files, logs, or backup archives.
- Be careful with `svh-ctrl-x7k9/`: it is a security-through-obscurity admin path already wired into the project.
- Prefer focused edits. Many pages are static HTML copies, so cross-page consistency matters.

## Common Commands

Quick local server:

```bash
./localhost.sh
```

Server-like local stack:

```bash
./tools/server-host.sh
./tools/server-health.sh
./tools/server-host-stop.sh
```

Smoke/consistency checks:

```bash
php tests/policy_consistency.php
php tests/booking_smoke.php http://127.0.0.1:8080
```

Predeploy checks:

```bash
./tools/predeploy-check.sh
```

## Working Notes For Agents

- If content/policy text changes, check both public pages and the matching API/config references.
- If booking behavior changes, verify CSRF, honeypot, rate limiting, and mail-related responses.
- If room data changes, check both `rooms/room-*/index.html` and `storage/data/rooms/room-*.json`.
- If sitemap-relevant routes change, review `sitemap.xml` and `tools/generate-sitemap.php`.
- If admin functionality changes, inspect both `svh-ctrl-x7k9/` and the matching `api/` endpoints.

## Preferred Workflow

1. Inspect the target page/API pair before editing.
2. Make minimal changes in the real source files already used by the site.
3. Run the smallest relevant check or smoke test after changes.
4. Summarize user-visible impact, operational risk, and anything not verified.
