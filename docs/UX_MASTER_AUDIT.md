# UX / UI / Operational Master Audit

Audit date: 2026-09-28
Environment: local QA (`http://localhost:5173`, API at `http://localhost:8000`)
Method: real Chromium 154 controlled through the browser debugging protocol; mouse, keyboard, forms, dialogs and responsive device emulation. No application code, data, dependencies or configuration were changed.

Status legend: `PASS` works end to end in the observed interface; `PARTIAL` works only in part; `FAIL` was reachable but did not work; `BLOCKED` could not be exercised because a prerequisite was absent; `EXTERNAL` depends on a system not exercised in this audit.

> **Current verdict — UX-QA-02 operational retest (2026-09-28).** The original read-only baseline is retained below for traceability. This retest supersedes its zero-data conclusions. It used the persisted `upgrade_test` fixtures through a separate browser/UI stack (`http://localhost:5174` → `http://localhost:8011`) and verified the active database as `upgrade_test` before and after the run. All writes described here came from visible UI actions; API/database reads were verification only. No application code, dependency, or configuration file was changed.

## UX-QA-02 Operational Retest

### Executive retest summary

The deterministic QA dataset removes most of the baseline's data blockers. Public simple, variant and out-of-stock products are rendered; pickup reaches the pre-payment state; a transfer completes through request → dispatch → receive; a collaborator creates, sends and converts a quotation; the generated sale and a direct sale both complete with one payment; an appointment completes create → reprogram → in-progress → cancelled; and assigned task/goal work is visible to the collaborator.

The system is still not ready for production UX sign-off. Three release-level issues remain:

1. **Default Shop discovery is broken.** The initial request combines `in_stock=1` with `featured=0` and returns zero products even though two published products have 15 units. Checking “Solo destacados” unexpectedly reveals them. A first-time buyer sees an empty catalog.
2. **CRM responsive behavior remains blocking.** The collaborator Home measures 1358 px wide at 1024, 768, 430, 390 and 360 px. At 390 px, most content sits off-canvas. Appointments also measures 1571 px against a 1425 px client width at desktop.
3. **Admin quotation creation cannot supply its required branch.** The global admin form exposes customer, vehicle, items, validity and notes, but no branch control. Submit returns “The branch id field is required.” The same quotation succeeds from the branch-bound collaborator portal.

Payment-provider return states remain `EXTERNAL`: the QA runtime uses the sandbox base URL but has no Wompi public key, so the audit stopped at the safe pre-payment boundary and did not initialize an external payment.

### Retest coverage and persisted evidence

| Profile / area | Result | Evidence |
|---|---|---|
| Public customer | `PARTIAL` | Product discovery workaround, simple/variant/OOS detail, cart, guest order, shipping attempt and pickup pre-payment completed. External payment unavailable. |
| Administrator | `PARTIAL` | Customer detail, failed admin quotation, transfer lifecycle, appointment lifecycle, task and goal creation completed. |
| Collaborator / limited user | `PASS` for allowed operations | Own quotation → sent → converted, generated sale payment/completion, direct sale payment/completion, task completion and goal progress completed. |
| Branch isolation | `PASS` in observed paths | Collaborator consistently showed “Sede actual: Barranquilla”; transfer explicitly moved BAQ → BOG. |
| Responsive storefront/checkout | `PASS` for horizontal fit | No document overflow at 1440/1024/768/430/390/360. |
| Responsive CRM | `FAIL` | 1358 px canvas from 1024 down through 360. |
| Accessibility sample | `PARTIAL` | Checkout tab order, dialog trap, Space activation, Escape and focus return passed; Enter did not activate the sampled task-detail button. |

Persisted QA records created by UI and intentionally retained:

- Public order `UG79-20260928-CXML7V`, pending/unpaid, pickup ready before payment.
- Second, distinct pickup order `UG79-20260928-XGSCXL`, pending/unpaid and ready before payment; database verification found exactly one order for each synthetic checkout email.
- Quotation `COT-20260928-OWABJE`, converted to sale `UG79-20260928-EBF6EF`.
- Direct sale `UG79-20260928-RI6MOZ`.
- Both sales are completed/paid with exactly one completed cash payment each.
- Transfer `TRF-20260928-0001`, received; movement history verified `transfer_out -1` (BAQ 10 → 9) and `transfer_in +1` (BOG 5 → 6).
- Appointment `[QA UX] UX-QA-02 Cita E2E`, cancelled after successful creation, reprogramming and in-progress transition.
- Task `[QA UX] UX-QA-02 Tarea E2E`, completed by the collaborator. Assignment and priority passed; deadline coverage is `PARTIAL` because this UI-created record had no deadline.
- Goal `[QA UX] UX-QA-02 Meta E2E`, active at 3/5 (60%).

### Storefront, Shop and product detail retest

| Flow | Result | Observation |
|---|---|---|
| Home → Shop | `PASS` | The primary CTA is discoverable and routes normally. |
| Default Shop result | `FAIL` | Shows 0 results/1 active filter despite stocked QA products. |
| Recovery by “Solo destacados” | `PARTIAL` | Reveals the two stocked products, but the control's name does not explain why this repairs the default result. |
| Macan compatibility filters | `PASS` with caveat | Keyboard-selecting Porsche → Macan → Base 2022–2026 → 2024 keeps the compatible Macan product visible. The unscoped simple product also remains, consistent with a potentially universal item; exclusivity was therefore not inferred. |
| Simple product | `PASS` | Price, 15-network-unit availability, quantity and Add to Cart are clear. |
| Variant product | `PASS` | Version is required; selecting it exposes SKU, exact price and 15-unit availability. |
| Out-of-stock product | `PASS` | “Producto agotado”, zero availability and disabled Add to Cart are consistent. |
| Cart persistence | `PASS` | Contents persisted across navigation and a new browser page in the same profile. |
| Rapid Add to Cart | `FAIL` | Two immediate clicks on the variant CTA increased the cart by two units (1 → 3 total items). No submitting lock/debounce is visible. |

Evidence: [Shop workaround](ux-audit/screenshots/19-qa02-shop-after-stock-filter-workaround.png), [Macan filters](ux-audit/screenshots/qa02-34-shop-macan-filters.png), [simple product](ux-audit/screenshots/20-qa02-simple-product-detail.png), [variant](ux-audit/screenshots/25-qa02-variant-detail.png), [out of stock](ux-audit/screenshots/26-qa02-oos-detail.png).

### Cart, checkout and payment retest

Checkout is a two-state route: contact/order preparation, then fulfillment/payment readiness.

| Flow | Screens/states | Approx. clicks | Fields | Result |
|---|---:|---:|---:|---|
| Cart → pickup pre-payment | 3 | 5–6 | 5 required + 1 optional | `PASS` |
| Cart → shipping quote | 3 | 6 before rate choice | 5 contact + 8 address + 2 optional | `EXTERNAL` after address save |
| Payment initialization/return | — | — | — | `EXTERNAL` (no sandbox key) |

Positive behavior:

- Total and readiness are explicit: “Hay cambios pendientes”, then “Listo para pagar”.
- Pickup branch application is clear and leaves “Continuar al pago” disabled until fulfillment is valid.
- Rapid “Preparar orden” created exactly one public order.
- Rapid pickup apply issued duplicate requests but kept one order/state; rapid payment records in CRM produced one payment per sale.
- Shipping-address save gives visible confirmation and exposes a retry after quote failure.
- A second checkout exercised both visible pickup branches (Bogotá, then Barranquilla), applied Barranquilla and reached pre-payment. It persisted as one distinct order despite rapid apply.

Friction and failure:

- Delivery method is selected only after asking name, email, phone, city and address/pickup point. Pickup users provide location data before saying they will collect.
- Shipping asks recipient name/phone/city/address again, creating avoidable repetition and 13 required/semirequired data entries across the two states.
- “No pudimos calcular el envío. Intenta nuevamente.” is recoverable but gives no provider/environment reason, ETA or assisted alternative.
- Wompi was not initialized because no sandbox public key is configured; approved/pending/failed/retry copy remains unverified.

Evidence: [initial checkout](ux-audit/screenshots/21-qa02-checkout-initial.png), [shipping address](ux-audit/screenshots/22-qa02-shipping-after-address.png), [quote error](ux-audit/screenshots/23-qa02-shipping-quote-result.png), [pickup ready](ux-audit/screenshots/24-qa02-pickup-ready-payment.png).

### CRM operational retest

| Process | Result | Measured outcome |
|---|---|---|
| Customer → start quote/sale | `FAIL` | Detail has contact/edit/vehicle/history actions but no quote or sale launcher; user must close and change module. |
| Admin quotation create | `FAIL` | Full form submission fails late for required `branch_id`; no branch field is rendered. |
| Collaborator quotation create/send/convert | `PASS` | Customer, vehicle, product and service persisted; conversion reused context and generated one confirmed sale. |
| Generated sale → payment → complete | `PASS` | One cash payment, zero balance, paid and completed states remain distinct and clear. |
| Direct sale → confirm → payment → complete | `PASS` | Pending copy correctly says stock is not yet consumed; confirmation precedes completion; one payment persisted. |
| Transfer request → dispatch → receive | `PASS` | Status and next action are obvious; stock moved only on lifecycle transitions and ended BAQ 9 / BOG 6. |
| Appointment create → reprogram → status → cancel | `PASS` | Availability check succeeded, historical branch remained visible, cancellation retained reason. |
| Task create → collaborator complete | `PASS` | Assignment notification increased; collaborator found and completed the task. |
| Goal create → collaborator progress | `PASS` | Collaborator saw assigned goal and registered 3/5 progress. |

The quote conversion is notably efficient: it did **not** ask for customer, vehicle, items or totals again. Preserve that behavior. Transfer lifecycle copy also correctly distinguishes request, transit and destination receipt.

Task deadline verification remains `PARTIAL`: creation, assignment, priority, visibility and completion were exercised, but the created task had no deadline. This is a coverage limitation, not a product defect.

### Collaborator portal retest

Status: `PARTIAL` overall, `PASS` for allowed destination modules.

The linked employee/branch fixture resolves the baseline account-linkage blocker. The collaborator sees own quotes, sales, commissions, tasks, goals, calendar and a permission-limited business overview. Own quote/sale lifecycle is viable and branch-scoped.

Home remains weak as a daily workspace. Even with an assigned pending task, active goal and commercial notifications, Home only promotes quotes, sales, commissions, notifications and profile; the task and goal require separate navigation. It still cannot answer “what must I do now?” in one view.

Evidence: [collaborator Home](ux-audit/screenshots/30-qa02-collaborator-home.png), [assigned goal](ux-audit/screenshots/32-qa02-collaborator-goal.png).

### Responsive retest

| Width | Storefront Shop | Checkout | Collaborator CRM |
|---:|---|---|---|
| 1440 | `PASS`, no overflow | `PASS`, no overflow | `PASS`, no overflow |
| 1024 | `PASS`, no overflow | `PASS`, no overflow | `FAIL`, 1358 px canvas |
| 768 | `PASS`, no overflow | `PASS`, no overflow | `FAIL`, 1358 px canvas |
| 430 | `PASS`, no overflow | `PASS`, no overflow | `FAIL`, 1358 px canvas |
| 390 | `PASS`, no overflow | `PASS`, no overflow | `FAIL`, 1358 px canvas |
| 360 | `PASS`, no overflow | `PASS`, no overflow | `FAIL`, 1358 px canvas |

Checkout stacks cleanly at 360 px with readable labels and fields. CRM is not merely dense: the content starts hundreds of pixels off-canvas, the horizontal navigation does not constrain itself, and core cards are clipped.

Evidence: [checkout 360](ux-audit/screenshots/qa02-checkout-360.png), [CRM 390](ux-audit/screenshots/qa02-crm-home-390.png). Full width matrix is stored under `ux-audit/screenshots/qa02-{store,checkout,crm-home}-*.png`.

### Accessibility retest

Status: `PARTIAL`.

- Checkout keyboard order at 390 px reached logo → cart → WhatsApp → back to Cart → name → email → phone → city → address → notes in a logical order.
- The task detail has `role="dialog"`, `aria-modal="true"`, a labelled title, an operative focus trap, Escape close and correct focus return to “Ver detalle”.
- Space activated “Ver detalle”; Enter did not in the sampled state and should be regression-tested with a hardware/assistive-technology pass.
- Radio/select controls were keyboard operable; async success messages were visible, but live-region announcement was not validated with a screen reader.
- Mobile CRM clipping remains an accessibility failure because focused content can be outside the visible viewport.
- Submit-with-error was observed on the completed global-admin quotation form: the server error was visible, but focus did not move to a branch control because no such control exists. Programmatic field association for that server error could not pass and is covered by the quotation blocker.

### Current Top 10 UX improvements

Ranked by impact × frequency ÷ approximate complexity:

1. Fix the default Shop query/filter semantics so stocked products render without requiring “Solo destacados”.
2. Make the CRM shell/cards/nav responsive at 1024/768/430/390/360.
3. Add a branch selector/default to global-admin quote creation before submit.
4. Ask pickup vs shipping before detailed contact/address fields and reuse entered data.
5. Lock/debounce Add to Cart on rapid clicks and make the resulting quantity explicit.
6. Put next task, active goal, next appointment and quote/sale follow-ups on collaborator Home.
7. Add “Nueva cotización” and “Nueva venta” actions to customer detail with customer/vehicle prefilled.
8. Provide deterministic shipping success/timeout/error fixtures and a configured Wompi sandbox return matrix.
9. Reduce appointment duplication by prefilling contact/vehicle and deriving end time from service duration.
10. Finish keyboard semantics/announcements, especially Enter activation and async status messages.

### Current priority groups

#### P0 — blocks operation, conversion or security

- Default Shop state hides all stocked products and stops normal discovery.
- CRM is operationally unusable below desktop width.
- Global admin quotation submission fails because required branch context is unavailable in the form.

#### P1 — important friction

- Checkout delays fulfillment choice and repeats contact/address data.
- Add to Cart accepts rapid duplicate activation.
- Shipping quote failure has no assisted fallback or deterministic QA success path.
- Collaborator Home omits assigned tasks/goals/appointments from its daily summary.
- Appointments overflows at 1440 px and remains field-heavy.

#### P2 — relevant improvement

- Customer detail quick-start actions for quotation/sale.
- Progressive Shop and product-form disclosure.
- Payment return-state QA fixtures and clearer external-service recovery.
- Persistent/shared report filters and employee operational hub.

#### P3 — polish

- Normalize dialog close patterns and business-facing Spanish microcopy.
- Refine loading-state layout stability and visible active-filter chips.

## Executive Summary

The CRM has a coherent visual language, useful business microcopy and several solid safety cues: stock is scoped by branch, a quotation explicitly does not reserve inventory, transfers explain when stock moves, and limited users receive an explicit access-denied state. The admin dashboard also gives a credible single view of sales, receivables, quotations and inventory.

The system is not ready for a production UX sign-off. The public catalog has zero products, so the primary revenue path stops at Shop. Product detail, populated cart, shipping/pickup checkout, payment and confirmation were therefore `BLOCKED`. This is the largest conversion risk and prevents an honest end-to-end verdict on checkout or payment idempotency from the interface.

The largest operational UI failure is responsive CRM behavior. At 1024 px the page measured 2315 px wide; at 390 px the collaborator view measured 929 px wide and the admin shell retained a desktop canvas. Navigation and content are clipped and require horizontal scrolling. The public storefront avoids horizontal overflow, but the Shop header overlaps its title at 390 px.

Coverage:

- Profiles: public customer, administrator, collaborator, and permission-limited behavior using the collaborator account.
- Route-level modules visited: Home, Shop, Cart, Login, Dashboard, Inventory, Movements, Transfers, Categories, Orders/Sales, Customers, Quotations, Appointments, Calendar, Employees, Schedules, Leaves, Tasks, Goals, Published Catalog, Services, Commissions, Reports, Notifications, Branches, Settings, Profile shell and collaborator portal.
- Responsive widths: 1440, 1280, 1024, 768, 430, 390 and 360 px.
- Transactional writes: none, as required by the read-only constraint.
- Evidence: 17 screenshots in [`ux-audit/screenshots`](ux-audit/screenshots/).

Top-level status:

| Area | Status | Evidence-based conclusion |
|---|---|---|
| Public discovery | PARTIAL | Home and Shop are reachable, but product inventory is empty. |
| Purchase funnel | BLOCKED | No product can be opened or added, so checkout cannot be initiated. |
| Admin operation | PARTIAL | Forms and navigation are present; empty data blocks lifecycle completion. |
| Collaborator operation | PARTIAL | Personal space works, but the seeded user is not linked to an employee and lacks daily sales/quote/commission tools. |
| Desktop visual system | PASS | Consistent, readable and well structured in most modules. |
| Tablet/mobile CRM | FAIL | Material horizontal overflow and clipping from 1024 px downward. |
| Keyboard/dialog basics | PARTIAL | Focus trap, Escape and focus return work; error focus and some control labels do not. |

## Storefront

Status: `PARTIAL`.

The first impression is distinctive and high contrast. Within five seconds the promise is clear: automotive personalization in Barranquilla. The main CTA, “Explorar artículos”, is visually dominant and correctly leads to Shop. WhatsApp, Instagram, contact content and footer navigation are present.

Observed friction:

- The desktop navigation does not expose a text link called “Tienda”; discovery relies on the hero CTA or the bag icon.
- The featured-products section shows “No hay productos destacados disponibles”, weakening trust directly after the hero.
- At mobile widths the hero contains a large empty vertical area before the value proposition. The primary CTA remains visible at 844 px but the hierarchy feels delayed.
- Vehicle exploration hotspots and carousel controls are disabled when there is no project content. That is safe, but the page does not explain why the exploration is unavailable.
- WhatsApp and Instagram were classified `EXTERNAL`; opening third-party apps was not required for a read-only audit.

Representative evidence: [desktop Home](ux-audit/screenshots/01-home-desktop-1440.png), [mobile Home](ux-audit/screenshots/10-home-mobile-390.png).

## Shop

Status: `PARTIAL`; purchase discovery result: `FAIL` for the current dataset.

The filtering model matches the business domain: text, product category/brand, vehicle brand/model/version/year, stock, featured and sorting. This could answer “what fits my vehicle” well when data exists.

Current problems:

- Zero products are available, despite one active category. The empty state gives no recovery beyond clearing filters and no route to assisted quotation.
- The UI reports “1 filtro activo” with apparently default/empty controls, which makes the user doubt the result.
- Ten visible inputs create a dense first interaction before any product is shown. Vehicle filters should progressively reveal after brand/model selection.
- Search and sort/filter controls are not consistently associated with accessible labels.
- At 390 px the store logo/header overlaps “Encuentra el upgrade compatible con tu vehículo”. This is a concrete readability defect, not an aesthetic preference.

Evidence: [desktop Shop](ux-audit/screenshots/02-shop-desktop-1440.png), [mobile overlap](ux-audit/screenshots/11-shop-mobile-390.png).

## Product Detail

Status: `BLOCKED`.

No product card existed in Shop, so there was no interface path to a detail page. Title, imagery, description, specifications, compatibility, variants, availability, price and CTA could not be evaluated. A direct URL was deliberately not used to convert this flow into a false pass.

Release gate: seed at least one public, active, in-stock product with image, price, category, compatibility and a purchasable variant; then repeat all detail and add-to-cart tests.

## Cart

Status: `PARTIAL`.

The empty state is clear, has “Seguir comprando” and “Ir a tienda”, and does not expose a checkout action with an empty cart. No horizontal overflow appeared at 390 px. Quantity editing, removal, subtotal clarity and stock reconciliation remain `BLOCKED` because nothing can be added.

Evidence: [empty Cart at 390 px](ux-audit/screenshots/12-empty-cart-mobile-390.png).

## Checkout

Status: `BLOCKED`.

The real path ended at an empty cart. Shipping/pickup selection, address, quotation, charges, persistent total, edit-address behavior, disabled-payment explanation and double-submit behavior were not reachable at any requested width. This applies to 1440, 1280, 1024, 768, 430, 390 and 360 px.

Required retest dataset:

- Public product with stock at a valid branch.
- Shipping-eligible address and pickup branch.
- Shipping quote success, timeout and error fixtures.
- Payment approved, pending, declined and retry fixtures.

## Payment

Status: `BLOCKED` for the UI return flow; `EXTERNAL` for the payment provider.

No order could be created from the storefront, so approved/pending/failed confirmation, retry visibility and duplicate-payment prevention could not be observed. The CRM sale form does expose an optional “Registrar pago ahora” section with method, amount, reference and notes, but it remains disabled until a valid sale exists.

The copy must distinguish Order status from Payment status in the retest. The current Orders page already separates the two filters, which is a positive foundation.

## Dashboard

Status: `PASS` on desktop; `FAIL` on tablet/mobile.

The admin can see sales today/month, receivables, stock alerts, quotations, cash flow, recent sales, top products, customers and financial summary without visiting five modules. General, branch-specific and comparison views are visible. Empty values are honest.

The zero-data state would be stronger with onboarding actions such as “Create first product”, “Add stock” and “Create sale”. At 1024 px the navigation/content canvas overflows horizontally.

Evidence: [desktop dashboard](ux-audit/screenshots/04-admin-dashboard-1440.png), [1024 px overflow](ux-audit/screenshots/13-dashboard-tablet-1024.png), [390 px clipping](ux-audit/screenshots/14-dashboard-mobile-390.png).

## Catalog

Status: `PARTIAL`.

Observed real sequence: Categories → Inventory/Create product → product form → Published catalog → Inventory. There is no separate “References” module in the visible IA; SKU/reference is a field in the product form. Categories define dynamic technical fields, product/variant scope and ecommerce filterability.

The product form combines seven sections in one scrollable dialog: Information, Details, Compatibility, Price, Variants, Commission and Publication. This avoids module switching but produces high cognitive load. The sticky footer is helpful; blank submission returns clear inline messages and a summary, but focus stays on “Guardar producto” instead of moving to the first invalid field.

Recommended organization:

- Always visible: identity, category, SKU, simple/variant choice, cost and sale price, active status.
- Progressive after category: category-specific technical fields.
- Progressive after “specific vehicles”: compatibility controls.
- “Advanced options”: tax/extra charges pricing modes, commission and featured flag.
- Preserve explicit ecommerce visibility and active status; do not infer publication from stock.
- Add “Guardar y asignar inventario” only after a valid save, while retaining the existing separate inventory ledger.

Evidence: [product form](ux-audit/screenshots/06-product-form-admin-1440.png), [Categories](ux-audit/screenshots/05-inventory-admin-1440.png).

## Inventory

Status: `PARTIAL`.

Existences, Movements and Transfers are grouped as clear tabs. Branch, SKU/product and low-stock filters are easy to understand. “Ajustar existencias” uses six fields and explains that it records an entry for an existing position or first branch receipt.

Transfer creation clearly states: requesting does not reduce stock; stock leaves the source only on dispatch. Origin and destination default to different branches, and Add is disabled without an in-stock article. This is good prevention. Request → dispatch → receive could not be completed because there is no stock, so lifecycle feedback and next-action prominence remain `BLOCKED`.

Evidence: [inventory](ux-audit/screenshots/05-inventory-admin-1440.png), [empty transfer](ux-audit/screenshots/07-transfer-empty-admin-1440.png).

## Sales

Status: `PARTIAL`; end-to-end sale `BLOCKED`.

The sale dialog follows customer → branch/seller → vehicle → products/services → optional payment. Selecting a branch enables product and seller controls and explains branch-scoped stock. Prices, stock and totals are explicitly confirmed by the backend.

Problems:

- The page header says “Órdenes web” while the principal module says “Órdenes y ventas”; the scope feels inconsistent.
- With no products/services, “Registrar venta” remains disabled and no safe QA sale can be completed.
- Payment adds four fields; “paid” versus “completed” could not be tested without an order.
- The seeded collaborator named “Ventas” cannot create or review sales from the personal portal.

Evidence: [sale form](ux-audit/screenshots/08-sale-form-empty-admin-1440.png).

## Customers

Status: `PARTIAL`.

Customer creation is a concise seven-field dialog. Search has a clear domain (name, phone, email, document). No customer existed, and read-only mode prohibited creation, so customer detail, vehicle, history and launch-sale/quotation actions were `BLOCKED`.

Proposal: customer detail should expose “Nueva venta” and “Nueva cotización” with customer and vehicle preselected. This removes re-search and context changes without weakening stock or authorization checks.

## Quotations

Status: `PARTIAL`; conversion `BLOCKED`.

The form supports no customer, existing customer or ad hoc customer; optional vehicle; products/services; validity and notes. It correctly states that a quotation does not reserve or consume inventory and that the backend confirms totals.

No articles exist, so creation, edit, send and convert could not be completed. Conversion must carry customer, vehicle, items, snapshots, discounts and notes, then require branch/stock/payment decisions at conversion time rather than asking the user to re-enter quote data.

## Employees

Status: `PARTIAL`.

Registration includes name, optional branch, role, specialty, phone, start date, notes, optional existing CRM user and active state. The distinction between an operational employee and a CRM account is explained well.

The employee detail acts as a partial hub with links to schedule, leaves and tasks. It omits appointments, availability summary, goals, commissions and sales performance. Adding those as context panels/links would reduce module switching without moving ownership of those records.

The only employee is inactive and not linked to the collaborator account, which blocks meaningful portal testing.

## Appointments

Status: `PARTIAL`.

Creation includes responsible employee, derived branch, customer/contact, vehicle, service, initial state, title/description/contact details and start/end. Availability is deferred until responsible employee and time are present. The only selectable employee is inactive.

The filter row causes horizontal overflow at 1440 px (1571 px document width versus 1425 px client width). The form asks for up to eleven fields at once and duplicates contact information that could be prefilled from a selected customer. The primary “Crear cita” action remains enabled before required context is complete, relying on later validation.

Evidence: [Appointments overflow](ux-audit/screenshots/09-appointments-overflow-admin-1440.png).

## Calendar

Status: `PASS` for navigation and empty state; event interaction `BLOCKED`.

Calendar is not redundant with Appointments. Appointments is the record-management list; Calendar is an aggregated operational view of appointments, tasks, leaves, work schedules and schedule adjustments. Week/Agenda, previous/today/next and filters by branch, employee, source and status are appropriate.

## Tasks

Status: `PARTIAL`.

Task creation is compact: responsible employee, title, description, priority, deadline and optional agenda scheduling. Copy correctly distinguishes a deadline from reserving agenda time. With only an inactive employee and no saved tasks, update/finalization was not exercised.

## Goals

Status: `PARTIAL`.

Filters cover employee, origin, status and date ranges. Creation supports title, description, numeric target, unit, start and due dates. The empty state says to create a goal for an active employee, but the modal preselects the only inactive employee. This contradiction should be prevented, not left to backend validation.

## Reports

Status: `PARTIAL`.

All eight requested families are present: Sales, Products, Payments, Receivables, Inventory, Movements, Quotations and Customers. Export to Excel is visible. Common branch/date filters and report-specific filters are understandable.

Issues:

- The report selector is duplicated as both a select and eight visible buttons.
- Default report period appears in the summary while From/To inputs look empty on initial load.
- Persistence of edited filters across families could not be confirmed reliably without changing report data; keep common branch/date filters stable and reset only report-specific fields.
- Empty reports explain zero values but offer no link to the relevant source module.

## Notifications

Status: `PASS` for the empty state.

“Todas” and “No leídas” are clear, and the empty state is honest. With no notifications, mark-read behavior and deep links remain `BLOCKED`.

## Settings

Status: `PASS` for information architecture and read-only inspection.

General, Sales/quotations and Users form a sensible configuration area. Branches remain a first-class Administration module, which fits their operational importance.

Categories should stay with Catalog/Inventory, not move wholesale into Settings: they directly shape product creation and ecommerce filters. A Settings placement would group configuration-like objects but separate staff from the workflow they affect. Recommendation: rename the navigation group from “Operación” to include a clearer Catalog cluster (`Productos`, `Categorías`, `Publicación`) while retaining deep links from Settings if needed.

The Users tab exposes only Administrator/User roles. Fine-grained permissions may exist behind the interface, but they are not manageable or explainable here; a truly custom limited profile could not be created read-only.

## Collaborator Portal

Status: `PARTIAL`.

The portal exposes Home, My calendar, Business overview, My tasks, My goals, Notifications and Profile. Permission denial for `/crm/orders` is explicit and safe. Business overview clearly explains that personal KPIs require linking the CRM account to an active employee.

Operational gap: the account is named “Ventas” but has no sales, quotations, customers or commissions workspace. Home only offers notifications/profile and generic future-tool copy. A collaborator should see today’s tasks/appointments, active quotes, sales needing action, personal commissions and quick actions allowed by permission. The seed/account-linking state also prevents realistic acceptance testing.

Evidence: [collaborator Home](ux-audit/screenshots/15-collaborator-home-1440.png), [collaborator mobile](ux-audit/screenshots/16-collaborator-mobile-390.png).

## Responsive

| Width | Storefront | Shop | CRM admin/collaborator |
|---:|---|---|---|
| 1440 | PASS | PASS | PARTIAL: Appointments overflows |
| 1280 | PASS | PASS | PASS in sampled dashboard |
| 1024 | PASS | PASS | FAIL: 2315 px document width |
| 768 | PASS | PASS | FAIL: desktop navigation canvas |
| 430 | PASS | FAIL: header/title collision | FAIL: clipped desktop canvas |
| 390 | PASS | FAIL: header/title collision | FAIL: admin 2315 px; collaborator 929 px |
| 360 | PASS | FAIL: collision/dense filters | FAIL: clipped desktop canvas |

Checkout at all widths: `BLOCKED` because no purchasable product/cart could be created.

## Accessibility

Status: `PARTIAL`.

Verified with real keyboard input:

- `Tab` reaches logo, Cart, WhatsApp and primary hero CTAs.
- Visible 2 px focus appears on primary CTAs and calendar controls.
- Product dialog uses `role="dialog"`, `aria-modal="true"` and an accessible title.
- `Shift+Tab` from the dialog container wraps to the final action, confirming a focus trap.
- `Escape` closes the product dialog and restores focus to “Crear producto”.

Problems:

- After blank product submit, clear inline errors appear, but focus remains on “Guardar producto” instead of the first error.
- Category list search and state select have no programmatic label in the observed DOM; similar unlabeled search/sort controls occur in Published Catalog.
- The native file input is visually inconsistent and depends on browser-language copy.
- Mobile CRM clipping makes keyboard focus location difficult to perceive even where the control itself is focusable.
- Full screen-reader semantics, announcements for async loading/success, and every modal’s trap/return behavior require a dedicated assistive-technology pass.

## Visual Consistency

Strengths: consistent dark palette, restrained cyan/yellow accents, strong display typography, predictable cards, labeled sections, sticky modal actions and coherent iconography.

Concrete issues:

- Shop mobile header collides with its H1.
- CRM tablet/mobile layouts exceed the viewport dramatically.
- Dense filter bars do not wrap safely in Appointments.
- Seven bordered product-form sections create a very long modal and make optional/required hierarchy weak.
- Both “X” and “Cerrar” patterns appear across dialogs, sometimes simultaneously.
- Loading copy such as “Buscando…” and “Cargando…” is useful, but layout often first renders zeros/empty select options and then changes, producing avoidable visual churn.

## Microcopy

| Current | Problem | Proposed |
|---|---|---|
| “Órdenes web” | Module also creates CRM sales | “Órdenes y ventas” |
| “1 filtros activos” | Grammar and hidden/default filter confusion | “1 filtro activo” plus visible filter chip |
| “No encontramos productos compatibles” | No recovery when catalog itself is empty | “Aún no hay productos publicados. Cotiza por WhatsApp o vuelve pronto.” |
| “Tus demás herramientas operativas aparecerán aquí cuando estén disponibles.” | Non-actionable collaborator Home | “Vincula tu cuenta a un empleado para ver agenda, tareas y métricas.” when applicable |
| “Volver al Dashboard” on denied collaborator page | `/crm` is not that user’s dashboard | “Volver a mi inicio” |
| “Revenue” | English term among Spanish labels | “Ingresos” |
| “Vehículo snapshot” | Technical/internal wording | “Vehículo (texto de referencia)” |
| “El backend confirmará…” | Exposes implementation detail | “Confirmaremos precio, disponibilidad y total al guardar.” |
| “Guardar producto” after errors | No recovery direction | “Revisa 3 campos obligatorios” in summary, then focus first field |

## Final UX Readiness

UX readiness: **NOT READY** for production sign-off.
Operational readiness: **PARTIAL** for desktop admin; **NOT READY** for public commerce and mobile CRM.

Release gates:

1. Publish a complete QA product/stock dataset and rerun product → checkout → payment states.
2. Fix CRM responsive behavior from 1024 px downward and the Shop mobile header collision.
3. Remove development credentials from the login form outside an explicitly marked local fixture.
4. Link a collaborator account to an active employee and provide/confirm the daily sales role workflow.
5. Retest error focus, async announcements, double submit, payment retry and order/payment state separation.

### Top 10 UX improvements

Ranked by user impact × frequency ÷ approximate complexity:

1. Publish at least one valid, purchasable product with branch stock and compatibility data.
2. Make the CRM shell responsive at 1024/768/430/390/360 px.
3. Fix the Shop mobile header/H1 collision.
4. Add permitted daily actions and actionable summaries to collaborator Home.
5. Add “Guardar y asignar inventario” after product creation.
6. Progressively disclose vehicle filters and advanced product fields.
7. Preserve customer/vehicle/items when converting a quotation to a sale.
8. Make customer detail a launch point for sale and quotation.
9. Move focus to the first invalid field and complete programmatic labels.
10. Normalize module names and business-facing copy (“Órdenes y ventas”, “Ingresos”, no “backend/snapshot”).

### Priority groups

#### P0 — blocks operation, conversion or security

- Empty public catalog blocks the complete purchase funnel.
- CRM tablet/mobile overflow blocks reliable operation below desktop widths.
- Development credentials are prefilled on Login; remove them before any production exposure.

#### P1 — important friction

- Shop mobile header/title collision.
- Collaborator account is not linked and lacks sales/quotation/commission workflows.
- Product creation is cognitively dense and requires a separate inventory context.
- Appointments overflow even at 1440 px and request too much manual contact data.
- Transactional lifecycle testing is unavailable because QA data/states are absent.

#### P2 — relevant improvement

- Customer-to-sale/quotation quick actions.
- Quote conversion should retain all existing context.
- Report filters/default period clarity and common-filter persistence.
- Employee detail should aggregate more operational context.
- Dashboard zero-state onboarding actions.

#### P3 — polish

- Normalize dialog close patterns and Spanish terminology.
- Add visible filter chips and correct singular/plural.
- Improve native file-control styling and loading-layout stability.
