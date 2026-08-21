# Deployment Record — Lin Htut Restaurant Ledger

## Local preparation verified

- Local project: `C:\xampp\htdocs\linhtutrestaurant`
- Local database: C: XAMPP MariaDB `linhtutrestaurant` at `127.0.0.1:3306`
- Application timezone: `Asia/Bangkok`
- Frontend gate: `npm run build:verify`
- Backend gate: `php artisan test`
- Latest repository migration: `2026_08_21_000011_remove_curry_categories`
- Verification date: `2026-08-21` (`Asia/Bangkok`)
- Frontend result: 20 UI tests; production build assets and service worker
  version `81b95eb874e1` verified
- Development frontend result: the C:-project Vite server is available for
  local development; the production build gate also passes without relying on
  React refresh.
- Compact-layout result: isolated Chrome phone preview at 390 x 844 passes
  without horizontal overflow; report filters render as a centered fade-in
  modal, and the captured brand icon is generated from `linhtuticon.jpg`
- Browser result: Chrome mobile viewport (390 x 844) passed login, direct report
  route refresh, English/Myanmar layouts, logout/re-login, and offline fallback
- Backend result: 75 tests, 451 assertions on both the default test profile and
  the isolated MariaDB/MySQL `linhtutrestaurant_test` profile
- Dependency audit: Composer and npm report no known vulnerabilities; the
  removed PDF feature's deprecated TCPDF dependency is no longer installed
- Formatting result: Prettier and Laravel Pint pass
- Local URL tested: `http://127.0.0.1:8000`
- Health URL tested: `http://127.0.0.1:8000/api/health`
- Live HTTP result: root/assets/health/service worker return 200; health reports
  `database_ok=true` without exposing the database driver; authenticated login
  and CSRF-protected post-login mutations succeed
- C: Apache hardening result: `/.env` is denied and compiled assets are served.
  The application front controller is reached, but C: Apache cannot execute
  Laravel until its bundled PHP 8.0.30 is upgraded to PHP 8.2 or newer.

## Production facts required before deployment

- Deployment type (fresh install or update): **Pending owner confirmation**
- Final public URL: **Pending**
- Hostinger absolute deployment path: **Pending**
- Document root can target Laravel `public/`: **Pending**
- MySQL database/user already created: **Pending**
- Existing production data/uploads requiring preservation: **Pending**
- Deployment authorization: **Pending**

## Backup record for an update

- Database backup path: **Not applicable until production target is confirmed**
- Database backup timestamp/size: **Pending**
- Private upload backup path: **Pending / none currently identified**
- Restore procedure tested: **Pending**

## Release record

- Git commit SHA: **Current audit changes are pending their final verified commit**
- Migrations applied in production: **Pending**
- Sensitive-path HTTP checks: **Automated locally; production HTTPS verification pending**
- SPA root/nested refresh checks: **Automated locally; production URL verification pending**
- Android install test: **Pending real device**
- iOS Add to Home Screen test: **Pending real device**
- Removed receipt/statement PDF features: **Confirmed absent by route and UI tests**

Do not mark production deployment complete until every pending production field is filled and verified.
