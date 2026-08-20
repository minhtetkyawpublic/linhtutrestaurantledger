# Phase-by-phase implementation audit

Audit target: `C:\xampp\htdocs\linhtutrestaurant`

This record distinguishes repository implementation from checks that require a
real phone or an authorized Hostinger target. Passing a narrow automated check
is not used as proof of an unrelated manual requirement.

## Phase 0 — terminology and design

Status: repository requirements implemented.

- Canonical phone wireframes and acceptance criteria:
  `phase-0-wireframes-and-acceptance.md`.
- Complete English/Myanmar UI keys are centralized in
  `resources/js/i18n/translations.js`.
- `npm run translations:check` proves exact key parity, non-empty values, and
  prevents English fallback values in the Myanmar map.
- Final preferred Burmese tone remains an owner wording decision, as intended
  by the roadmap.

## Phase 1 — foundation

Status: implemented and automated locally.

- One Laravel 12 + React/Vite application using the C: XAMPP MariaDB/MySQL
  server locally and a dedicated MySQL configuration template for production.
- PHP requirement is `^8.2`; timezone is `Asia/Yangon`.
- Session authentication, CSRF-protected mutations, runtime API path helper,
  root/nested SPA tests, global API errors, and permission-aware navigation.
- Install manifest, valid 192/512 icons, service worker with API bypass,
  static offline fallback, online/offline state, save blocking while offline,
  update-ready prompt, and History API routes that survive direct refreshes.
- Authentication refreshes the SPA CSRF token after Laravel regenerates the
  session, so protected buttons continue working immediately after login.
- Blade includes the React refresh preamble before Vite scripts; both live Vite
  development mode and compiled production mode are browser-tested.
- Direct SPA routes are permission guarded, and Settings always exposes
  language and logout controls.
- Evidence: `npm run runtime:paths:test`, `RoutingTest`,
  `ServiceWorkerSecurityTest`, and live authenticated HTTP checks.

## Phase 2 — accounts and permissions

Status: implemented and feature-tested.

- Login/logout, disabled-account enforcement, no public registration.
- Admin/cashier/viewer templates plus exact per-user permission customization.
- Staff create/edit, hidden password reset, enable/disable, role-permission API,
  audit history, and independently protected API routes.
- Production seeding never creates or resets a known-password administrator.
  `php artisan app:create-admin` uses hidden interactive password input.
- Evidence: `AuthPermissionsTest`, `AdminBootstrapTest`, and
  `PermissionAndReadinessTest`.

## Phase 3 — curry and customer setup

Status: implemented and feature-tested.

- Category and curry create/update/reprice/reorder/availability/archive.
- Customer create/search/edit/archive, opening-balance reason and ledger entry.
- Curry read access is available to authenticated create-sale staff without
  granting menu-management permission.
- User-entered values are stored without translation.
- Evidence: `CurryCustomerTest` and historical snapshot acceptance test.

## Phase 4 — sales and ledger

Status: implemented and feature-tested.

- Customer-first and explicit fully-paid walk-in flows.
- Server-captured item prices; sale discount; full/partial/unpaid/overpayment.
- Atomic sale/items/ledger work; opening-balance/customer updates are also
  transaction-wrapped.
- Customer payments, money given/lent, chronological running balance,
  append-only payment/loan correction and reversal with reasons, audit records,
  and preserved before/after
  sale item snapshots.
- Database idempotency for sales and customer-money actions plus customer-row
  locking, unique ledger-reversal protection, cross-action idempotency-key
  conflict rejection, and chronological recalculation for backdated entries.
- Evidence: `SalesLedgerTest`.

## Phase 5 — sharing and reports

Status: implemented and feature-tested.

- Localized receipt and customer statement PDFs with automatic pagination and
  an embedded Padauk font for Myanmar text.
- Native Web Share files when supported, normal download fallback otherwise.
- Statement date filter and report filters for preset/custom range, customer,
  curry/category, and payment status.
- Archived customers/curries and inactive categories remain available as
  historical report filters; reversed sales remain visible in sale history.
- Sales/debt/payment/money-lent/balance/top-curry/reversal metrics; reversed
  customer-money entries no longer inflate totals, and overpayments use the
  plain-language `Customer credit` label rather than negative unpaid values.
- Evidence: `ReportsAndSharingTest`.

## Phase 6 — quality and PWA verification

Status: automated repository gates pass; physical-device checks pending.

Automated:

- `npm run lint`
- `npm run translations:check`
- `npm run runtime:paths:test`
- `npm run hostinger:verify`
- `npm run test:ui` (18 tests covering login/localization, authenticated home,
  permission routes, settings, duplicate-submit locks, ledger corrections and
  backdating, balance refresh, saved-sale detail, and connection recovery)
- `npm run test:browser:dev` with live Vite: React refresh preamble and CSS
- `npm run test:browser` in installed Chrome at 390 x 844: production CSS,
  login, direct `/reports` refresh, English/Myanmar layouts, logout/re-login,
  console errors, horizontal overflow, and service-worker offline fallback
- `npm run build`
- `php artisan test` (63 tests, 405 assertions)
- MariaDB profile (63 tests, 405 assertions against the disposable C: XAMPP
  `linhtutrestaurant_test` database)
- migration status and sensitive-route tests

Still requiring external/manual evidence:

- Android installation on a real target device.
- iOS Add to Home Screen on a real target device.
- Native PDF share sheet on the owner's phone/browser.
- Final narrow-screen Burmese wording review by the owner.
- Production-MySQL stress/load test under expected concurrent traffic.

## Phase 7 — Hostinger preparation and deployment

Status: repository preparation implemented; deployment is not authorized or
verifiable without production facts.

- Compiled `public/build`, nested runtime paths, root/public `.htaccess`, root
  front controller, production environment example, sensitive-route denial,
  PWA assets, and readiness verifier are present.
- `DEPLOYMENT_RECORD.md` records the exact missing production facts and backup
  evidence.
- This directory is not currently a Git worktree, so a commit SHA and tracked
  build cannot yet be proven.
- No claim of Hostinger deployment success is made.

## Phase 8 — localization and finalization

Status: technical localization implemented; owner wording review pending.

- Complete UI translation maps with exact English/Myanmar key parity and no
  silent English-value fallback.
- Locale persists locally and in the authenticated user profile.
- Permission/role labels, browser errors, receipt labels, statement labels,
  and statement event names follow the selected locale.
- User-entered curry/customer/note content remains unchanged.
- Owner may revise Burmese strings without application-logic changes.

## Current local runtime constraint

The project and MySQL server/data are on C:, but `C:\xampp\php\php.exe` is PHP
8.0.30 while Laravel 12 requires PHP 8.2+. A strictly C:-only runtime requires
upgrading the PHP bundled with C: XAMPP. No project or database path points to
the old D: project tree.
