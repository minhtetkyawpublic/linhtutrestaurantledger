# Phase 7 — Hostinger preparation and deployment

Reference:
- `DEVELOPMENT_ROADMAP.md` Section 10 (Phase 7)
- `GENERIC_HOSTINGER_LARAVEL_REACT_AI_PROMPT.md`

## Implemented deployment-readiness artifacts

- `scripts/verify-hosting-readiness.mjs`
  - checks required files and key deployment assets.
- `public/build/manifest.json` is present and validated against its hashed entries.
- `public/manifest.webmanifest` and `public/service-worker.js` are present and
  validated. Git tracking/commit evidence is pending because this directory is
  not a Git worktree.
- `public/.htaccess` and repo-root `/.htaccess` hardening rules present.
- `.env.production.example` created for shared-hosting production bootstrap.
- Composer declares PHP 8.2+, PDO, and PDO MySQL platform requirements so an
  incompatible Hostinger PHP configuration fails during installation.
- `DEPLOYMENT_RECORD.md` records local evidence and keeps production facts,
  backups, URLs, migrations, and device checks explicitly pending.
- Production administrator bootstrap uses `php artisan app:create-admin`; the
  production seeder never installs a known default password.
- Application routing returns 404 for sensitive repository paths even when the
  PHP development server (rather than Apache `.htaccess`) handles the request.
- Runtime/path checks for nested deployment included:
  - `scripts/test-runtime-paths.mjs`
  - `resources/js/utils/runtime-path.js`
- The readiness verifier checks required PHP extensions and exact secure
  production environment defaults. Local C: Apache denies `.env` and serves
  compiled assets; its PHP runtime must be upgraded from 8.0.30 to 8.2+ before
  the Laravel front controller can run there.

## Current deployment alignment

- Supports both:
  - preferred public-root deployment; and
  - shared-hosting fallback where the repo is inside `public_html/<folder>`.
- SPA fallback and API namespace routing are configured.

## Remaining phase-7 operational tasks (outside repository code)

- Confirm whether deployment is a fresh install or update.
- Confirm final DB/app path (`/<folder>/`) and ensure `.env` production values.
- Confirm backups and credentials handling.
- Run production deployment commands (`composer install`, `.env`, `php artisan migrate`, `php artisan optimize`) on Hostinger.
- Verify health/API, nested route loads, and sensitive path hardening via HTTP checks.

## Useful command checklist

```bash
npm run build:verify
php artisan test
git status --short
```
