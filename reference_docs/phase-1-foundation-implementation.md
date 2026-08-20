# Phase 1 — Project foundation (implemented and auditable)

Reference:
- `DEVELOPMENT_ROADMAP.md` section 1 and section 10 (Phase 1)
- `GENERIC_HOSTINGER_LARAVEL_REACT_AI_PROMPT.md`

## Required scope from the roadmap

- Create one Laravel + React/Vite project.
- Configure MySQL, session auth, timezone, nested-path-safe routing.
- Add React routing, API client, and global validation/error handling.
- Add translation file.
- Add manifest, icons, service worker, offline indicator + update prompt.
- Add automated tests for root + nested runtime paths.

## What is in this repository now

- One Laravel app with:
  - `resources/js/app.js`
  - `resources/js/i18n/translations.js`
  - `resources/js/utils/runtime-path.js`
  - `resources/js/lib/api.js`
- Nested-path-safe SPA fallback route in `routes/web.php`.
- Session/auth + API middleware defined in `routes/api.php`.
- `.htaccess` hardening for source protection when hosting from repo root.
- App-base manifest/service-worker wiring via `resources/views/welcome.blade.php`.
- Base-path runtime helper tested by script and tests:
  - `scripts/test-runtime-paths.mjs`
  - `tests/Feature/RoutingTest.php`
- Translation parity enforcement:
  - `scripts/check-translations.mjs`

## Phase 1 acceptance commands (should pass before moving to Phase 2)

```bash
npm run translations:check
npm run runtime:paths:test
npm run hostinger:verify
npm run build
php artisan test
```

## Hostinger foundation alignment from generic prompt

- App keeps front-end and API in one repo.
- No production hardcoding in compiled JS (runtime paths derived from bundle or `window.__APP_BASE_PATH`).
- Root/public fallback behavior supports nested route rendering.

## Evidence produced

- `npm run build:verify` passes.
- `php artisan test` passes.
- `scripts/verify-hosting-readiness.mjs` confirms key deployment files are present.
- New acceptance record stored in this file and linked from `README.md`.
