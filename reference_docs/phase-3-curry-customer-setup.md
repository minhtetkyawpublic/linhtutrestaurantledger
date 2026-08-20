# Phase 3 — Curry and customer setup (implemented and verified)

Reference:
- `DEVELOPMENT_ROADMAP.md` Section 10 (Phase 3)

## Scope implemented

- Curry categories
- Curry items
- Customer creation/search/update
- Opening balance tracking
- Archive/available toggles and status flags

## Current evidence

- `app/Http/Controllers/CurryCategoryController.php`
  - list/create/update
- `app/Http/Controllers/CurryItemController.php`
  - list/create/update/archive
- `app/Http/Controllers/CustomerController.php`
  - `index`, `store`, `update`
  - search by `q` with `name` or `phone_number`
  - opening balance with required reason when non-zero
  - customer archive status fields (`is_archived` / `is_active`)
- `app/Models/Customer.php`
  - balance and ledger relations
- `database/migrations/2026_08_19_000002_create_curry_and_customer_tables.php`
- `tests/Feature/CurryCustomerTest.php`
  - covers curry category/item creation and customer workflow paths
- Phone UI supports curry creation, repricing, category, display order,
  availability, archive, plus customer create/edit/search/archive.

## Notes from roadmap

- "Merge duplicate customers only as a later enhancement" — not implemented in this phase (and intentionally deferred).

## Phase 3 acceptance checks

```bash
npm run build
php artisan test
php artisan migrate:status
```

Run:

```bash
php artisan test tests/Feature/CurryCustomerTest.php
```
