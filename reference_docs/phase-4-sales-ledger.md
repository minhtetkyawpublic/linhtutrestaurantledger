# Phase 4 — Sales and ledger (implemented and verified)

> Superseded in part: receipt previews and PDF receipt actions were later
> removed. Sales are reviewed through the paginated unified History list and a
> dedicated sale-details route.

Reference:
- `DEVELOPMENT_ROADMAP.md` Section 10 (Phase 4)

## Scope from roadmap

- Fast sale workflow and calculations.
- Atomic creation of sales and ledger entries.
- Customer payments and money lent/returned.
- Running balance timeline and statement flows.
- Permission-controlled backdating, editing, reversal.
- Duplicate-submit protection.

## Evidence in repository

- `app/Http/Controllers/SaleController.php`
  - `store`: atomic transaction around sale + ledger append.
  - `update`: reverses prior ledger effect and applies corrected effect.
  - `reverse`: records reversal with reason and marks sale reversed.
  - walk-in guard + paid/unpaid validation (walk-in must be fully paid).
  - idempotency check for sales via `idempotency_key`.
  - receipt generation endpoint with PDF output.
- `app/Http/Controllers/CustomerController.php`
  - `recordPayment` and `recordMoneyLent` create ledger deltas with required reason.
  - `correctLedgerEntry` atomically appends a reversal and corrected payment or
    loan, preserves the original entry, and requires an audit reason.
- `app/Services/LedgerService.php`
  - transaction-independent helper for append/reverse and current balance math.
- `database/migrations/2026_08_19_000003_create_sales_and_ledger_tables.php`
- `tests/Feature/SalesLedgerTest.php`
  - partial sale behavior
  - idempotent duplicate submission
  - overpayment negative balance handling
  - walk-in full payment guard
  - backdating permission and reversal audit
  - opening balance adjustment reversal with audit trail
  - duplicate payment idempotency and chronological backdated-balance recalculation
- `app/Services/AuditService.php`
  - records staff, curry, customer, sale, payment, loan, and reversal actions.
- `2026_08_20_000005_add_ledger_idempotency.php`
  - database uniqueness plus customer-row locking protects payment/loan submissions.
- `2026_08_20_000006_add_reversal_uniqueness.php`
  - database uniqueness plus row locking prevents two reversals of the same
    ledger entry under concurrent requests.
- `2026_08_20_000007_add_report_query_indexes.php`
  - composite sale-status/date index supports active and reversed report
    range queries; foreign-key indexes already cover curry reporting.
- `2026_08_20_000008_expand_ledger_reason_column.php`
  - aligns the database reason field with the validated 500-character limit.
- An idempotency key reused for a different customer or money action is
  rejected instead of returning an unrelated existing result.
- Phone UI supports sale entry, quantity keypad input, complete saved-sale
  receipt detail, sale correction with reason, reversal, receipts, authorized
  backdated customer money actions, immediate balance refresh, timeline,
  payment/loan correction, and ledger reversal.

## What’s intentionally aligned with roadmap constraints

- Historical snapshot for sale lines: snapshot fields are stored per sale item (`curry_name_snapshot`, `unit_price_snapshot_kyat`, `line_total_kyat`).
- Reversal model is auditable: reversed ledger entry stores `reverses_entry_id` and reason/meta via service.

## Phase 4 checks

```bash
php artisan test tests/Feature/SalesLedgerTest.php
php artisan test tests/Feature/ReportsAndSharingTest.php
```
