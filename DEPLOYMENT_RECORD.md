# Deployment Record — Lin Htut Restaurant Ledger

## Local preparation verified

- Local project: `C:\xampp\htdocs\linhtutrestaurant`
- Local database: C: XAMPP MariaDB `linhtutrestaurant` at `127.0.0.1:3306`
- Application timezone: `Asia/Yangon`
- Frontend gate: `npm run build:verify`
- Backend gate: `php artisan test`
- Latest local migration: `2026_08_20_000008_expand_ledger_reason_column`
- Verification date: `2026-08-20` (`Asia/Yangon`)
- Frontend result: 18 UI tests; production build assets and service worker
  version `c1238fddd7dc` verified
- Development frontend result: live Vite/React Chrome test passes with the
  refresh preamble and application CSS loaded; Vite was stopped and
  `public/hot` removed after verification
- Browser result: Chrome mobile viewport (390 x 844) passed login, direct report
  route refresh, English/Myanmar layouts, logout/re-login, and offline fallback
- Backend result: 63 tests, 405 assertions on both the default test profile and
  the isolated C: MariaDB `linhtutrestaurant_test` profile
- Dependency audit: Composer and npm report no known vulnerabilities
- Formatting result: Prettier and Laravel Pint pass
- Local URL tested: `http://127.0.0.1:8000`
- Health URL tested: `http://127.0.0.1:8000/api/health`
- Live HTTP result: root/assets/health/service worker return 200; health reports
  `database=mysql` and `database_ok=true`; authenticated login and CSRF-protected
  post-login mutations succeed
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

- Git commit SHA: **Unavailable — this directory is not currently a Git worktree**
- Migrations applied in production: **Pending**
- Sensitive-path HTTP checks: **Automated locally; production HTTPS verification pending**
- SPA root/nested refresh checks: **Automated locally; production URL verification pending**
- Android install test: **Pending real device**
- iOS Add to Home Screen test: **Pending real device**
- Native PDF share test: **Pending real phone/browser**

Do not mark production deployment complete until every pending production field is filled and verified.
