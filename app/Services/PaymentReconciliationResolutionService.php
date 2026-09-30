<?php

namespace App\Services;

use App\Exceptions\PaymentReconciliationException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentReconciliationAction;
use App\Models\PaymentReconciliationReview;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class PaymentReconciliationResolutionService
{
    public const DECISION_REFUND_REQUIRED = 'refund_required';

    public const DECISION_REFUND_CONFIRMED_EXTERNALLY = 'refund_confirmed_externally';

    public const DECISION_ESCALATED = 'escalated';

    public const DECISION_PROVIDER_VOID_VERIFIED = 'provider_void_verified';

    public const DECISION_CONFLICT_OWNERSHIP_VERIFIED = 'conflict_ownership_verified';

    public const DECISION_INVALID_EVENT_CONFIRMED = 'invalid_event_confirmed';

    public const DECISION_LOCAL_PAYMENT_ABSENCE_CONFIRMED = 'local_payment_absence_confirmed';

    public const DECISION_PROVIDER_TRANSACTION_ABSENCE_CONFIRMED = 'provider_transaction_absence_confirmed';

    /** @return array{review: PaymentReconciliationReview, action: PaymentReconciliationAction, replayed: bool} */
    public function begin(PaymentReconciliationReview $review, User $actor, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($review, $actor, $idempotencyKey) {
            $locked = PaymentReconciliationReview::query()->lockForUpdate()->findOrFail($review->id);
            $this->authorize($actor);
            $fingerprint = $this->requestFingerprint('start', $locked, $actor, []);

            if ($replay = $this->replay($locked, $idempotencyKey, $fingerprint)) {
                return ['review' => $locked->fresh(), 'action' => $replay, 'replayed' => true];
            }
            if ($locked->state !== PaymentReconciliationReview::STATE_DETECTED) {
                throw new PaymentReconciliationException('RECONCILIATION_STATE_CONFLICT');
            }

            $now = now();
            $locked->update([
                'state' => PaymentReconciliationReview::STATE_UNDER_REVIEW,
                'assigned_to' => $actor->id,
                'review_started_at' => $now,
            ]);
            $action = $locked->actions()->create([
                'actor_id' => $actor->id,
                'action' => PaymentReconciliationAction::ACTION_REVIEW_STARTED,
                'previous_state' => PaymentReconciliationReview::STATE_DETECTED,
                'new_state' => PaymentReconciliationReview::STATE_UNDER_REVIEW,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'created_at' => $now,
            ]);

            return ['review' => $locked->fresh(), 'action' => $action, 'replayed' => false];
        }, 3);
    }

    /**
     * @param  array{decision: string, justification: string, evidence_reference?: ?string, canonical_payment_id?: ?int}  $data
     * @return array{review: PaymentReconciliationReview, action: PaymentReconciliationAction, replayed: bool}
     */
    public function decide(PaymentReconciliationReview $review, User $actor, string $idempotencyKey, array $data): array
    {
        return DB::transaction(function () use ($review, $actor, $idempotencyKey, $data) {
            $lockedPayment = $review->payment_id === null
                ? null
                : Payment::query()->lockForUpdate()->findOrFail($review->payment_id);
            $locked = PaymentReconciliationReview::query()->lockForUpdate()->findOrFail($review->id);
            $this->authorize($actor);
            $normalized = [
                'decision' => $data['decision'],
                'justification' => trim($data['justification']),
                'evidence_reference' => isset($data['evidence_reference']) ? trim($data['evidence_reference']) : null,
                'canonical_payment_id' => isset($data['canonical_payment_id']) ? (int) $data['canonical_payment_id'] : null,
            ];
            $normalized['evidence_reference'] = $normalized['evidence_reference'] === '' ? null : $normalized['evidence_reference'];
            $fingerprint = $this->requestFingerprint('decision', $locked, $actor, $normalized);

            if ($replay = $this->replay($locked, $idempotencyKey, $fingerprint)) {
                return ['review' => $locked->fresh(), 'action' => $replay, 'replayed' => true];
            }
            if ($locked->state !== PaymentReconciliationReview::STATE_UNDER_REVIEW) {
                throw new PaymentReconciliationException('RECONCILIATION_STATE_CONFLICT');
            }

            $this->validateDecision($locked, $normalized);
            $terminal = $this->isTerminal($normalized['decision']);
            $newState = $terminal
                ? PaymentReconciliationReview::STATE_RESOLVED
                : PaymentReconciliationReview::STATE_UNDER_REVIEW;
            $now = now();
            $values = ['state' => $newState, 'latest_decision' => $normalized['decision']];
            if ($terminal) {
                $values += ['resolved_at' => $now, 'resolved_by' => $actor->id];
            }
            $locked->update($values);
            $action = $locked->actions()->create([
                'actor_id' => $actor->id,
                'action' => $terminal
                    ? PaymentReconciliationAction::ACTION_RESOLVED
                    : PaymentReconciliationAction::ACTION_DECISION_RECORDED,
                'previous_state' => PaymentReconciliationReview::STATE_UNDER_REVIEW,
                'new_state' => $newState,
                'decision' => $normalized['decision'],
                'justification' => $normalized['justification'],
                'evidence_reference' => $normalized['evidence_reference'],
                'canonical_payment_id' => $normalized['canonical_payment_id'],
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'created_at' => $now,
            ]);

            if ($terminal && $locked->payment_id !== null) {
                $this->syncLegacyMarker($lockedPayment);
            }

            return ['review' => $locked->fresh(), 'action' => $action, 'replayed' => false];
        }, 3);
    }

    public function detect(
        string $reason,
        WebhookEvent $event,
        ?Payment $payment = null,
        ?Order $order = null,
    ): PaymentReconciliationReview {
        if (! $this->supportsReason($reason)) {
            throw new LogicException('Unsupported reconciliation reason.');
        }

        $orderId = $order?->id ?? $payment?->order_id;
        $key = $this->detectionKey([
            'provider' => $event->provider,
            'event_key' => $event->event_key,
            'reason' => $reason,
            'provider_transaction_id' => $event->provider_transaction_id,
            'payment_id' => $payment?->id,
            'order_id' => $orderId,
        ]);

        return DB::transaction(function () use ($reason, $event, $payment, $orderId, $key) {
            if ($payment?->reconciliation_required_at?->lt($event->received_at)
                && ! $payment->reconciliationReviews()->exists()) {
                $this->backfillPayment($payment);
            }
            $existing = PaymentReconciliationReview::query()->where('detection_key', $key)->first();
            if ($existing) {
                return $existing;
            }

            $parent = $payment === null ? null : PaymentReconciliationReview::query()
                ->where('payment_id', $payment->id)
                ->where('state', PaymentReconciliationReview::STATE_RESOLVED)
                ->latest('resolved_at')->latest('id')->first();
            $now = now();
            try {
                $review = PaymentReconciliationReview::create([
                    'payment_id' => $payment?->id,
                    'order_id' => $orderId,
                    'webhook_event_id' => $event->id,
                    'parent_review_id' => $parent?->id,
                    'reason' => $reason,
                    'state' => PaymentReconciliationReview::STATE_DETECTED,
                    'detection_key' => $key,
                    'detected_at' => $now,
                ]);
            } catch (QueryException $exception) {
                $review = PaymentReconciliationReview::query()->where('detection_key', $key)->first();
                if (! $review) {
                    throw $exception;
                }
            }

            $this->createDetectedAction($review);
            if ($payment !== null) {
                $this->syncLegacyMarker($payment);
            }

            return $review;
        }, 3);
    }

    public function backfillLegacyMarkers(): void
    {
        Payment::query()
            ->whereNotNull('reconciliation_required_at')
            ->orderBy('id')
            ->chunkById(100, function ($payments): void {
                foreach ($payments as $payment) {
                    $this->backfillPayment($payment);
                }
            });
    }

    public static function decisions(): array
    {
        return [
            self::DECISION_REFUND_REQUIRED,
            self::DECISION_REFUND_CONFIRMED_EXTERNALLY,
            self::DECISION_ESCALATED,
            self::DECISION_PROVIDER_VOID_VERIFIED,
            self::DECISION_CONFLICT_OWNERSHIP_VERIFIED,
            self::DECISION_INVALID_EVENT_CONFIRMED,
            self::DECISION_LOCAL_PAYMENT_ABSENCE_CONFIRMED,
            self::DECISION_PROVIDER_TRANSACTION_ABSENCE_CONFIRMED,
        ];
    }

    public function supportsReason(string $reason): bool
    {
        return isset($this->decisionMatrix()[$reason])
            || (str_starts_with($reason, 'WOMPI_EVENT_') && str_ends_with($reason, '_MISMATCH'));
    }

    private function validateDecision(PaymentReconciliationReview $review, array $data): void
    {
        $length = mb_strlen($data['justification']);
        if ($length < 10 || $length > 2000) {
            throw ValidationException::withMessages([
                'justification' => 'La justificación debe contener entre 10 y 2000 caracteres.',
            ]);
        }
        if ($data['evidence_reference'] !== null && mb_strlen($data['evidence_reference']) > 255) {
            throw ValidationException::withMessages([
                'evidence_reference' => 'La referencia de evidencia no puede superar 255 caracteres.',
            ]);
        }
        $allowed = $this->allowedDecisions($review->reason);
        if (! in_array($data['decision'], $allowed, true)) {
            throw ValidationException::withMessages([
                'decision' => 'La decisión no está permitida para esta discrepancia.',
            ]);
        }
        if (in_array($data['decision'], $this->evidenceRequiredDecisions(), true)
            && $data['evidence_reference'] === null) {
            throw ValidationException::withMessages([
                'evidence_reference' => 'La referencia de evidencia es obligatoria para esta decisión.',
            ]);
        }
        if ($review->reason === WompiPaymentReconciliationService::DUPLICATE_APPROVAL) {
            if ($data['canonical_payment_id'] === null || $review->payment_id === null || $review->order_id === null) {
                throw ValidationException::withMessages([
                    'canonical_payment_id' => 'Debes identificar el pago canónico.',
                ]);
            }
            $valid = Payment::query()
                ->whereKey($data['canonical_payment_id'])
                ->where('order_id', $review->order_id)
                ->whereKeyNot($review->payment_id)
                ->exists();
            if (! $valid) {
                throw ValidationException::withMessages([
                    'canonical_payment_id' => 'El pago canónico no pertenece a la misma orden o no es válido.',
                ]);
            }
        } elseif ($data['canonical_payment_id'] !== null) {
            throw ValidationException::withMessages([
                'canonical_payment_id' => 'Esta discrepancia no acepta un pago canónico.',
            ]);
        }
    }

    private function allowedDecisions(string $reason): array
    {
        if (str_starts_with($reason, 'WOMPI_EVENT_') && str_ends_with($reason, '_MISMATCH')) {
            return [self::DECISION_INVALID_EVENT_CONFIRMED, self::DECISION_ESCALATED];
        }

        return $this->decisionMatrix()[$reason] ?? [];
    }

    private function decisionMatrix(): array
    {
        $integrity = [self::DECISION_INVALID_EVENT_CONFIRMED, self::DECISION_ESCALATED];

        return [
            WompiPaymentReconciliationService::LATE_APPROVAL => [self::DECISION_REFUND_REQUIRED, self::DECISION_REFUND_CONFIRMED_EXTERNALLY, self::DECISION_ESCALATED],
            WompiPaymentReconciliationService::DUPLICATE_APPROVAL => [self::DECISION_REFUND_REQUIRED, self::DECISION_REFUND_CONFIRMED_EXTERNALLY, self::DECISION_ESCALATED],
            WompiPaymentReconciliationService::VOID_AFTER_APPROVAL => [self::DECISION_PROVIDER_VOID_VERIFIED, self::DECISION_ESCALATED],
            WompiPaymentReconciliationService::TRANSACTION_CONFLICT => [self::DECISION_CONFLICT_OWNERSHIP_VERIFIED, self::DECISION_ESCALATED],
            'WOMPI_AMOUNT_MISMATCH' => $integrity,
            'WOMPI_CURRENCY_MISMATCH' => $integrity,
            'WOMPI_REFERENCE_MISMATCH' => $integrity,
            'WOMPI_ORDER_MISMATCH' => $integrity,
            'WOMPI_PAYMENT_NOT_FOUND' => [self::DECISION_LOCAL_PAYMENT_ABSENCE_CONFIRMED, self::DECISION_INVALID_EVENT_CONFIRMED, self::DECISION_ESCALATED],
            'WOMPI_TRANSACTION_NOT_FOUND' => [self::DECISION_PROVIDER_TRANSACTION_ABSENCE_CONFIRMED, self::DECISION_ESCALATED],
        ];
    }

    private function evidenceRequiredDecisions(): array
    {
        return [
            self::DECISION_REFUND_CONFIRMED_EXTERNALLY,
            self::DECISION_PROVIDER_VOID_VERIFIED,
            self::DECISION_CONFLICT_OWNERSHIP_VERIFIED,
            self::DECISION_INVALID_EVENT_CONFIRMED,
            self::DECISION_LOCAL_PAYMENT_ABSENCE_CONFIRMED,
            self::DECISION_PROVIDER_TRANSACTION_ABSENCE_CONFIRMED,
        ];
    }

    private function isTerminal(string $decision): bool
    {
        return ! in_array($decision, [self::DECISION_REFUND_REQUIRED, self::DECISION_ESCALATED], true);
    }

    private function replay(
        PaymentReconciliationReview $review,
        string $idempotencyKey,
        string $fingerprint,
    ): ?PaymentReconciliationAction {
        $existing = $review->actions()->where('idempotency_key', $idempotencyKey)->first();
        if (! $existing) {
            return null;
        }
        if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
            throw new PaymentReconciliationException('RECONCILIATION_IDEMPOTENCY_CONFLICT');
        }

        return $existing;
    }

    private function createDetectedAction(PaymentReconciliationReview $review): PaymentReconciliationAction
    {
        $key = 'system:detection:'.$review->detection_key;
        $fingerprint = hash('sha256', json_encode([
            'action' => PaymentReconciliationAction::ACTION_DETECTED,
            'review_id' => $review->id,
            'detection_key' => $review->detection_key,
        ], JSON_THROW_ON_ERROR));

        return $review->actions()->firstOrCreate(
            ['idempotency_key' => $key],
            [
                'action' => PaymentReconciliationAction::ACTION_DETECTED,
                'previous_state' => null,
                'new_state' => PaymentReconciliationReview::STATE_DETECTED,
                'request_fingerprint' => $fingerprint,
                'created_at' => $review->detected_at,
            ],
        );
    }

    private function backfillPayment(Payment $payment): PaymentReconciliationReview
    {
        if (! is_string($payment->reconciliation_reason) || $payment->reconciliation_reason === '') {
            throw new LogicException('A legacy reconciliation marker has no reason.');
        }
        $detectedAt = $payment->reconciliation_required_at;
        $key = $this->detectionKey([
            'source' => 'legacy_marker',
            'payment_id' => $payment->id,
            'reason' => $payment->reconciliation_reason,
            'reconciliation_required_at' => $detectedAt->toISOString(),
        ]);
        $review = PaymentReconciliationReview::firstOrCreate(
            ['detection_key' => $key],
            [
                'payment_id' => $payment->id,
                'order_id' => $payment->order_id,
                'reason' => $payment->reconciliation_reason,
                'state' => PaymentReconciliationReview::STATE_DETECTED,
                'detected_at' => $detectedAt,
            ],
        );
        $this->createDetectedAction($review);

        return $review;
    }

    private function syncLegacyMarker(Payment $payment): void
    {
        $open = PaymentReconciliationReview::query()
            ->where('payment_id', $payment->id)
            ->where('state', '!=', PaymentReconciliationReview::STATE_RESOLVED)
            ->orderBy('detected_at')->orderBy('id')->first();
        $values = [
            'reconciliation_required_at' => $open?->detected_at,
            'reconciliation_reason' => $open?->reason,
        ];
        $currentAt = $payment->getRawOriginal('reconciliation_required_at');
        $nextAt = $open?->getRawOriginal('detected_at');
        if ($currentAt !== $nextAt || $payment->reconciliation_reason !== $values['reconciliation_reason']) {
            $payment->update($values);
        }
    }

    private function detectionKey(array $identity): string
    {
        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function requestFingerprint(
        string $operation,
        PaymentReconciliationReview $review,
        User $actor,
        array $payload,
    ): string {
        return hash('sha256', json_encode([
            'operation' => $operation,
            'review_id' => $review->id,
            'actor_id' => $actor->id,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function authorize(User $actor): void
    {
        if (! $actor->hasPermission('payments.reconcile')) {
            throw new AuthorizationException('No tienes permisos para realizar esta acción.');
        }
    }
}
