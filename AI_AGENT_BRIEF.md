# AI Agent Brief: Single CSS Policy (SvityazHOME)

Last updated: 2026-03-12

## Current CSS Architecture
- Public website pages use exactly one stylesheet: `/assets/css/site.css`.
- Expected pattern in each public HTML `<head>`:
  - `<link rel="preload" href="/assets/css/site.css" as="style">`
  - `<link rel="stylesheet" href="/assets/css/site.css">`
- Service worker precache also uses `/assets/css/site.css`.

## Scope
- Applies to public pages (`/`, `/about/`, `/booking/`, `/gallery/`, `/reviews/`, `/ozero-svityaz/`, `/rooms/`, `/rooms/room-*/`).
- Does not apply to admin/mini-app styles:
  - `svh-ctrl-x7k9/assets/admin.css`
  - `telegram-app/app.css`
  - `telegram-app-v4/app.css`

## Rules For Any AI Agent
- Do not reintroduce `critical.css`, `styles.min.css`, `site-polish.css`, `site-refresh.css`, or `styles.css` for public pages.
- Keep one stylesheet include per public page.
- If changing site visuals, edit only `assets/css/site.css`.
- If caching logic changes, keep `sw.js` precache in sync with `/assets/css/site.css`.

## Quick Verification Commands
```bash
rg -n 'rel="stylesheet"' 404.html index.html about/index.html booking/index.html gallery/index.html reviews/index.html ozero-svityaz/index.html rooms/index.html rooms/room-*/index.html
rg -n '/assets/css/(critical|styles\.min|site-polish|site-refresh|styles)\.css' --glob '*.html'
rg -n '/assets/css/site\.css' --glob '*.html' sw.js
```
