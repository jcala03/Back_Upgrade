# UX Flow Optimization

Measured on 2026-09-28 with real Chromium against the local QA interface. Counts include meaningful clicks/decisions, not every keystroke. `N/O` means not observable without inventing a product/order or bypassing the interface. No records were saved.

## Friction matrix

| Flow | Status | Screens/dialogs | Clicks approx. | Fields approx. | Friction | Main problem | Reduction potential |
|---|---|---:|---:|---:|---|---|---|
| Buy product | BLOCKED | 2 before stop | 1 before stop | 10 filters | CONFUSING | Shop has zero products | High after data exists |
| Checkout shipping | BLOCKED | N/O | N/O | N/O | BLOCKED | Cannot create cart | Must retest |
| Checkout pickup | BLOCKED | N/O | N/O | N/O | BLOCKED | Cannot create cart | Must retest |
| Create product | PARTIAL | 1 + long dialog | 2 from Inventory | 18+ | TOO LONG | Seven sections shown together | High |
| Assign inventory | PARTIAL | 1 dialog | 2 | 6 | ACCEPTABLE | Separate from product save | Medium |
| Transfer stock | BLOCKED lifecycle | list + dialog + lifecycle detail | 2 to request; N/O dispatch/receive | 5 + line items | ACCEPTABLE | No stock to exercise states | Medium |
| Create sale | BLOCKED completion | 1 dialog | 3–7 before items | 9 base | TOO LONG | Branch/catalog prerequisites and empty data | Medium |
| Register payment | BLOCKED completion | within sale | +1 | +4 | ACCEPTABLE | Cannot validate paid/completed lifecycle | Low |
| Create quotation | BLOCKED completion | 1 dialog | 3–6 | 8–10 | ACCEPTABLE | No items available | Medium |
| Convert quotation | BLOCKED | N/O | N/O | N/O | BLOCKED | No quotation can be created | High if data repeats |
| Create customer | PARTIAL | 1 dialog | 2 | 7 | ACCEPTABLE | No vehicle/next action in same flow | Medium |
| Create appointment | BLOCKED completion | 1 dialog | 3–6 | up to 11 | TOO LONG | Manual contact duplication; inactive employee | High |
| Create employee | PARTIAL | 1 dialog | 2 | 9 | ACCEPTABLE | Operational setup remains fragmented | Medium |
| Product → stock → publish | PARTIAL | 3 contexts | 6–9 | 24+ | TOO LONG | Save, relocate, search again, adjust, publish | High |
| Customer → sale/quote | BLOCKED | 2–3 contexts | 5–8 | repeated lookup | TOO LONG | No launch action verified from detail | High |
| Collaborator daily work | PARTIAL | 3–5 modules | 4–8 | 0–var. | CONFUSING | Home has no daily summary/actions | High |
| Change report family | PARTIAL | 1 screen | 1–3 | 5–9 filters | ACCEPTABLE | Duplicate selector and unclear defaults | Medium |

## 1. Public purchase and checkout

### ACTUAL

Home → Explore articles → Shop → **zero products / stop**

BEFORE: 2 screens / 1 click / up to 10 filter controls before the dead end.

Shipping, pickup, address, quote, charges, payment and confirmation were not reachable. Assigning invented numbers to those screens would contradict the real-browser rule.

### PROPOSED

Home or Shop → Product → Add to cart → Cart → Checkout with shipping/pickup first → address or branch → automatic quote → payment → confirmation

AFTER target: 6–7 screens or steps / 8–12 meaningful clicks / only fulfillment-relevant fields.

### AHORRO

The immediate gain is not fewer clicks but removal of a 100% conversion dead end. After the retest, target one checkout screen with progressive sections or no more than three clearly numbered steps.

### RAZÓN

A buyer must see an available product, price and compatibility before any optimization of checkout matters.

### PRIORIDAD

P0.

Preserve: server-side price, stock, branch isolation, shipping quote authority, Order/Payment separation and idempotent payment initiation.

## 2. Create product and assign inventory

### ACTUAL

Inventory → Create product → seven-section modal → Save → return/search in Inventory → Adjust stock → choose branch → search the product again → quantity/reason → Save → Published catalog to verify visibility

BEFORE: about 3 contexts / 8–10 clicks / 24+ controls across product and stock; classification `TOO LONG`.

### PROPOSED

Inventory → New product → essential fields with progressive category/compatibility → **Save and assign inventory** → branch/quantity/reason → saved product summary with publication state

AFTER: 2 dialogs/steps / 5–6 clicks / 12–15 initially visible fields plus progressive controls.

### AHORRO

- 2–4 clicks.
- One product re-search.
- One module/context change.
- Roughly six optional controls hidden until relevant.

### RAZÓN

The ledger must remain separate, but the successful create response already provides the exact product identity needed to open a stock adjustment.

### PRIORIDAD

P1.

What is removed: re-navigation and re-search.
What is automated: carry product/variant into the adjustment dialog.
What is preserved for safety: explicit branch, quantity, reason, server validation and independent stock movement record.
Technical risk: medium; requires a safe post-create handoff, not a combined database shortcut.

## 3. Product form progressive disclosure

### ACTUAL

Information → Details → Compatibility → Price → Variants → Commission → Publication, all visible in a single long dialog.

BEFORE: 1 long dialog / 18+ controls / 7 section-level decisions.

### PROPOSED

Essentials → category-dependent details → simple vs variants → compatibility only when specific → advanced commercial options → publication summary

AFTER: 1 dialog / 10–12 controls initially / 3 primary decisions; advanced fields remain available.

### AHORRO

Approximately 6–8 controls and four section decisions removed from the initial scan, not from the data model.

### RAZÓN

Most simple products do not need variant, commission or advanced pricing decisions on first contact.

### PRIORIDAD

P1.

Preserve: category-required specs, SKU uniqueness, compatibility, price validation and explicit active/ecommerce states.
Technical risk: medium because conditional validation and draft state must remain synchronized.

## 4. Create sale

### ACTUAL

Orders → New sale → customer mode → branch → optional seller → vehicle mode → Products/Services → search/add lines → optional payment → register

BEFORE: 1 dialog / about 7–12 clicks once data exists / 9 base controls plus product lines and 4 payment fields; classification `TOO LONG` in the empty QA state.

### PROPOSED

New sale → branch defaulted from current operator where allowed → customer/vehicle inline search → unified item search with product/service badges → totals → optional payment → confirm

AFTER: 1 dialog / about 6–9 clicks / 6–8 primary controls plus item/payment details.

### AHORRO

- 2–3 decisions when branch/operator defaults are trustworthy.
- One Products/Services mode switch through unified results.
- No repeat customer/vehicle lookup when launched from Customer.

### RAZÓN

Branch and seller can often be defaults, while still remaining explicit and editable. Product versus service is an item attribute, not necessarily a user mode.

### PRIORIDAD

P1.

Preserve: authorization, branch-scoped stock, seller/branch compatibility, backend prices, inventory validation, payment/order independence and audit history.
Technical risk: medium-high because search combines stock-bearing and non-stock items.

## 5. Register payment

### ACTUAL

Within New sale → enable “Registrar pago ahora” → method → amount → reference → notes → register sale

BEFORE: same dialog / +1 click / +4 fields.

### PROPOSED

Keep the section collapsed and optional; when enabled, prefill amount with outstanding total while allowing partial payment and showing “Order status” and “Payment status” separately.

AFTER: +1 click / 2 required fields (method, amount) / reference and notes progressive by method.

### AHORRO

Up to two unnecessary fields for cash; clearer status consequence.

### RAZÓN

Reference is relevant for transfer/card/Wompi, not always for cash.

### PRIORIDAD

P2.

Preserve: payment record, idempotency, partial-payment handling, immutable audit trail and no implicit “completed” status.

## 6. Transfer stock

### ACTUAL

Inventory → Transfers → New transfer → origin/destination → search/add items → request → later open transfer → dispatch → later open transfer → receive

BEFORE: about 3 lifecycle views / 7–10 clicks / 5 base controls plus line items. Completion was `BLOCKED` by zero stock.

### PROPOSED

Keep the three business transitions. On each transfer detail, show a single dominant next action and a timeline: Requested → In transit → Received. Carry current branch and recent item searches where safe.

AFTER: still 3 controlled transitions / about 5–8 clicks / no reduced validation.

### AHORRO

1–2 navigation/search clicks, while preserving all three audited events.

### RAZÓN

Request, dispatch and receipt represent real custody changes and must not be collapsed merely to save clicks.

### PRIORIDAD

P2.

Technical risk: low-medium. Preserve stock locks, origin availability, branch isolation and movement history.

## 7. Create quotation and convert to sale

### ACTUAL

Quotations → New quotation → customer → vehicle → Products/Services → validity/notes → create → later find/open → send → convert → unknown downstream form

BEFORE observed creation: 1 dialog / about 6 clicks / 8–10 controls. Conversion counts are `N/O` because no items allow a quotation to be created.

### PROPOSED

Quotation → Convert to sale → retain customer, vehicle, items, snapshots, discounts and notes → choose branch/seller → revalidate stock/prices → optional payment → confirm

AFTER target for conversion: 1 confirmation flow / 4–6 clicks / 3–5 decisions.

### AHORRO

Eliminate all repeated customer, vehicle and line-item entry; likely 5–10 clicks and a full context rebuild.

### RAZÓN

Conversion should carry commercial intent but must revalidate operational facts.

### PRIORIDAD

P1.

Preserve: quote price history, explicit branch, live stock, current price reconciliation, authorization and separate resulting sale/payment records.
Technical risk: medium-high until tested with real quotations.

## 8. Customer to sale or quotation

### ACTUAL

Customers → find/open customer → switch to Orders or Quotations → start new record → select/search the customer again → select vehicle again

BEFORE estimated from visible modules: 2–3 contexts / 5–8 clicks / repeated lookup.

### PROPOSED

Customer detail → New sale or New quotation → customer and chosen vehicle already set → continue with branch/items.

AFTER: 1 context handoff / 2–4 clicks / no repeated customer fields.

### AHORRO

3–4 clicks, one module search and one change of context.

### RAZÓN

The customer record is the strongest source of identity and vehicle context.

### PRIORIDAD

P1.

Preserve: permissions on the destination module and all sale/stock rules.
Technical risk: low.

## 9. Create appointment

### ACTUAL

Appointments → New appointment → responsible employee → customer/manual contact → vehicle → service → state → title/description/contact/phone/email/vehicle text → start/end → availability → save

BEFORE: 1 dialog / 5–10 clicks / up to 11 controls; classification `TOO LONG`.

### PROPOSED

New appointment → employee/service → customer search or manual contact → vehicle auto-filled → start + duration → availability result → optional details → save

AFTER: 1 dialog / 4–7 clicks / 6–8 primary controls.

### AHORRO

3–5 initially visible fields and 2–3 decisions; phone/email/vehicle reuse when a customer is selected.

### RAZÓN

Customer and employee selections can supply branch and contact context. Duration is usually easier than manually coordinating two datetime inputs.

### PRIORIDAD

P1.

Preserve: backend availability, employee/branch rules, timezone, conflict prevention and audit of rescheduling.
Technical risk: medium.

## 10. Create employee and operational setup

### ACTUAL

Employees → Register employee → save → employee detail → Horarios → Ausencias → Tareas; CRM access is a separate optional link/account action.

BEFORE: at least 4 contexts / 7–10 clicks / 9 employee fields plus separate setup forms.

### PROPOSED

Register employee → save → employee operations hub with setup checklist: branch/access, schedule, availability/leaves, tasks/goals, appointments and commissions.

AFTER: 1 hub plus task dialogs / 4–7 navigation clicks; no combined unsafe save.

### AHORRO

2–3 module searches and clearer setup progress.

### RAZÓN

The detail already links schedule, leaves and tasks; extend this pattern rather than moving ownership of data.

### PRIORIDAD

P2.

Preserve: separate account lifecycle, permission assignment and employee/account independence.
Technical risk: low-medium.

## 11. Collaborator daily work

### ACTUAL

Login → Home with Notifications/Profile → navigate separately to Calendar, Business overview, Tasks or Goals. Sales, quotations and commissions are absent. The account is not linked to an employee.

BEFORE: 3–5 modules / 4–8 clicks to assemble a daily picture; classification `CONFUSING`.

### PROPOSED

Personal Home → today panel (next appointment, overdue tasks, active goal, quotes requiring follow-up, sales requiring action, commission summary) → permitted one-click action.

AFTER: 1 screen / 0–2 clicks for the most common daily action.

### AHORRO

2–4 module transitions per session.

### RAZÓN

Home should answer “what do I need to do now?” while every destination module remains authoritative.

### PRIORIDAD

P1.

Preserve: capability checks per card/action and employee linkage. Never reveal company-wide data through convenience summaries.
Technical risk: medium.

## 12. Reports

### ACTUAL

Reports → choose one of eight families using buttons or a duplicate select → re-evaluate 5–9 filters → apply → export/change report.

BEFORE: 1 screen / 1–3 clicks per change / 5–9 controls.

### PROPOSED

Single report-family control → persistent common filter bar (branch, date) → report-specific filters below → Apply → switch family without losing common context.

AFTER: 1 screen / 1–2 clicks / 2 persistent common controls plus specific controls.

### AHORRO

One duplicate decision and repeated branch/date setup.

### RAZÓN

Branch and period represent analysis context; they should survive family changes unless the user resets them.

### PRIORIDAD

P2.

Preserve: permission-limited financial metrics and export scope.
Technical risk: low.

## Validation plan for the next audit

The following must be measured again after QA fixtures exist:

1. Product detail → variant → cart, including insufficient stock.
2. Shipping checkout and pickup checkout at all seven widths.
3. Address save/edit, automatic shipping quote, quote failure and stale quote.
4. Payment approved, pending, failed, retry and repeated-click protection.
5. Quote send/edit/convert without re-entry.
6. Transfer request/dispatch/receive with two authorized branch users.
7. Sale create/payment/complete and the distinction between paid and completed.

## UX-QA-02 — measured operational update

This section supersedes the `BLOCKED`/`N/O` measurements above where the deterministic `upgrade_test` dataset made the flow executable. Counts are meaningful UI activations, not keystrokes. External payment and successful carrier rating remain unmeasured rather than estimated.

### Updated friction matrix

| Flow | Status | Screens/states | Clicks approx. | Fields approx. | Friction | Main problem | Reduction potential |
|---|---|---:|---:|---:|---|---|---|
| Buy simple product | PARTIAL | 4 | 6 | 0 | CONFUSING | Default Shop returns zero; user must discover the “Solo destacados” workaround | High |
| Filter for QA Macan | PASS with caveat | 1 | 5 | 1 year | ACCEPTABLE | Cascading vehicle controls work, but require four selections; the universal/unscoped simple item remains | Medium |
| Checkout shipping | EXTERNAL | 3 before stop | 6 | 13 required/semirequired + 3 optional | TOO LONG | Fulfillment is chosen after contact; recipient/address data repeats; rating provider fails | High |
| Checkout pickup | PASS | 3 | 5–6 | 5 required + 1 optional | ACCEPTABLE | City/address is requested before pickup choice | High |
| Create product | PARTIAL (baseline) | 1 + long dialog | 2 | 18+ | TOO LONG | Seven simultaneous sections | High |
| Assign inventory | PASS prerequisite / baseline UI | 1 dialog | 2–4 | 6 | ACCEPTABLE | Separate search/context after product save | Medium |
| Transfer stock | PASS | list + create + detail lifecycle | 8 | 2 selects + line quantity + notes | ACCEPTABLE | Lifecycle is clear; several list/detail transitions | Low–medium |
| Create direct sale | PASS | list + create + detail | 8–10 | 2 searches/selections + notes + line defaults | ACCEPTABLE | Customer/vehicle selection is still modal-heavy | Medium |
| Register payment | PASS | sale detail + payment dialog | 2 | 4 (amount, method, reference, notes) | OPTIMAL | Defaults to full outstanding balance; preserves review | Low |
| Create quotation (collaborator) | PASS | list + create + detail | 9–11 | 2 searches + line defaults + validity/notes | ACCEPTABLE | Dense but context stays in one dialog | Medium |
| Create quotation (global admin) | FAIL | 1 dialog | 9 before failure | 10+ | CONFUSING | Required branch is absent from the UI | High |
| Convert quotation | PASS | detail | 2 after open (send + convert) | 0 | OPTIMAL | No repeated customer/vehicle/items | Low |
| Create customer | PARTIAL (baseline) | 1 dialog | 2 | 7 | ACCEPTABLE | No immediate quote/sale continuation | Medium |
| Customer → quotation | FAIL as direct path | 3 contexts | 5–7 before form work | customer search repeats | TOO LONG | Customer detail has no commercial launcher | High |
| Create appointment | PASS | list + dialog | 6 selections + save | up to 11 + 2 datetimes | TOO LONG | Existing customer data is repeated; end time is manual | High |
| Create task | PARTIAL | list + dialog + collaborator detail | 5–7 | 4 core; deadline not exercised | ACCEPTABLE | Assignment, priority, visibility and completion pass; deadline coverage remains partial | Low |
| Create goal | PASS | list + dialog + collaborator detail | 6–8 | 5 core | ACCEPTABLE | Creation and progress work; Home does not summarize it | Medium |
| Create employee | PARTIAL (baseline) | 1 dialog + setup modules | 7–10 | 9 + setup | TOO LONG | Schedule/access/availability remain fragmented | Medium |
| Collaborator daily work | PARTIAL | Home + destination | 2–4 per concern | 0 | CONFUSING | Home omits assigned task/goal/appointment | High |

### Public purchase and fulfillment

#### ACTUAL

Home → Shop (0 products) → enable “Solo destacados” → product → select variant when needed → Add to Cart → Cart → Continue → contact form → Prepare order → choose/apply fulfillment → payment boundary.

- Purchase discovery: **4 screens / ~6 clicks / 1 unexplained filter decision**.
- Pickup from Cart: **3 states / 5–6 clicks / 5 required fields + 1 optional**.
- Shipping from Cart: **3 states / 6 clicks before a rate / about 13 required or semirequired fields + 3 optional**; ends at carrier quote error.

#### PROPOSED

Home/Shop → product → Add to Cart → Cart → Checkout → **choose pickup or shipping first** → collect only relevant contact data → auto-save/auto-rate shipping or select pickup branch → payment.

- Discovery target: **4 screens / 4–5 clicks / no recovery workaround**.
- Pickup target: **2 states / 3–4 clicks / name + email + phone, with branch selection**.
- Shipping target: **2 states / 4–5 clicks / one nonduplicated recipient/address set**.

#### AHORRO

- 1 unexplained filter decision on every shopping session.
- Pickup: 2 location fields and about 2 clicks.
- Shipping: 3–5 repeated fields and one explicit save/calculate step if address save triggers rating automatically.

#### RAZÓN

Fulfillment determines which data is necessary. Asking for it first reduces irrelevant input while keeping server-authoritative price, stock, shipping quotes and payment readiness.

#### PRIORIDAD

P0 for Shop discovery; P1 for checkout order/repetition.

### Customer to quotation or sale

#### ACTUAL

Customers → Ver cliente → close detail → sidebar Quotations/Sales → New → search the same customer → select customer → select vehicle → items → save.

BEFORE: **3 contexts / about 7–10 clicks / repeated customer lookup**; `TOO LONG`.

#### PROPOSED

Customer detail → New quotation or New sale → prefilled customer and optional selected vehicle → items → save.

AFTER: **2 contexts / about 4–6 clicks / no repeated customer lookup**.

#### AHORRO

1 module change, 1 search, and 3–4 clicks per customer-led commercial action.

#### RAZÓN

Customer detail is already the relationship hub. The shortcut only carries identifiers; it must not bypass branch, price, stock or authorization checks.

#### PRIORIDAD

P1.

### Quotation creation and conversion

#### ACTUAL

Collaborator: My quotations → New → product/service → registered customer → search/result → vehicle → notes → Create → open/send → Convert.

BEFORE: **1 list + 2 dialogs/states / about 11–13 clicks / 2 search inputs + line defaults + notes**. Conversion itself is **2 clicks / 0 fields** after opening and preserves all context.

Global admin follows a denser version of the same flow but fails at submit because `branch_id` is required and no branch control is rendered.

#### PROPOSED

For global admin, require branch as the first contextual selector or inherit an explicitly visible active branch. Keep the collaborator conversion unchanged.

AFTER: collaborator unchanged; global admin gains **1 explicit decision** and avoids a late full-form failure.

#### AHORRO

The main saving is error prevention: no completed 10-field form discarded at submit. Conversion already avoids all re-entry.

#### RAZÓN

Branch is a business/security boundary, not a field to infer silently for a global admin.

#### PRIORIDAD

P0 for admin creation. Preserve branch isolation and immutable quotation snapshots.

### Direct sale and payment

#### ACTUAL

My sales → New → product/service → customer search/result → vehicle → notes → Create pending → Confirm → Register payment → Complete.

BEFORE: **list + create + detail/payment / about 11–13 clicks / 2 searches/selections + notes + 4 payment fields**; `ACCEPTABLE`.

#### PROPOSED

Keep the lifecycle separation. Improve only entry: customer-led launch or recent-customer suggestion; keep an explicit review before Confirm and keep Payment distinct from Completion.

AFTER: **same lifecycle / about 8–10 clicks / no repeated customer lookup**.

#### AHORRO

2–3 clicks and one context/search when launched from Customer. No safety step is removed.

#### RAZÓN

Observed copy correctly explains that pending does not consume stock. Combining paid/completed or auto-confirming would damage auditability.

#### PRIORIDAD

P2 for speed; preserve current safety semantics.

### Transfer lifecycle

#### ACTUAL

Inventory → Transfers → New → source/destination → item → Add → Request → open detail → Dispatch/confirm → Receive/confirm.

BEFORE: **3 states / about 8 clicks / 2 branches + item + quantity + notes**; `ACCEPTABLE`.

#### PROPOSED

Keep all states. After request, leave the new transfer detail open and emphasize one “Next action” button; after dispatch, update the same panel to Receive.

AFTER: **same 3 safety states / about 6 clicks / same fields**.

#### AHORRO

One list return and one re-open action. No lifecycle or traceability is removed.

#### RAZÓN

Request, dispatch and receipt correspond to real custody changes. The optimization is continuity, not fewer controls.

#### PRIORIDAD

P2.

### Appointment lifecycle

#### ACTUAL

Appointments → New → responsible employee → customer → vehicle → service → initial status → title/description/contact/phone/email/vehicle snapshot → start/end → availability → save → Reprogram → edit both datetimes → In progress → Cancel → reason.

BEFORE: **list + 3 dialogs / 12–16 clicks / up to 13 data fields**; `TOO LONG`.

#### PROPOSED

Appointments → New → employee → customer/vehicle → service → start time → derive duration/end from service → prefill contact and vehicle snapshot → optional details → save. Keep reprogram, status and cancellation reason separate.

AFTER: **list + dialog / 7–9 clicks / 6–8 visible fields**.

#### AHORRO

5 duplicated fields and 3–5 interactions on creation; lifecycle controls remain intact.

#### RAZÓN

Customer, vehicle and service are already authoritative records. Snapshotting can happen on save without requiring retyping.

#### PRIORIDAD

P1.

### Collaborator daily work

#### ACTUAL

Home → separate Tasks, Goals, Calendar, Quotes, Sales and Commissions modules. A pending task and active assigned goal were not summarized on Home.

BEFORE: **2–4 module visits / 3–7 clicks** to assemble daily priorities; `CONFUSING`.

#### PROPOSED

Home → “Today” panel with next appointment, pending/overdue task, active goal progress, quote follow-up, sale requiring action and commission summary → one-click permitted destination/action.

AFTER: **1 screen / 0–2 clicks** for the next task.

#### AHORRO

2–3 context switches per session.

#### RAZÓN

The destination modules stay authoritative; the Home only aggregates permission-safe personal records.

#### PRIORIDAD

P1.
