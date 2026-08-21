# Phase 8 — Burmese and English localization + finalization

> Superseded in part: PDF-specific localization is no longer present because
> receipt and statement PDFs were removed by owner decision.

Reference:
- `DEVELOPMENT_ROADMAP.md` Section 8 and 11

## Implemented

- Complete translation maps at `resources/js/i18n/translations.js`; Myanmar
  does not inherit English UI values.
- `scripts/check-translations.mjs` enforces exact key parity, non-empty values,
  and rejects untranslated English values in the Myanmar map.
- Permission and role names are localized while user-entered names, notes,
  curry names, and customer names remain unchanged.
- Authentication/validation failures use a localized UI fallback instead of
  exposing Laravel's English messages in the Myanmar interface.
- Receipt and statement headings, fields, and ledger events are localized by
  the signed-in user's saved locale.
- PDF output uses embedded Padauk font data and automatic pagination.
- UI and PHP tests cover Myanmar rendering, error fallback, translation parity,
  PDF label parity, font embedding, and multi-page statements.

## Required remaining work (if desired by owner)

- Review Burmese wording with the owner and revise any preferred terminology or
  tone. This is a human copy decision, not a missing application feature.

## Acceptance command

```bash
npm run translations:check
```
