# UX Findings

Audit date: 2026-09-28. Findings come from the rendered interface and real browser interaction. `BLOCKED` in reproduction text is a flow status; the Severity column uses the requested product-priority vocabulary.

| ID | Profile | Module | Problem | How to reproduce | Impact | Severity | Recommendation | Screenshot |
|---|---|---|---|---|---|---|---|---|
| UX-001 | Public | Shop / purchase | The public catalog returns zero products, blocking Product → Cart → Checkout → Payment. | Home → click “Explorar artículos”; observe 0 results with defaults. | 100% conversion stop; critical flows cannot be assessed. | BLOCKER | Publish a complete QA/production product with stock, price, image and compatibility; retest the funnel. | [Shop](ux-audit/screenshots/02-shop-desktop-1440.png) |
| UX-002 | Public | Home | Featured-products section is empty. | Scroll Home to “Productos para elevar el look”. | Reduces trust and gives no direct product route after the hero. | MINOR | Show curated products or replace the empty section with assisted quotation content. | [Home](ux-audit/screenshots/01-home-desktop-1440.png) |
| UX-003 | Public | Global navigation | Desktop navigation has no explicit “Tienda” label; only hero CTA and bag icon expose commerce. | Inspect Home header and primary navigation. | Repeat visitors may not immediately find the catalog. | MINOR | Add a clear “Tienda” navigation item; retain Cart icon. | [Home](ux-audit/screenshots/01-home-desktop-1440.png) |
| UX-004 | Public | Shop mobile | Logo/header overlaps the Shop H1 at 430/390/360 px. | Open Shop at 390 px. | Value proposition becomes partially unreadable at the start of shopping. | IMPORTANT | Reserve header height and position the hero below it; verify all three mobile widths. | [390 px](ux-audit/screenshots/11-shop-mobile-390.png) |
| UX-005 | Public | Shop filters | UI reports an active filter when visible controls appear at defaults. | Open Shop with no deliberate filter changes. | Users may blame a hidden filter for zero results. | MINOR | Show a named filter chip or report zero active filters when defaults are applied. | [Shop](ux-audit/screenshots/02-shop-desktop-1440.png) |
| UX-006 | Public | Shop filters | Ten controls are shown before any progressive vehicle selection. | Open Shop and scan Advanced search. | High cognitive load; slow discovery for first-time buyers. | IMPORTANT | Reveal model/version/year only after their parent selection and collapse advanced technical filters. | [Mobile filters](ux-audit/screenshots/11-shop-mobile-390.png) |
| UX-007 | Public | Empty Cart | “Seguir comprando” and “Ir a tienda” lead to the same destination without a differentiated purpose. | Open Cart via the bag with no products. | Redundant choice in an otherwise good empty state. | MINOR | Keep one primary return-to-Shop action. | [Cart](ux-audit/screenshots/12-empty-cart-mobile-390.png) |
| UX-008 | Admin / Collaborator | CRM responsive | CRM document is 2315 px wide at 1024 px; mobile retains a clipped desktop canvas. Collaborator is 929 px wide at 390 px. | Open Dashboard at 1024/768/430/390/360; horizontally scroll. | Tablet/mobile operation is unreliable; navigation and controls leave the viewport. | BLOCKER | Implement a responsive shell: collapsible nav, wrapping controls/cards, responsive tables/dialogs and correct viewport behavior. | [1024](ux-audit/screenshots/13-dashboard-tablet-1024.png), [390](ux-audit/screenshots/14-dashboard-mobile-390.png) |
| UX-009 | Admin | Appointments | Filter row overflows even at 1440 px (1571 px content vs 1425 px client). | CRM → Citas at 1440 px. | Filters/actions can be missed; magnification worsens it. | IMPORTANT | Wrap filters into responsive rows or a filter drawer; remove fixed minimum widths. | [Appointments](ux-audit/screenshots/09-appointments-overflow-admin-1440.png) |
| UX-010 | Admin | Product form | Seven sections and 18+ controls appear in one long modal. | Inventory → Create product. | High cognitive load; optional decisions compete with essential data. | IMPORTANT | Use progressive disclosure and “Advanced options”; keep sticky save actions. | [Product form](ux-audit/screenshots/06-product-form-admin-1440.png) |
| UX-011 | Admin / Keyboard | Product validation | Blank submit shows clear messages, but focus remains on “Guardar producto”. | Create product → submit blank → inspect focus. | Keyboard/screen-reader users must find the first error manually. | IMPORTANT | Focus the first invalid field and connect summary/errors with `aria-describedby`/live status. | [Product form](ux-audit/screenshots/06-product-form-admin-1440.png) |
| UX-012 | Admin / Keyboard | Categories / Published catalog | Search and state controls lacked an observed programmatic label. | CRM → Categories; inspect/tab search and state select. | Screen-reader purpose is ambiguous. | MINOR | Add persistent `<label>` or `aria-label` matching visible intent. | — |
| UX-013 | Admin / Security | Login | Development credentials are prefilled on the Login screen. | Log out and open Login. | Risk of accidental credential exposure outside a strictly local fixture; undermines trust. | IMPORTANT | Never prefill credentials outside explicitly marked local development; use documented test-account setup. | [Login](ux-audit/screenshots/03-login-desktop-1440.png) |
| UX-014 | Public | Environment/origin | Public API requests failed when the same app was opened through `127.0.0.1`, while `localhost` worked. | Open the local frontend using the alternate loopback host. | Brittle QA/demo behavior and misleading empty/error states. | MINOR | Align allowed origins and document one canonical local URL; show a clear network error. | — |
| UX-015 | Admin | Orders/Sales | Page heading says “Órdenes web” while the module creates CRM sales too. | CRM → Orders. | Users may not know where counter sales belong. | MINOR | Use “Órdenes y ventas” consistently in page title, heading and navigation. | [Sale form](ux-audit/screenshots/08-sale-form-empty-admin-1440.png) |
| UX-016 | Admin | Inventory/Sales/Quotes | No products or stock exist, so transfer, sale, quotation and downstream lifecycle actions cannot complete. | Open Inventory, Transfer, Sale or Quote creation. | Operational acceptance is impossible; disabled actions dominate. | IMPORTANT | Provide deterministic QA fixtures spanning two branches and lifecycle states. | [Transfer](ux-audit/screenshots/07-transfer-empty-admin-1440.png), [Sale](ux-audit/screenshots/08-sale-form-empty-admin-1440.png) |
| UX-017 | Collaborator | Account linkage | Seeded collaborator is not linked to an active employee. | Login as collaborator → Business overview / My goals. | Personal calendar, goals, KPIs and operational relevance are largely empty. | IMPORTANT | Link a QA collaborator to an active employee/branch; surface linkage status on Home. | [Portal](ux-audit/screenshots/15-collaborator-home-1440.png) |
| UX-018 | Collaborator | Home | Home exposes Notifications/Profile only; no daily tasks, appointments, sales, quotes or commissions. | Login as collaborator and inspect Home. | User cannot answer “what should I do now?” without visiting multiple modules; sales role lacks sales tools. | IMPORTANT | Add permission-safe today/overdue/follow-up cards and quick actions. | [Portal](ux-audit/screenshots/15-collaborator-home-1440.png) |
| UX-019 | Admin | Users/permissions | Settings exposes only Administrator/User roles; no UI for explaining or configuring a custom limited profile. | Settings → Users. | Permission intent is opaque; support/admin cannot reason about capabilities. | DECISION | Decide whether permissions are fixed roles or configurable capabilities; expose read-only capability summary at minimum. | — |
| UX-020 | Admin | Goals | Empty state requests an active employee, but New goal preselects the only inactive employee. | Goals → New goal. | Creates a contradictory path likely to fail late. | IMPORTANT | Exclude inactive employees or require an explicit override with consequence copy. | — |
| UX-021 | Admin | Employee detail | Detail links Schedule, Leaves and Tasks, but omits appointments, availability, goals, commissions and performance. | Employees → Ver. | Repeated module switching for employee-centered work. | MINOR | Extend detail as a permission-aware operational hub with links/summaries. | — |
| UX-022 | Admin | Reports | Report family is selectable through both a select and eight buttons. | CRM → Reports. | Duplicate controls increase visual density and decision ambiguity. | MINOR | Keep one responsive family selector: tabs on wide screens, select on narrow screens. | — |
| UX-023 | Admin | Reports | Initial summary applies a date period, while From/To fields look empty. | Open Reports without changing filters. | Users cannot tell which filters generated the result. | MINOR | Populate displayed defaults in the inputs or label them as automatic period. | — |
| UX-024 | Admin | Appointments | Appointment creation exposes up to eleven fields and repeats contact/vehicle data even when a customer could supply it. | Citas → Nueva cita. | Slow entry, more errors, poor mobile viability. | IMPORTANT | Prefill from customer/vehicle, use duration, and place description/snapshot under optional details. | [Appointments](ux-audit/screenshots/09-appointments-overflow-admin-1440.png) |
| UX-025 | Public/Admin | Microcopy | UI uses technical terms including “backend”, “snapshot” and English “Revenue”. | Open Sale, Quotation, Appointment and Reports. | Business users must translate implementation terminology. | MINOR | Use consequence-oriented Spanish: “confirmaremos”, “vehículo de referencia”, “ingresos”. | — |
| UX-026 | Public | Payment | No UI-accessible fixtures for approved, pending, failed and retry states. | Attempt real funnel; stopped before Product detail. | Duplicate-payment risk, retry clarity and return messaging are unverified. | IMPORTANT | Add safe QA order/payment states reachable through the interface and repeat the audit. | — |
| UX-027 | All | Double submit | Destructive/transactional submit locking could not be verified without valid records; blank product validation leaves submit enabled. | Open create flows; valid submission unavailable under read-only/empty data. | Residual risk of duplicate action remains unknown. | DECISION | Add an explicit QA test plan for rapid double click, disabled/submitting state and navigation during requests. | — |
| UX-028 | Admin | Dashboard | Zero-data dashboard has metrics but no onboarding actions. | Open Dashboard in current dataset. | New admin sees state but not the shortest recovery path. | MINOR | Add contextual links for first product, stock, customer, quote and sale. | [Dashboard](ux-audit/screenshots/04-admin-dashboard-1440.png) |
| UX-029 | Admin | Dialogs | Dialog close affordances vary between X-only, “Cerrar”, or both. | Compare Product, Category, Sale, Appointment and Employee dialogs. | Small consistency and keyboard-learning cost. | POLISH | Standardize header close icon, Escape behavior and footer Cancel wording. | [Product form](ux-audit/screenshots/06-product-form-admin-1440.png) |
| UX-030 | Admin | Product form UI | Native file input uses browser-language styling unlike the rest of the design system. | Inventory → Create product → Image principal. | Visual inconsistency and less helpful upload guidance. | POLISH | Use an accessible styled upload control with accepted format/size guidance. | [Product form](ux-audit/screenshots/06-product-form-admin-1440.png) |
| UX-031 | Admin | Product → Inventory | Product form explicitly sends users to Inventory; no post-save “assign stock” action was available to test. | Create product form → read Inventory section and “Gestionar inventario”. | Likely re-navigation and re-search immediately after creation. | IMPORTANT | On successful save offer “Save and assign inventory”, carrying the new product ID but keeping a separate stock movement. | [Product form](ux-audit/screenshots/06-product-form-admin-1440.png) |
| UX-032 | Admin | Information architecture | Categories sits under Operations while Published catalog is a separate Catalog group; product creation lives in Inventory. | Scan sidebar, then follow Categories → Inventory → Published catalog. | Catalog setup is split across three mental locations. | DECISION | Group navigation as Products, Categories and Publication while preserving Inventory as the stock ledger; validate with staff terminology. | [Inventory](ux-audit/screenshots/05-inventory-admin-1440.png) |

## Counts

- BLOCKER: 2
- IMPORTANT: 13
- MINOR: 12
- POLISH: 2
- DECISION: 3
- Total: 32

## Verified positive behavior

These are not defects and should be preserved:

- Product dialog traps focus, closes with Escape and returns focus to its launcher.
- Transfer copy distinguishes request, dispatch and receipt; request does not decrement stock.
- Quotation copy states that a quote does not reserve inventory.
- Sale copy states that prices, stock and totals are confirmed by the server.
- Limited user sees a clear access-denied page instead of admin content.
- Calendar and Appointments serve distinct purposes: aggregated schedule versus record management.
- Empty Cart blocks checkout and offers a route back to Shop.

## UX-QA-02 operational retest disposition

The original table above is the immutable read-only baseline. The following disposition records what changed after deterministic QA data was persisted and the formerly blocked flows were exercised through the UI.

| Baseline ID | Retest status | Current conclusion |
|---|---|---|
| UX-001 | RECLASSIFIED | Products now exist, but the default Shop request still returns zero because `in_stock=1` and `featured=0` exclude the available fixtures. The conversion blocker remains for a different cause; see UX-033. |
| UX-002 | RESOLVED_BY_QA_DATA | Featured QA products are published and available; product content is no longer globally absent. |
| UX-003 | STILL_OPEN | No new explicit desktop “Tienda” navigation label was observed. |
| UX-004 | CONFIRMED | No code changed; the prior mobile header/title visual collision remains an open visual issue even though document overflow is false. |
| UX-005 | RECLASSIFIED | The unexplained default filter is now proven to suppress stocked products, increasing severity; see UX-033. |
| UX-006 | CONFIRMED | Shop still presents the full filter set before progressive vehicle choices. |
| UX-007 | STILL_OPEN | The empty-cart duplicate destination was not removed; populated-cart testing does not invalidate it. |
| UX-008 | CONFIRMED | Collaborator Home is 1358 px wide at 1024/768/430/390/360; see current screenshots. |
| UX-009 | CONFIRMED | Appointments measured 1571 px against a 1425 px viewport at nominal 1440. |
| UX-010 | STILL_OPEN | Product-form structure is unchanged and remains dense. |
| UX-011 | STILL_OPEN | No implementation change occurred; error-focus remediation remains required. |
| UX-012 | STILL_OPEN | No labeling change occurred in the sampled controls. |
| UX-013 | CONFIRMED | Login still rendered prefilled development credentials before QA values were entered. |
| UX-014 | STILL_OPEN | Canonical-origin/CORS behavior was not changed; the retest intentionally used `localhost`. |
| UX-015 | STILL_OPEN | Global admin information architecture remains unchanged. |
| UX-016 | RESOLVED_BY_QA_DATA | Products, branch stock, transfer, quotation and sale lifecycles are now executable. |
| UX-017 | RESOLVED_BY_QA_DATA | Collaborator is linked to an active employee at Barranquilla with operational capabilities. |
| UX-018 | CONFIRMED | Even with a pending task and active goal, Home does not summarize them. |
| UX-019 | STILL_OPEN | Role/capability explanation remains unchanged. |
| UX-020 | RESOLVED_BY_QA_DATA | Active employee is selectable; assigned goal creation and collaborator progress both succeed. |
| UX-021 | STILL_OPEN | Employee-detail hub scope is unchanged. |
| UX-022 | STILL_OPEN | Report family controls remain duplicated. |
| UX-023 | STILL_OPEN | Report default-period clarity remains unchanged. |
| UX-024 | CONFIRMED | Creation still exposes repeated contact/vehicle fields and two manual datetimes. |
| UX-025 | CONFIRMED | “backend” and “snapshot” remain visible in business-facing copy. |
| UX-026 | RECLASSIFIED | Product/order prerequisites are resolved, but payment return states are `EXTERNAL` because no Wompi sandbox public key is configured. |
| UX-027 | RECLASSIFIED | Rapid transactional submits created one order/quote/transfer/appointment/task/goal/payment each, but rapid Add to Cart added two variant units; see UX-034. |
| UX-028 | RESOLVED_BY_QA_DATA | The dashboard no longer depends on a completely empty operation, though onboarding links remain a useful enhancement. |
| UX-029 | STILL_OPEN | Dialog close patterns remain inconsistent. |
| UX-030 | STILL_OPEN | Native upload styling is unchanged. |
| UX-031 | STILL_OPEN | No product-save → inventory continuation was introduced. |
| UX-032 | STILL_OPEN | Catalog/navigation ownership is unchanged. |

## UX-QA-02 new findings

| ID | Profile | Module | Problem | How to reproduce | Impact | Severity | Recommendation | Screenshot |
|---|---|---|---|---|---|---|---|---|
| UX-033 | Public | Shop | Default Shop hides all available products. The UI requests in-stock plus non-featured (`featured=0`); stocked QA products are featured, while the only non-featured product is out of stock. | Home → “Explorar artículos”; wait for loading: 0 products. Toggle “Solo destacados”: two stocked products appear. | Normal product discovery stops before any product detail; users have no reason to discover the workaround. | BLOCKER | Do not send `featured=0` as an exclusion when the toggle is off; treat off as “all”, and add regression coverage for default/in-stock/featured combinations. | [Default](ux-audit/screenshots/qa02-store-1440.png), [workaround](ux-audit/screenshots/19-qa02-shop-after-stock-filter-workaround.png) |
| UX-034 | Public | Product / Cart | Rapid double activation of Add to Cart increments the variant twice. | Select the QA variant and activate Add to Cart twice immediately; cart count moves from 1 to 3. | Accidental over-ordering and surprising totals; especially likely with latency or assistive input. | IMPORTANT | Disable/lock during the state transition, coalesce duplicate activation, and announce the resulting quantity. Preserve deliberate later quantity changes. | [Variant](ux-audit/screenshots/25-qa02-variant-detail.png) |
| UX-035 | Public | Checkout | Fulfillment is chosen after five required contact/location fields; shipping then repeats recipient, phone, city and address concepts. | Cart → Checkout → complete contact → Prepare order → choose Shipping. | More abandonment and data-entry errors; pickup users provide unnecessary location detail. | IMPORTANT | Ask pickup/shipping first, reuse contact data, and reveal only relevant fields. Keep server-side quote/address validation. | [Initial](ux-audit/screenshots/21-qa02-checkout-initial.png), [shipping](ux-audit/screenshots/22-qa02-shipping-after-address.png) |
| UX-036 | Public | Shipping | Address saves, but “Calcular envío” returns only “No pudimos calcular el envío. Intenta nuevamente.” with no assisted fallback. | Complete QA shipping address → Save → Calculate Shipping. | Shipping customer cannot proceed or understand whether retrying will help. | IMPORTANT | Provide deterministic QA carrier fixtures; in production distinguish temporary/provider/address errors and offer assisted quotation without losing the order. | [Quote error](ux-audit/screenshots/23-qa02-shipping-quote-result.png) |
| UX-037 | Admin | Quotations | Global-admin quote form cannot provide required branch context and fails only after the full form is completed. | Admin → Quotations → New → customer/vehicle/product/service/notes → Create. | Admin cannot create a quotation and loses time after a late error. | BLOCKER | Add an explicit required branch selector or an unmistakable active-branch context before items. Never infer a branch silently for a global admin. | — |
| UX-038 | Collaborator | Home | Linked collaborator with an assigned task, active goal and notifications still sees no “today” summary for those items. | Create task/goal as admin → login collaborator → Home. | Daily priorities require multiple module visits. | IMPORTANT | Add permission-safe next appointment, pending task, active goal and commercial follow-up cards. | [Home](ux-audit/screenshots/30-qa02-collaborator-home.png) |
| UX-039 | Keyboard | Task detail | Space activates the focused “Ver detalle” button, while Enter did not in the sampled browser state. Dialog trap, Escape and focus return otherwise pass. | Open/close task detail with Escape; with focus returned to Ver detalle, press Enter, then Space. | Inconsistent keyboard activation for users who expect either standard key. | MINOR | Add a browser/AT regression test for native-button Enter/Space activation before changing code; retain native `<button>` semantics. | — |
| UX-040 | Public | Payment | Pickup reaches “Listo para pagar”, but the QA runtime has no Wompi sandbox public key; approved/pending/failed/retry and duplicate payment initiation remain unobservable. | Complete pickup → inspect configured payment environment before external initialization. | Payment readiness cannot receive a release verdict. | DECISION | Configure test-only Wompi keys and deterministic return fixtures; never use production payment credentials for this audit. | [Pre-payment](ux-audit/screenshots/24-qa02-pickup-ready-payment.png) |

## UX-QA-02 current counts

Counts below represent the consolidated current open set, not a sum of the historical and retest tables:

- BLOCKER: 3
- IMPORTANT: 12
- MINOR: 11
- POLISH: 2
- DECISION: 4

## UX-QA-02 verified positive behavior

- Rapid create/submit produced exactly one public order, quotation, transfer, appointment, task and goal; each tested sale has exactly one completed payment.
- Two independent public checkout identities produced exactly two orders (`UG79-20260928-CXML7V` and `UG79-20260928-XGSCXL`), one apiece; both visible pickup branches were selectable.
- Porsche → Macan → Base 2022–2026 → 2024 filters were operable by keyboard and retained the compatible variant product.
- Pickup keeps payment disabled until a branch is applied and then explains “Listo para pagar”.
- Out-of-stock product has zero availability and a disabled Add to Cart action.
- Quote conversion preserves customer, vehicle, product, service and totals without re-entry.
- Pending sale explicitly does not consume inventory; paid and completed remain separate states.
- Transfer request, dispatch and receipt expose the correct next action; persisted movements prove BAQ 10 → 9 at dispatch and BOG 5 → 6 at receipt.
- Appointment availability, reprogramming, status and cancellation reason work end to end.
- Collaborator sees only own branch-scoped commercial records and can complete assigned work.
- Task detail dialog traps focus, closes with Escape and returns focus to the launcher.
