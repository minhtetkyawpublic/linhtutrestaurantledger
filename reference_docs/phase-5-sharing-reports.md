# Phase 5 — Sharing and reports (implemented and wired in UI shell)

Reference:
- `DEVELOPMENT_ROADMAP.md` Section 10 (Phase 5)

## What’s implemented in this repository

- Sale receipt PDF generation endpoint:
  - `app/Http/Controllers/SaleController::receipt`
- Customer statement PDF generation endpoint:
  - `app/Http/Controllers/CustomerController::statement`
- PDF output uses TCPDF with an embedded Padauk Myanmar font, selected-locale
  labels and events, and automatic page breaks for long statements.
- Reports endpoints:
  - `app/Http/Controllers/ReportController::salesSummary`
  - `app/Http/Controllers/ReportController::customerBalances`
  - `app/Http/Controllers/ReportController::topCurries`
- Report filters:
  - ranges: today, yesterday, this_week, this_month, custom `from/to`
  - customer filtering, curry/curry category filters, paid status filtering
- Service-worker and manifest are present for install/share workflows:
  - `public/manifest.webmanifest`
  - `public/service-worker.js`

## Implemented roadmap behavior in this phase

- Native share-sheet download/fallback actions are now wired in the UI shell for:
  - receipt fetch/share by sale id
  - customer statement fetch/share with optional date range
- Reports UI exposes roadmap filters for date range, customer, curry/category,
  and paid/partial/unpaid status.
- Archived customers/curries and inactive categories remain selectable so old
  sales can still be investigated.
- Report totals exclude reversed customer payments/money-lent entries and show
  both reversed sale and reversed adjustment counts. Corrected entries report
  their replacement amounts, and overpayment is labelled `Customer credit`.
- Service-worker compatibility still ensures API requests remain uncached.

## Evidence

```bash
php artisan test tests/Feature/ReportsAndSharingTest.php
```
