# Current implementation audit

Audit target: `C:\xampp\htdocs\linhtutrestaurant`

This is the current-state record. The latest owner decisions override older
phase drafts: curry categories, receipt PDFs, statement PDFs, file sharing, and
receipt previews are intentionally removed.

## Product workflows

- Session login/logout, disabled-account enforcement, CSRF protection, login
  throttling, per-route permissions, and no public registration.
- Staff, roles, direct permissions, password resets, account disable/enable,
  and database-paginated audit history.
- Curry create/edit/reprice/reorder/availability/archive. The management list
  is searchable and database-paginated; sale selectors use bounded server-side
  search.
- Customer create/search/edit/archive and reasoned opening balances. The list
  is database-paginated and customer details open on a dedicated route.
- Customer payments, money given to customers, corrections, reversals, and
  chronological ledger balances. The ledger is database-paginated.
- Named-customer and fully-paid walk-in sales, server-side price snapshots,
  discounts, partial payment, overpayment credit, correction, and reversal.
- Unified History list for sales, customer payments, and money given. It
  defaults to today, supports date/type/customer filters, is database-paginated,
  and links sales to a dedicated details page.
- Reports use SQL aggregates for sales, customer balances, payments, money
  given, and top curries. Reversed financial entries do not inflate totals;
  separate reversed-item report cards are intentionally absent.
- English and Myanmar UI maps have exact key parity; user-entered names and
  notes are never translated.
- Install manifest, full uncropped owner-provided icon, service worker, offline
  fallback, online/offline state, and root/nested deployment path support.

## Record and calculation integrity

- Sales, items, ledger entries, and audit records are transaction-wrapped.
- Sale and money-action idempotency keys reject reuse with changed payloads.
- Customer rows are locked while their ledger changes.
- Corrections append reversal/replacement entries rather than rewriting money
  history.
- Backdated entries recalculate the chronological tail in constant application
  memory; same-time entries are ordered by ID.
- Financial database columns use BIGINT. Request limits, item-count limits,
  quantity limits, and subtotal limits keep calculations inside supported
  integer and browser-safe ranges.
- Curry price/name snapshots preserve historical sales after menu edits.
- Invoice identifiers include millisecond time plus randomness and remain
  database-unique.

## Long-term performance

- Customers, curries, histories, customer ledgers, and audit records paginate
  in SQL; no growing transaction list is loaded in one response.
- Sale/report/history selectors return at most 50 matches and search on the
  server while retaining the selected record.
- Dashboard and report balances use grouped aggregate queries instead of one
  query per customer.
- Sale creation loads all selected curry records in one query.
- History/report indexes cover sale date, ledger event/date, and audit
  action/order access patterns.
- Recent dashboard activity is intentionally capped at five and report ranking
  lists at ten.

## Security and failure handling

- Authenticated mutation routes are CSRF protected and independently
  permission-gated.
- Passwords created/reset by administrators require at least 12 characters,
  mixed case, numbers, and symbols. Email addresses are normalized.
- Password reset and account disabling revoke database-backed sessions for the
  target user.
- Sessions are encrypted, HTTP-only, SameSite=Lax, and default to 12 hours;
  production must set secure cookies over HTTPS.
- Laravel emits nosniff, anti-framing, referrer, permissions, and production
  content-security headers even without Apache header configuration.
- Sensitive repository paths are denied, API requests bypass the service-worker
  cache, offline writes are blocked in the UI, and production debug mode must
  remain disabled.
- Date ranges, pagination limits, IDs, text lengths, dates, money values, and
  future/backdated financial actions are validated server-side.

## Automated evidence (2026-08-21)

- `npm run lint`: pass.
- `npm run test:ui`: 20 tests pass.
- `php artisan test --compact`: 75 tests / 451 assertions pass using the
  available PHP 8.2 runtime.
- `php vendor/bin/phpunit --configuration=phpunit.mysql.xml`: 75 tests / 451
  assertions pass against the disposable MySQL database.
- `npm run build:verify`: pass, including lint, formatting, 20 UI tests,
  translation parity, root/nested path tests, production build, service worker,
  and Hostinger readiness verification.

The tests cover permissions, login/CSRF, disabled accounts, security headers,
pagination, bounded selectors, N+1 query regressions, idempotency conflicts,
backdating, corrections, reversals, report calculations, route safety,
localization, PWA behavior, and root/nested builds.

## External/manual evidence still required

- The in-app browser was unavailable during this audit session, so a fresh
  rendered mobile walkthrough still needs to be captured when that browser is
  available. Repository Chrome scripts remain available for local execution.
- Physical Android install and iOS Add to Home Screen.
- Final Burmese wording review by the owner.
- Authorized Hostinger deployment, backup/restore proof, HTTPS cookie check,
  and production concurrency/load measurements.

## C: runtime constraint

The project and intended XAMPP installation are on `C:`. At audit time,
`C:\xampp\php\php.exe` reports PHP 8.0.30, while Laravel 12 requires PHP 8.2+.
The audit used an official portable PHP 8.2.33 runtime inside ignored
`storage/app/test-runtime/` and the current local port 8000 server uses that C:
runtime. Upgrade the bundled C: XAMPP PHP before treating the normal XAMPP setup
as complete. Do not use or modify the old D: XAMPP projects.
