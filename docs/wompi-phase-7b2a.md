# Wompi 7B-2A: verification only

`POST /api/webhooks/wompi` accepts JSON and returns only a status or a fixed error code. It does not mutate Payment, Order, inventory, commissions or reservation expiry. No payment transaction ID is assigned yet. No migration is needed.

## Authentication and middleware

The exact route excludes Sanctum's `EnsureFrontendRequestsAreStateful`, which otherwise installs session/cookie/CSRF middleware for first-party origins. It is outside the authenticated CRM group. No global CSRF exemption is added; other routes retain their existing middleware.

The configured base URL explicitly maps sandbox to `test`, production to `prod`; other bases fail closed. Events-secret and private-key prefixes must match the environment. Signature properties are resolved relative to `data`, in received order. Strings are preserved byte-for-byte, integers become decimal strings. Missing paths, objects, arrays, booleans, nulls and floating-point values fail closed because their concatenation is not specified by the documented contract. No fixed property list is assumed.

SHA256 input: concatenated property values + integer timestamp + events secret. Hex checksums are case-insensitive; comparison uses `hash_equals`. Either header or body is sufficient; both, if present, must agree. Raw JSON is decoded independently of Laravel's string trimming. Unknown signed event types return `200 ignored`, without ledger or private query.

References: [official events contract](https://docs.wompi.co/docs/colombia/eventos/) and [private transaction lookup](https://docs.wompi.co/docs/colombia/transacciones/).

Documentation QA note: the displayed sample checksum does not match SHA256 of the documented concatenation. The test uses those inputs with the independently recomputed digest, not the inconsistent displayed checksum. The implementation follows the documented algorithm without exceptions.

## Ledger identity and races

`event_key = SHA256(JSON([environment,event,transaction.id,transaction.status,transaction.reference ?? null,transaction.amount_in_cents ?? null,transaction.currency ?? null]))`.

JSON flags: `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR`. Fixed positional tuple, no whitespace; optional missing values are null. Timestamps, property ordering and checksum casing do not alter semantic identity. `payload_hash = SHA256(raw request body)` records the first delivery exactly, including whitespace. It is not authentication.

Insert is protected by existing `UNIQUE(provider,event_key)` with explicit unique-violation recovery. Processing locks that ledger row in a DB transaction, including the bounded private GET. Concurrent deliveries serialize; only the first successful verifier queries Wompi. Verified, ignored and permanent-failed duplicates return the stored outcome without updates or query. A crash rolls back processing, leaving the committed received row retryable. Retryable failures reuse the same row. Race tests deterministically inject the competing insert; they do not claim a multi-process stress test.

Only metadata `environment`, `provider_status` (known enum only), and `retryable` is stored. Errors contain fixed domain codes. Full bodies, private responses, PII, signatures, credentials and status messages are not persisted or logged. Event type and provider transaction ID are bounded/validated structural identifiers.

## Private lookup and matching

GET `{base_url}/transactions/{encoded_id}`, Accept JSON, Bearer private key, connect timeout 3s, total timeout 10s per attempt. At most two attempts for connection failures or 5xx; no in-request retry for 4xx, no redirects. Transport exceptions and response bodies are not chained into domain errors.

Private keys must contain only visible ASCII without whitespace; invalid header characters are rejected as configuration errors before the HTTP library can include the credential in its exception text.

The immutable DTO validates ID/reference/status strings, positive integer cents, uppercase three-letter currency, nullable string payment-method type/status message. Provider JSON is not returned or automatically stored.

Matching requires event ID/status and any provided reference/amount/currency to agree with the private response. Payment is resolved by provider/reference and checked byte-exactly after SQL lookup (collation can be case-insensitive). Payment amount is validated before integer conversion/multiplication to avoid overflow; cents, currency and any existing transaction ID must agree. Related Order must exist, be ecommerce and have matching currency. Matching is a verification snapshot, **not authorization for future writes**: 7B-2B must revalidate under the appropriate operational locks.

Known statuses: PENDING, APPROVED, DECLINED, VOIDED, ERROR. None is mapped to financial state. Unknown provider status is ignored/audited after consistency checks.

## HTTP policy

| Outcome | HTTP | Ledger |
| --- | --- | --- |
| Verified/duplicate | 200 | verified, no duplicate processing |
| Unsupported signed event | 200 | none |
| Unsupported matched status | 200 | ignored, fixed reason |
| Permanent matching inconsistency | 200 | failed, retryable=false, fixed reason; requires operator review |
| Bad JSON/structure/environment | 400 | none |
| Wrong content type | 415 | none |
| Invalid/mismatched checksum | 401 | none |
| Missing/mismatched configuration | 503 | failed if a valid event reached lookup; otherwise none |
| Provider 404/401/403/429/5xx/network/redirect | 503 | failed, retryable=true |
| Invalid provider response | 502 | failed, retryable=true |

200 means delivery acknowledged, **not payment accepted**. Permanent mismatches are visible as `status=failed` and require ledger review, not infinite provider retries. Temporary/provider/configuration failures allow redelivery. An unchanged permanently failed event requires an explicit future operational replay policy after review; none is implemented here.

## Deferred to 7B-2B

Financial state transitions, definitive Payment.transaction_id/provider_status persistence, paid Order state, concurrency across distinct events/payment attempts, late approvals, reconciliation/refunds, reservation expiry and scheduling. Verification ledger `verified` must not be confused with financially processed; a future phase must explicitly handle already-verified events. No frontend or live Wompi calls are part of this phase.

## Validation — 2026-09-21

Effective database was checked before runs and after the final suite: `upgrade_test`, connection `mysql`, application environment `testing`; both `TEST_DB_*` overrides match. No writes to `upgrade`.

| Run | Tests | Assertions | Failures | Errors | Skipped |
| --- | ---: | ---: | ---: | ---: | ---: |
| Final 7B-2A focused | 67 | 402 | 0 | 0 | 0 |
| Related regressions | 191 | 1277 | 0 | 0 | 0 |
| Final full suite | 542 | 4385 | 0 | 0 | 0 |

Final full suite: 128.15 seconds (JUnit). Global Pint PASS; PHP lint PASS for all nine new/modified PHP files. Outbound Wompi calls were exclusively faked, with stray requests prohibited. No frontend, packages or Git operations. No real secrets printed. No unresolved blocker/important/minor findings.

New files:

- `app/Exceptions/WompiWebhookException.php`
- `app/Http/Controllers/Api/WompiWebhookController.php`
- `app/Services/WompiClient.php`
- `app/Services/WompiWebhookService.php`
- `app/Services/WompiWebhookSignatureService.php`
- `app/Support/Payments/WompiTransaction.php`
- `tests/Feature/WompiWebhookPhaseSevenTest.php`
- `docs/wompi-phase-7b2a.md`

Modified existing files: `app/Models/WebhookEvent.php` (internal ledger statuses), `routes/api.php` (exact stateless webhook route). No migration, operational model, 7B-1 payment-init implementation, config or frontend changes.
