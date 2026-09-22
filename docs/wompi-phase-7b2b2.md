# Wompi 7B-2B-2 — manual financial reconciliation

Extends the validated normal webhook flow. Signature, environment, private GET, reference/amount/currency and Order matching remain mandatory. No migration is needed: existing Payment reconciliation fields and free-string ledger status are sufficient.

## Financial decisions

| Case | Payment | Order |
| --- | --- | --- |
| APPROVED when cancelled or stock reverted | pending, provider_status=APPROVED, verified transaction_id, reconciliation LATE_APPROVAL | unchanged; never reactivated/credited by this event |
| Another Payment already completed and a distinct attempt is APPROVED | second Payment pending, provider_status=APPROVED, verified transaction_id, reconciliation DUPLICATE_APPROVAL | unchanged; first Payment untouched |
| VOIDED on completed Payment | refunded, provider_status=VOIDED, verified transaction_id, reconciliation VOID_AFTER_APPROVAL | only payment_status recalculated from remaining valid credit |
| Conflicting transaction ID | existing financial fields and transaction ownership preserved; reconciliation TRANSACTION_CONFLICT | unchanged |

`pending` for approved money awaiting review means pending allocation, not provider rejection. Provider APPROVED + real transaction ID + original amount + reconciliation flag are retained unambiguously. It is deliberately not completed, so normal completed-payment calculations cannot accidentally credit a cancelled order or double-count money. paid_at stays null for these held approvals. Failure strings from a previous declined attempt are cleared rather than contradicting the verified approval.

`refunded` in the VOIDED case means the formerly approved payment no longer represents current credit; this does **not** assert that our application called a refund API. Historical paid_at is preserved. Human review remains required.

Remaining credit includes only completed payments without reconciliation flags and in the Order currency. No credit => unpaid; partial valid credit => partial; credit covering the total => paid. Summation is capped at the total to avoid integer addition overflow. No order-status transition is invoked.

## Flags, retry gating and ordering

Reasons are centralized in WompiPaymentReconciliationService. Existing reconciliation_required_at and reason are preserved; missing fields are filled without resetting established timestamps. Nothing automatically clears reconciliation.

EcommercePaymentService's existing PAYMENT_RECONCILIATION_REQUIRED guard now runs before the order-status guard, including cancelled orders. PublicOrderResource already delegates can_retry_payment to Order, which checks all order payments for reconciliation; no public model/resource change or private flag exposure is needed.

Stale PENDING/DECLINED after completed approval remain fail-closed and cannot downgrade the payment. A previously processed normal duplicate remains a no-op. Expired-but-not-cancelled/unreverted approval remains outside the normal transition rules; reservation expiry processing is for 7B-3.

## Transaction and ledger

Locks remain WebhookEvent -> Payment -> Order. Existing financial savepoints preserve atomicity. A nonmatching existing Payment.transaction_id is handled only after every other authoritative matching check. A UNIQUE(provider,transaction_id) collision rolls back tentative financial changes, reads the actual owner with a locking/current read, and flags the target Payment without stealing the transaction or altering its owner. Unrelated uniqueness failures are not silently classified as transaction conflicts. Database deadlocks roll back; provider redelivery is safe.

requires_reconciliation is terminal with processed_at populated, because technical processing completed even though manual review remains. Responses are minimal HTTP 200/status only. A duplicate terminal event performs no private GET, no timestamp reset, no financial mutation and no duplicate notification.

Previously deferred failed 7B-2B-1 events with APPROVED/VOIDED and OUT_OF_SCOPE/TRANSACTION_ID_CONFLICT/TRANSACTION_ID_MISMATCH may be revisited on signed redelivery, with fresh private verification. No bulk replay, scheduler or weakening of other permanent matching failures is introduced.

## Operational alert

Reuses CrmNotificationService.distribute with existing permission payments.view. Only active users whose real permissions satisfy that capability receive the new payment_reconciliation_required type; no role/user/email is hardcoded in delivery. payments.create_own alone is insufficient. Each user/payment/reason has a dedupe key.

Payload is limited to payment_id, order_id and controlled reason; generic Spanish title/message contain no customer PII, provider response, transaction IDs or credentials. Notifications are DB writes in the same transaction, not external delivery. If alert persistence fails, financial/ledger changes roll back and the webhook can be redelivered; it is not falsely acknowledged as terminal.

## Invariants and deferred work

No InventoryStock or InventoryMovement writes by any reconciliation webhook. stock_committed_at, stock_reverted_at and stock_reservation_expires_at remain unchanged. No Order completion/cancellation, commission earning or legacy Order.payment_* writes. Real cancellation/reversal in tests occurs as fixture setup before the webhook, not inside reconciliation.

7B-3 still needs the expiry command, scheduling, locked/idempotent reservation release and the policy for reservations held by reconciled payments. No expiry worker, automatic stock reversal, refund API, automatic refund, frontend changes, packages or Git operations are included here. Review/refund resolution remains manual.
