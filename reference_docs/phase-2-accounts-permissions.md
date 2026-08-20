# Phase 2 — Accounts and permissions (implemented and verified)

Reference:
- `DEVELOPMENT_ROADMAP.md` Section 10 (Phases 2)

## Expected behavior

- Login/logout flow exists and disabled users cannot continue authenticated activity.
- No public registration endpoint is exposed.
- Roles are seeded (`admin`, `cashier`, `viewer`).
- Granular permissions are assigned to roles.
- API routes are middleware-gated by permission for every sensitive area.
- Admin screens and permission-sensitive operations are hidden/blocked when unauthorized.

## Current implementation evidence in repo

- `app/Http/Controllers/AuthController.php`
  - `login`, `logout`, `session`, `setLocale`
  - disabled users are rejected and logged out immediately
- `app/Http/Middleware/RequirePermission.php`
  - rejects unauthenticated and unauthorized API callers with 403.
- `database/seeders/RolesPermissionsSeeder.php`
  - seeds all roadmap-aligned permissions:
    - `view_dashboard`
    - `create_sale`
    - `view_sales_history`
    - `backdate_sale`
    - `edit_sale`
    - `delete_reverse_sale`
    - `view_customers`
    - `create_edit_customers`
    - `record_customer_payment`
    - `record_money_given_lent`
    - `correct_reverse_ledger`
    - `view_customer_statements`
    - `view_reports`
    - `manage_curry_items`
    - `manage_staff_and_permissions`
    - `view_audit_history`
  - seeds role templates: `admin`, `cashier`, `viewer`
- `routes/api.php`
  - each route area is explicitly wrapped by `permission:*` middleware.
- `app/Http/Controllers/AdminPermissionController.php`
  - create/edit staff, apply customizable permission templates, reset passwords,
    enable/disable accounts, update role permissions, and read audit history.
- `app/Console/Commands/CreateAdminUser.php`
  - hidden interactive production administrator bootstrap; no password argument.
- Default documented login is local/testing only; production seeding never creates
  or resets a known-password administrator.
- `tests/Feature/AuthPermissionsTest.php`
  - login/session behavior + permission rejections/authorizations
- `resources/js/lib/permissions.js`
  - permission-aware navigation filtering in client shell

## Phase 2 follow-up fix to make required permission list exact

The roadmap expects `record_money_given_lent`; current seeder and route middleware are aligned to this key.

## Go/No-go checks for Phase 2

Run:

```bash
php artisan test
php artisan db:seed --class=RolesPermissionsSeeder --force
```

The seeded permission matrix should be visible from:

```bash
php artisan tinker --execute="dump(App\\Models\\Permission::pluck('name')->sort()->values())"
```
