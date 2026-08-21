# Lin Htut Restaurant Ledger — Development Roadmap

> Historical planning document. The owner's later product decisions remove
> curry categories, receipt/statement PDF generation, sharing, and receipt
> previews. Use `IMPLEMENTATION_AUDIT.md` as the current authoritative record.

## 1. Product goal

Build a very simple, phone-first restaurant ledger for one restaurant location. Its main purpose is to record:

- what each customer ate;
- how much each customer owes the shop;
- money a customer pays to the shop;
- money the restaurant lends or gives back to a customer;
- when each event happened; and
- the customer's running balance and full history.

This is not an inventory, purchasing, profit-accounting, multi-branch, or printing system.

## 2. Confirmed technology and deployment constraints

- One Laravel project containing the React/Vite SPA and Laravel API.
- MySQL database.
- Installable, phone-first PWA.
- Online connection is required to save or change financial records.
- The application shell may load offline, but financial data must never pretend to be saved while offline.
- Must work from either a domain root or a nested Hostinger folder.
- Browser URLs, API calls, PWA manifest, service worker, icons, and navigation must use a runtime-derived application base path. Do not hardcode the production domain or deployment folder into compiled JavaScript.
- Build frontend assets locally and intentionally track `public/build/` for Hostinger deployment without Node.js.
- Do not cache authenticated API responses in the service worker.
- Use `Asia/Bangkok` as the application timezone because all operational users are in Thailand.
- Store kyat amounts as integers.

## 3. Deliberate scope exclusions

Do not add these unless the owner later changes the scope:

- Stock or ingredient management
- Curry photos
- Multiple portions or sizes
- Multiple branches
- Cost or profit calculations
- Customer credit limits
- Dedicated payment-method configuration or payment-method selector
- Receipt printing or print layouts
- CSV exports
- Staff-member report filter
- Offline transaction synchronization

## 4. Financial rules

Use one consistent signed customer balance:

- Positive balance: customer owes the shop.
- Zero balance: settled.
- Negative balance: shop owes the customer.

Ledger effects:

| Event | Effect on customer balance |
| --- | --- |
| Credit/partially paid sale | Increase by unpaid amount |
| Restaurant lends money to customer | Increase |
| Customer pays shop | Decrease |
| Shop returns/pays money to customer | Increase |
| Manual adjustment | Increase or decrease, with a required reason |

A customer may overpay. The excess creates a negative balance, meaning the shop owes the customer. The UI must always use plain labels such as “Customer owes shop” and “Shop owes customer”; staff should not need to interpret accounting signs.

Every financial action must be recorded in an append-only customer ledger with:

- event type;
- customer;
- amount;
- date and time;
- optional note;
- balance after the event;
- staff account responsible; and
- related sale or reversal reference where applicable.

Editing or deleting a sale must never silently rewrite financial history. The system should reverse the original ledger effect, record the actor, time, and reason, then apply the corrected sale when editing. “Delete” in the UI means a reversible soft deletion/cancellation, not physical database deletion.

The same reversal principle must be used for any permitted correction to payments, loans, repayments, and adjustments. These records may be edited or deleted/reversed only when the staff member has the relevant permission.

## 5. Roles and permissions

### Accounts

- Admin can create, edit, disable, and reset staff accounts.
- Disabled users cannot sign in, but their historical actions remain visible.
- No public registration.

### Permission model

Use granular permissions and provide simple role templates. Navigation and buttons must be hidden when the user lacks permission, and the Laravel API must enforce every permission independently.

Initial permissions:

- View dashboard
- Create sale
- View sales history
- Backdate sale
- Edit sale
- Delete/reverse sale
- View customers
- Create/edit customers
- Record customer payment
- Record money given/lent to customer
- Correct/reverse customer ledger entry
- View customer statements
- View reports
- Manage curry items
- Manage staff accounts and permissions
- View audit history

Suggested templates:

- Admin: all permissions
- Cashier: create sales, view customers, record payments and money lent, view permitted history
- Viewer: read-only access selected by admin

The admin can customize permissions after selecting a template.

## 6. Functional modules

### 6.1 Authentication

- Login with a simple identifier and password.
- Explicit logout.
- Secure Laravel session authentication.
- Appropriate session isolation for a nested Hostinger deployment.
- Remember the user's language preference.

### 6.2 Curry items

- One curry name field; no separate Burmese and English names.
- Category.
- Current price.
- Available/unavailable toggle.
- Display order.
- Archive instead of permanently deleting an item used in sales.
- Historical sale lines retain the sold name and price even if the curry is later renamed or repriced.

### 6.3 Customers

- Name, required.
- Phone number, optional.
- Address or short note, optional.
- Opening balance, available only with appropriate permission and a required reason.
- Current balance displayed as “Customer owes shop,” “Shop owes customer,” or “Settled.”
- Active/archive status.
- Search by name or phone.
- Merge duplicate customers only as a later enhancement, not initial scope.

### 6.4 Sales

- Customer selection is the first step and is required for normal sales.
- Provide an explicit Walk-in Customer option for exceptional anonymous sales.
- Walk-in Customer sales must be fully paid. The app must not create anonymous customer debt.
- Add curry items using large tap targets.
- Quantity and captured unit price for each item.
- Staff cannot override an individual curry's price during a sale; use the sale-level discount when a price reduction is needed.
- Sale-level discount only.
- Paid amount supports fully paid, partially paid, and fully unpaid sales.
- Payment method is not structured; use the optional sale/payment note when staff need to remember cash, KBZPay, Wave Pay, or another method.
- Sale date and time default to now.
- Backdating requires permission.
- Optional note.
- Save once with protection against accidental duplicate submissions.
- View complete sale details afterward.
- Edit and delete/reverse only with permission and a required reason.

Calculation:

`subtotal = sum(quantity × captured unit price)`

`total = max(0, subtotal - discount)`

`unpaid amount = total - paid amount`

For named customers, the paid amount may exceed the sale total. The excess becomes a customer credit and the balance changes to “Shop owes customer” when it crosses below zero. Walk-in Customer sales cannot be overpaid.

### 6.5 Customer ledger and statement

Customer detail should show:

- prominent current balance;
- quick actions for New Sale, Customer Pays Shop, and Customer Gets Money From Shop;
- chronological timeline combining sales and money movements;
- running balance after every entry;
- date filter;
- entry details and reversal links when applicable.

Provide a clean PDF customer statement that can be saved and shared using the phone's native share sheet when supported. This is not a print feature. Provide a normal PDF download fallback when native sharing is unavailable. Do not add an image export in the first release.

### 6.6 Receipt

- Simple digital receipt after a sale.
- Restaurant name, receipt number, customer, date/time, items, quantities, prices, discount, paid amount, unpaid amount, and note.
- Save as PDF and share using the phone's native share sheet when supported.
- Normal PDF download fallback; no image export in the first release.
- No printer integration and no dedicated print workflow.

### 6.7 Reports

Keep reports focused on sales and customer debt:

- Sales summary for today, yesterday, this week, this month, or a custom range.
- Total sales value.
- Total discounts.
- Total paid at sale time.
- Total new sale debt.
- Customer payments received.
- Money lent/paid to customers.
- Outstanding customer balances.
- Customers who owe the shop.
- Customers whom the shop owes.
- Most-sold curry by quantity.
- Highest-selling curry by sales value.
- Cancelled/reversed sales and adjustments for authorized users.

Filters:

- Date range.
- Customer.
- Curry item or category where relevant.
- Paid, partially paid, or unpaid status where relevant.

Do not include a staff-member filter, CSV export, stock reports, cost reports, or profit reports.

## 7. Phone-first UI/UX flow

### Navigation

Use a bottom navigation bar filtered by permission:

1. Home
2. New Sale
3. Customers
4. Reports
5. More

The central New Sale action should be the most prominent. “More” contains curry management, staff/permissions, audit history, language, and logout according to permissions.

### Home

Keep the home screen small:

- Large New Sale button.
- Today's sales total.
- Total customer debt.
- Recent customer activity.
- Quick customer search.

Avoid dense charts on the home screen.

### New sale flow

1. Search/select customer or explicitly choose Walk-in Customer.
2. Tap curry buttons to add items.
3. Adjust quantities in the compact order panel.
4. Enter discount and paid amount.
5. Optionally change date/time if permitted and add a note.
6. Review total, debt created, and resulting customer balance.
7. Save.
8. Show receipt with Share and Save actions.

Frequently used curries can appear first, followed by category filters and search. Use a sticky total and Save button at the bottom. Advanced fields remain collapsed until needed.

### Customer flow

1. Search customer.
2. See the balance immediately in words and color.
3. Use one of three large actions: New Sale, Customer Pays, Customer Gets Money.
4. See a single combined timeline below.
5. Open details only when needed.

Do not split sales and money movements into several confusing tabs initially.

### Interaction standards

- Large touch targets and numeric keypad inputs for money and quantity.
- Burmese-friendly font stack and generous line height.
- Confirm consequential actions.
- Require reasons for reversals and historical corrections.
- Show clear success/error feedback.
- Prevent double taps from creating duplicate transactions.
- Preserve entered form data after recoverable validation or network errors.
- Do not rely on color alone to explain balances or status.

## 8. Burmese and English localization

Keep all developer-written interface translations in one easy-to-edit file, for example:

`resources/js/i18n/translations.js`

The file contains matching `my` and `en` objects. It should include navigation, buttons, validation messages, permissions, statuses, reports, receipt/statement labels, confirmations, PWA messages, and error text.

User-entered curry names, customer names, and notes are stored exactly as entered and are not translated.

Add a development check that reports missing or extra keys between the two languages. Avoid scattered hardcoded user-facing strings in React components.

### Initial owner-editable Burmese terminology

Use these initial labels consistently in the translation file. The owner may correct the native wording later without changing application logic.

| English meaning | Initial Burmese label |
| --- | --- |
| New Sale | အရောင်းအသစ် |
| Customer | ဖောက်သည် |
| Customers | ဖောက်သည်များ |
| Walk-in Customer | အမည်မရှိ ဖောက်သည် |
| Customer Pays Shop | ဖောက်သည်မှ ဆိုင်သို့ ငွေပေး |
| Customer Gets Money From Shop | ဖောက်သည်မှ ဆိုင်ထံမှ ငွေယူ |
| Customer owes shop | ဖောက်သည်က ဆိုင်ကို ပေးရန်ရှိ |
| Shop owes customer | ဆိုင်က ဖောက်သည်ကို ပေးရန်ရှိ |
| Settled | စာရင်းရှင်းပြီး |
| Current balance | လက်ရှိ လက်ကျန်ငွေ |
| Amount | ငွေပမာဏ |
| Paid amount | ပေးချေငွေ |
| Unpaid amount | ပေးရန်ကျန်ငွေ |
| Discount | လျှော့ဈေး |
| Total | စုစုပေါင်း |
| Sale date and time | အရောင်း ရက်စွဲနှင့် အချိန် |
| Note | မှတ်ချက် |
| Save | သိမ်းမည် |
| Share receipt | ဘောင်ချာ မျှဝေမည် |
| Save receipt | ဘောင်ချာ သိမ်းမည် |
| Customer statement | ဖောက်သည် ငွေစာရင်း |
| Money lent to customer | ဖောက်သည်ကို ချေးပေးငွေ |
| Edit | ပြင်မည် |
| Delete / Reverse | ပယ်ဖျက်ပြီး စာရင်းပြန်ညှိမည် |
| Reversal reason | ပယ်ဖျက်/ပြန်ညှိရသည့် အကြောင်းရင်း |
| Reports | အစီရင်ခံစာများ |
| Most-sold curry | ရောင်းအားအများဆုံး ဟင်း |

## 9. Suggested core data model

- `users`
- `roles`
- `permissions`
- role/user permission assignment tables
- `curry_categories`
- `curry_items`
- `customers`
- `sales`
- `sale_items`
- `customer_ledger_entries`
- `audit_logs`

Important implementation rules:

- Use database transactions when saving or reversing a sale and its ledger entries.
- Use immutable IDs and explicit relationships between sales, ledger entries, and reversals.
- Use soft deletion/status fields for financial records.
- Keep calculated snapshots on sale items so historical sales do not change when menu prices change.
- Add indexes for customer, date, sale status, ledger type, and curry reporting queries.
- Recalculate/verify customer balances from ledger entries during tests; do not trust only a mutable balance field.

## 10. Development phases

### Phase 0 — Confirm terminology and design

- Review and, if necessary, correct the initial Burmese terminology in section 8.
- Produce low-fidelity phone wireframes for the sale and customer screens.
- Define acceptance criteria before schema work.

### Phase 1 — Project foundation

- Create the single Laravel + React/Vite project.
- Configure MySQL, session authentication, timezone, and nested-path-safe routing.
- Add React routing, API client, global validation/error handling, and the single translation file.
- Add manifest, icons, service worker, online/offline indicator, and update prompt.
- Add automated tests for root and nested runtime base paths.

### Phase 2 — Accounts and permissions

- Implement login/logout and disabled-account behavior.
- Implement roles, granular permissions, templates, API authorization, and permission-filtered UI.
- Add admin screens for staff and permissions.
- Test every protected operation at API level.

### Phase 3 — Curry and customer setup

- Implement curry categories/items, ordering, availability, repricing, and archive behavior.
- Implement customer creation, search, details, opening balance, and archive behavior.
- Seed only safe reference permissions/default roles; do not seed demo financial data in production.

### Phase 4 — Sales and ledger

- Implement the fast sale workflow and calculations.
- Atomically create sales, items, payments, and ledger entries.
- Implement customer payments and money lent/returned.
- Implement running balance and customer timeline.
- Implement permission-controlled backdating, editing, and reversal.
- Add idempotency/duplicate-submit protection.

### Phase 5 — Sharing and reports

- Build digital receipt save/share.
- Build customer statement date filtering and save/share.
- Build the focused reports and filters in section 6.7.
- Verify most-sold curry results by quantity and by value.

### Phase 6 — Quality and PWA verification

- Test Burmese and English interfaces on narrow phone screens.
- Test permissions, rounding, discounts, partial payments, negative balances, reversals, backdating, and concurrent submissions.
- Test online-required transaction behavior and connection-loss recovery.
- Test Android installation and iOS Add to Home Screen.
- Verify the service worker never caches private/authenticated API data.
- Run frontend lint/build, Laravel tests, and migration tests.

### Phase 7 — Hostinger preparation and deployment

- Audit repository/configuration against `GENERIC_HOSTINGER_LARAVEL_REACT_AI_PROMPT.md`.
- Confirm final URL, document-root capability, database status, and whether deployment is fresh or an update.
- Build and track `public/build/` locally.
- Prepare secure root/public routing for the chosen hosting layout.
- Deploy with Composer and migrations; do not require Node.js on Hostinger.
- Verify sensitive files are inaccessible, SPA refresh routes work, API/database health is good, and the PWA uses the correct nested base path.
- Document backup and safe update procedures.

## 11. Minimum acceptance scenarios

- Create a customer, make a partially paid sale, and see only the unpaid amount added to customer debt.
- Record an overpayment and see the balance cross to “Shop owes customer.”
- Lend money to a customer and see the balance increase.
- Prevent an unpaid, partially paid, or overpaid Walk-in Customer sale.
- Prevent staff from overriding an individual curry price during a sale.
- Edit a sale with permission and see the old effect reversed and the corrected effect applied with a complete audit trail.
- Delete/reverse a sale with permission and see related debt reversed without physically losing the sale.
- Edit or delete/reverse a payment or loan with permission and preserve its complete reversal history.
- Deny every edit, delete, backdate, report, and admin action when the user lacks permission.
- Rename/reprice/archive a curry without altering historical sale details.
- Save/share a receipt and customer statement from a phone without a printer workflow.
- Switch between Burmese and English with no missing translation keys.
- Refresh important SPA routes under both root and nested-folder hosting paths.

## 12. Confirmed owner decisions

- Named customers may overpay; excess payment becomes a balance owed by the shop.
- Permission-controlled editing/deletion applies to payments and money-lending entries as well as sales, always through audited reversals.
- Walk-in Customer sales must be fully paid and cannot be overpaid.
- Staff cannot override curry prices during a sale; they may use the sale-level discount.
- Receipts and customer statements use PDF for saving and sharing; image export is not included initially.
- The initial Burmese wording in section 8 will be kept in one translation file so the owner can correct it easily.
