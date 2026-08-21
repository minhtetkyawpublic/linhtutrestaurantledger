# Lin Htut Restaurant Ledger

Phone-first Laravel + React + Vite restaurant ledger with nested-path-safe routing for Hostinger deployments.

Local development uses the C: XAMPP MariaDB/MySQL server; production uses a
dedicated MySQL database and user configured only in the server `.env`.
PHP 8.2+ with PDO and PDO MySQL is required.

## Implemented phase focus

This repository follows the roadmap in `reference_docs/DEVELOPMENT_ROADMAP.md` and the Hostinger deployment checklist in `reference_docs/GENERIC_HOSTINGER_LARAVEL_REACT_AI_PROMPT.md`.

Current status: **The local application workflows are implemented and covered by
automated frontend, SQLite, and MySQL tests. Production deployment and physical
Android/iOS installation remain external steps. The latest owner decisions
supersede older roadmap drafts: curry categories and receipt/statement PDF
sharing are intentionally not part of the product.**

Implemented in this codebase:

- Phase 1 foundations: single Laravel + React/Vite app with runtime-derived base path helpers for nested folders.
- Functional phone-first screens for sales, unified histories, customer details
  and ledger actions, curry management, reports, staff/permission management,
  and paginated audit history.
- Compact phone layouts with centered fade-in modals for secondary forms and
  filters, capped long pickers, and collapsed low-priority report sections.
- The complete, uncropped `linhtuticon.jpg` is displayed in the app; installable
  Apple, standard, and maskable PWA variants preserve the full artwork.
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
- Complete browser localization:
  - explicit Burmese/English UI maps with untranslated-value detection
  - localized permissions, roles, errors, financial events, and reports
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
  `linhtutrestaurant_test` database as documented in `LOCAL_TEST_COMMANDS.md`):

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
- Laravel `storage/` and `bootstrap/cache/` writable by the hosting account.
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
