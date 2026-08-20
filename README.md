# Lin Htut Restaurant Ledger

Phone-first Laravel + React + Vite restaurant ledger with nested-path-safe routing for Hostinger deployments.

Local development uses the C: XAMPP MariaDB/MySQL server; production uses a
dedicated MySQL database and user configured only in the server `.env`.
PHP 8.2+ with PDO and PDO MySQL is required.

## Implemented phase focus

This repository follows the roadmap in `reference_docs/DEVELOPMENT_ROADMAP.md` and the Hostinger deployment checklist in `reference_docs/GENERIC_HOSTINGER_LARAVEL_REACT_AI_PROMPT.md`.

Current status: **Phases 0–5 and the repository work for Phase 8 are implemented. Phase 6 automated gates pass; physical Android/iOS installation and native-share checks still require real phones. Phase 7 production deployment remains pending until the owner supplies the final Hostinger URL/layout/database facts and authorizes deployment. Final Burmese tone remains owner-reviewable.**

Implemented in this codebase:

- Phase 1 foundations: single Laravel + React/Vite app with runtime-derived base path helpers for nested folders.
- Functional phone-first screens for sales, customers, curry management, ledger actions, reports, PDF sharing, staff/permission management, and audit history.
- Audited sale correction/reversal and ledger correction flows with chronological running-balance recalculation.
- Database-backed duplicate protection for sales, payments, and money-lent actions.
- Runtime and deployment verification checks (`npm run build:verify`), including ESLint, PWA icons, runtime paths, and compiled assets.
- Real Chrome mobile-browser verification (`npm run test:browser`) covering the
  production CSS/JavaScript bundle, routing, authentication, localization, and
  offline fallback.
- Shared-hosting-safe root hardening for fallback deployments:
  - `/.htaccess`
  - `index.php` at repository root
- Production environment starter (`.env.production.example`) aligned to Hostinger workflow.
- Complete browser and PDF localization:
  - explicit Burmese/English UI maps with untranslated-value detection
  - localized permissions, roles, errors, receipt/statement labels and events
  - TCPDF pagination with embedded OFL-licensed Padauk Myanmar font.
- Phase 0 wireframes and acceptance criteria drafted in:
  - `reference_docs/phase-0-wireframes-and-acceptance.md`
- Phase 1 foundation checklist and acceptance evidence added in:
  - `reference_docs/phase-1-foundation-implementation.md`
- Phase 2 accounts/permissions acceptance evidence added in:
  - `reference_docs/phase-2-accounts-permissions.md`
- Phase 3 curry/customer implementation evidence added in:
  - `reference_docs/phase-3-curry-customer-setup.md`
- Phase 4 sales and ledger implementation evidence added in:
  - `reference_docs/phase-4-sales-ledger.md`
- Phase 5 sharing/reports evidence added in:
  - `reference_docs/phase-5-sharing-reports.md`
- Phase 6 quality/verification evidence added in:
  - `reference_docs/phase-6-quality-and-verification.md`
- Phase 7 hostinger preparation evidence added in:
  - `reference_docs/phase-7-hostinger-preparation.md`
- Phase 8 localization/finalization evidence added in:
  - `reference_docs/phase-8-localization-and-finalization.md`

## Quick validation commands

- Frontend/runtime checks + host-ready verification + production build:

```bash
npm run build:verify
```

- Laravel test suite:

```bash
php artisan test
```

- Full MariaDB/MySQL test profile (after creating the disposable
  `linhtutrestaurant_test` database as documented in `LOCAL_TEST_COMMANDS.txt`):

```bash
php vendor/bin/phpunit --configuration=phpunit.mysql.xml
```

- Hostinger verifier only:

```bash
npm run hostinger:verify
```

## Hostinger deployment notes (production)

When deploying to shared hosting (project kept in `public_html/<folder>`), keep:

- one `.env` value set for production:
  - `APP_ENV=production`
  - `APP_TIMEZONE=Asia/Yangon`
  - `APP_URL=https://example.com/<folder>/`
  - `SESSION_COOKIE` unique per app
  - `SESSION_PATH=/<folder>/`
  - `SESSION_SECURE_COOKIE=true`
- `public/build/` tracked in git for PHP-only runtime.
- `storage/framework/cache/` writable so TCPDF can cache its generated font
  definition on first PDF request.
- Route and runtime base paths remain folder-aware for PWA manifest/service worker/API calls.
- Seed roles/permissions, then create the first production administrator without exposing a password in shell history:

```bash
php artisan db:seed --class=Database\\Seeders\\RolesPermissionsSeeder --force
php artisan app:create-admin
```

The documented `admin@example.com` / `ChangeMe123!` account is created only in local/testing environments and an existing password is never reset by the seeder.

## Files added for hosting safety

- `index.php` (repository-root entry point)
- `.htaccess` (repository-root fallback hardening)
- `scripts/verify-hosting-readiness.mjs`
- `scripts/test-runtime-paths.mjs`
- `scripts/check-translations.mjs`
- `tests/Feature/HostingSecurityTest.php`
- `tests/Feature/ServiceWorkerSecurityTest.php`
- `.env.production.example`

See `package.json` scripts for command wiring.
