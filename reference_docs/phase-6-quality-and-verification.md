# Phase 6 — Quality and verification

Reference:
- `DEVELOPMENT_ROADMAP.md` Section 10 (Phase 6)
- `GENERIC_HOSTINGER_LARAVEL_REACT_AI_PROMPT.md` deployment verification section

## Completed items in this milestone

- Deployment/path checks:
  - `npm run lint`
  - `npm run translations:check`
  - `npm run runtime:paths:test`
  - `npm run hostinger:verify`
  - `npm run build`
- API/feature validation:
  - permissions
  - sales/ledger behavior
  - customer/payment/report endpoints
  - service worker/API bypass check
  - database idempotency for sales and customer money actions
  - chronological running-balance recalculation for backdated entries
  - valid 192/512 PWA icons and an update-ready prompt
- React UI component validation (`npm run test:ui`):
  - guest login form
  - complete Myanmar guest UI and localized server-error fallback
  - successful login and authenticated dashboard
  - bottom-navigation access to the new-sale screen
  - immediate duplicate-sale submission lock
  - customer-ledger date-filter request
  - offline financial-save warning and successful retry after reconnection
  - permission route guards, settings, ledger correction/backdating, immediate
    balance refresh, and complete saved-sale details
- Live Vite Chrome validation (`npm run test:browser:dev`):
  - React refresh preamble detected correctly
  - application CSS loads without runtime or console errors
- Real Chrome production-build validation (`npm run test:browser`):
  - 390 x 844 phone viewport with no horizontal overflow
  - compiled styling and no Vite React preamble/console error
  - login, direct `/reports` refresh, English/Myanmar layouts, and logout
  - service-worker offline navigation fallback
- Financial/PDF integrity:
  - database uniqueness for ledger reversals
  - reversal-aware reports
  - multi-page TCPDF output with embedded Padauk Myanmar font
- PWA integrity:
  - generated, build-versioned service worker
  - API requests always bypass caches at root and nested paths

## Not-yet-complete items (external/manual work)

- Android/iOS installation still requires physical-device confirmation.
- The native share sheet still requires the owner's real phone/browser.
- Final Burmese wording remains an owner review.
- Database uniqueness and row locking are implemented and feature-tested, but a
  production-MySQL load/stress test remains pending.

## Recommended next QA commands

```bash
npm run build:verify
php artisan test
npm run test:browser
npm run test:browser:dev  # only while Vite is running
```

Service worker check:

```bash
Manual review of `public/service-worker.js`:
 - `shouldBypassCache` returns early for API paths.
 - navigation/page responses are not stored as authenticated responses.
```
