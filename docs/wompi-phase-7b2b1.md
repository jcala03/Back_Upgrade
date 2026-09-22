# Wompi 7B-2B-1 — normal financial transitions

This phase extends, not replaces, 7B-2A signature/environment/private-query/matching. No migration. `Payment` remains authoritative; no legacy payment fields on Order are written.

## Atomic processing

The webhook locks `WebhookEvent -> Payment -> Order` in that order. Matching checks use the freshly locked Payment and Order, not a pre-lock snapshot. The bounded private lookup still precedes financial mutation. A savepoint groups financial writes and the final ledger write inside the outer ledger transaction, so even a handled domain/unique-key failure cannot commit a partial payment or paid order.

`transaction_id` is assigned only after verified matching and normal-state guards. Same ID is accepted; conflicting existing ID fails matching. The database's existing UNIQUE(provider,transaction_id) arbitrates cross-payment collisions; a collision rolls back and records `WOMPI_TRANSACTION_ID_CONFLICT`, without exposing the SQL/provider response. This is a fail-closed guard, not duplicate-approval resolution.

## State mapping

| Private provider status | Payment.status | Order.payment_status | paid_at |
| --- | --- | --- | --- |
| PENDING | pending | unpaid | null |
| APPROVED (normal) | completed | paid | server processing time |
| DECLINED | failed | unpaid | null |
| ERROR | failed | unpaid | null |
| VOIDED before approval | failed | unpaid | null |

Every successful transition persists the verified transaction ID and provider status. Failed outcomes store fixed local code/reason strings (`WOMPI_DECLINED/ERROR/VOIDED`), never the provider's free-text status message. PENDING creates no failure fields. Internal HTTP/database errors do not become provider ERROR or failed Payment.

Normal transitions require confirmed/unpaid ecommerce Order, existing unreverted reservation/branch, coherent total, an eligible non-completed Payment, and no completed/reconciliation-marked payment for the order. APPROVED additionally requires the reservation still in the future and Payment pending. PENDING does not resurrect a failed Payment. APPROVED after failed, expired approval, another completed payment and VOIDED after completion fail closed; their resolution is deferred. No reconciliation fields are written.

The same already-completed APPROVED Payment/transaction is a no-op, including stable paid_at and updated_at. Repeated PENDING/negative states avoid writes when the fields already agree. No OrderService transition or generic partial-payment PaymentService workflow is invoked. Order.status stays confirmed; no completion, commission earning, order history or inventory movement is generated.

## Ledger and retry/crash safety

New lifecycle: received -> verified -> processed. Only processed is financially terminal for successful events. `processed_at` is set after transition/no-op completion, in the same atomic transaction. Previously verified 7B-2A events are not skipped: a signed redelivery repeats private verification, locks, matching and transition. A historical verified row's old verification timestamp does not authorize skipping financial processing; it is replaced on successful processing.

Processed duplicates return 200 without requery or mutation. Unknown statuses remain ignored and permanent mismatches/out-of-scope events remain failed with safe codes; neither gets a new processed_at. Provider temporary failures stay retryable (502/503), preserving financial state. Permanent failures are acknowledged with 200/failed and need the future explicit review/replay policy, not automatic reconciliation in this phase.

A crash rolls back all partial financial/ledger work. The previously committed received or verified row remains available for signed redelivery. Tests inject failures before Order persistence and before/after the processed-ledger write; retry changes the payment once, preserving original paid_at on duplicates. A separate test verifies rollback of handled exceptions after financial writes using the savepoint. In a database deadlock, the transaction rolls back and the request may return 5xx for provider redelivery; no custom scheduler/replay worker is added.

## Invariants and tests

All normal statuses preserve InventoryStock.quantity, InventoryMovement count, stock_committed_at, stock_reverted_at, stock_reservation_expires_at, Payment.expires_at and legacy Order.payment_* values. Tests use stock actually reserved by payment-init, plus a real pending commission produced during confirmation; that commission remains pending/unearned. No new notifications or stock reversals.

7B-2A tests retain security/matching/provider-failure/unknown-status/deduplication coverage. Only success expectations change from verified to processed and from no financial writes to the newly authorized Payment/Order writes; stock/commission invariants remain checked. No tests from 7B-1 are modified.

## Explicitly deferred

7B-2B-2: late approval, duplicate approvals across Payments, VOIDED after APPROVED, reconciliation/review/replay decisions and administrative alerts; no automatic refunds. 7B-3: expiry command, scheduler and inventory release. No frontend, live Wompi HTTP, packages or Git operations.

## Final validation

Every run used APP_ENV=testing, DB_CONNECTION=mysql, DB_DATABASE=upgrade_test, TEST_DB_CONNECTION=mysql, TEST_DB_DATABASE=upgrade_test. SELECT DATABASE() was checked before each run and after the complete suite: exactly upgrade_test. No writes to upgrade.

| Run | Tests | Assertions | Failures | Errors | Skipped |
| --- | ---: | ---: | ---: | ---: | ---: |
| 7B-2B-1 focused | 35 | 355 | 0 | 0 | 0 |
| 7B-2A regression | 67 | 405 | 0 | 0 | 0 |
| Related regressions | 251 | 1792 | 0 | 0 | 0 |
| Complete suite | 577 | 4743 | 0 | 0 | 0 |

All final processes exited 0. Complete suite runner duration: 149.726 seconds. Global Pint PASS; lint PASS for all five PHP files in this phase. No code changes during final validation runs. No real Wompi HTTP requests or printed credentials.

Resume remediation was classification A (test defect): the idempotency test reused a three-argument provider but accepted only one argument, producing a nonzero PHPUnit exit despite zero test failures/errors. Its signature now accepts all three values and asserts the expected financial statuses. No production logic/schema change during resume. Earlier fixture defects (required Employee.job_title and comparison of newly-created versus freshly-loaded model attributes) were corrected in tests only.

Phase files (six):

- New: app/Services/WompiPaymentTransitionService.php
- Modified: app/Services/WompiWebhookService.php
- Modified: app/Models/WebhookEvent.php
- New: tests/Feature/WompiFinancialTransitionsPhaseSevenTest.php
- Modified: tests/Feature/WompiWebhookPhaseSevenTest.php
- New: docs/wompi-phase-7b2b1.md

Unresolved findings: BLOCKER=0, IMPORTANT=0, MINOR=0. Deferred behaviors above remain intentionally unimplemented.
