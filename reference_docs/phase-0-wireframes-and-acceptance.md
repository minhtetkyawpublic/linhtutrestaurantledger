# Phase 0 — Confirm terminology and design

> Historical wireframe. Later owner decisions remove curry categories,
> receipt/statement PDFs, sharing, and receipt previews. See
> `IMPLEMENTATION_AUDIT.md` for current acceptance evidence.

This document captures the first milestone from `DEVELOPMENT_ROADMAP.md` for this repository.

## 0.1 Burmese terminology baseline

We keep all user-facing interface text in one file:

- `resources/js/i18n/translations.js`

Parity is currently enforced by:

- `npm run translations:check`
- `scripts/check-translations.mjs`

## 0.2 Low-fidelity phone wireframes (v1)

### New Sale screen

```
+--------------------------------------------------+
| Lin Htut Restaurant Ledger                      X |
+--------------------------------------------------+
| [Customer search | + ] [Walk-in Customer]         |
+--------------------------------------------------+
| [Curry A    +] [Curry B    +] [Curry C    +]     |
| [Curry D    +] [Curry E    +] [Curry F    +]     |
| [Search curries ...]                              |
+--------------------------------------------------+
| Qty      /  -  2  +                            |
| Price    ________   (captured at time of sale)     |
+--------------------------------------------------+
| Order items                                  [ + ] |
| 1) Curry A   1 x 1200   1200                    |
| 2) Curry C   2 x 700    1400                    |
+--------------------------------------------------+
| Discount        [________]                         |
| Paid amount     [________]                         |
| Total                    2200                     |
| Debt Added             2100  (if partial payment) |
| [Date/time picker] (read-only default = now)      |
+--------------------------------------------------+
| [Note]                                          + |
| [Save sale]                                     + |
+--------------------------------------------------+
```

### Customer detail screen

```
+--------------------------------------------------+
| Customer: Aye Aye                                 |
| Phone: 09XXXXXXXX                                |
+--------------------------------------------------+
| Balance: Customer owes shop — 1,500 ကျပ်             |
| [New Sale] [Customer Pays Shop] [Gets Money]       |
|                                                  |
| Activity (latest first)                           |
| 2026-08-19  New Sale        +2 items   +1200    |
| 2026-08-18  Payment          -500        -500    |
| 2026-08-17  Money lent       +300        +300    |
|                                                  |
| Filters: [From] [To]                              |
| [Export statement (PDF)] [Share]                   |
+--------------------------------------------------+
```

### Home screen

```
+--------------------------------------------------+
| Today's sales  | Total debt  |
|  [value]       |   [value]    |
+--------------------------------------------------+
| Quick customer search                            |
| [New Sale] [Quick actions...]                    |
| Recent activity                                  |
+--------------------------------------------------+
```

## 0.3 Acceptance criteria before schema work

Before moving to schema/model work, all of these must be satisfied:

1. Bilingual terminology is centralized in a single file.
2. English and Burmese translation keys are exactly matched.
3. No hardcoded user-facing labels in React components outside translation helper.
4. New Sale, Customer, Quick Sale, and statement actions are represented in terms.
5. Developer can open this file and confirm the intended first UI layout.

## 0.4 Next implementation gates

- Add/adjust Burmese text in `resources/js/i18n/translations.js` as product owner confirms wording.
- Keep this file as the canonical phase-0 design checkpoint.
