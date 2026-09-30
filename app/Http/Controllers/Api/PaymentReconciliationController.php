<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaymentReconciliationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StartPaymentReconciliationReviewRequest;
use App\Http\Requests\StorePaymentReconciliationDecisionRequest;
use App\Models\PaymentReconciliationReview;
use App\Services\PaymentReconciliationResolutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentReconciliationController extends Controller
{
    public function __construct(private readonly PaymentReconciliationResolutionService $reconciliations) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'payments.view');
        $filters = $request->validate([
            'state' => ['nullable', Rule::in(PaymentReconciliationReview::states())],
            'reason' => ['nullable', 'string', 'max:120'],
            'payment_id' => ['nullable', 'integer', 'exists:payments,id'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $reviews = PaymentReconciliationReview::query()
            ->with(['assignee:id,name', 'resolver:id,name'])
            ->when($filters['state'] ?? null, fn ($query, string $state) => $query->where('state', $state))
            ->when($filters['reason'] ?? null, fn ($query, string $reason) => $query->where('reason', $reason))
            ->when($filters['payment_id'] ?? null, fn ($query, int $id) => $query->where('payment_id', $id))
            ->when($filters['order_id'] ?? null, fn ($query, int $id) => $query->where('order_id', $id))
            ->orderByRaw("CASE WHEN state = 'resolved' THEN 1 ELSE 0 END")
            ->orderByDesc('detected_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25))->withQueryString()
            ->through(fn (PaymentReconciliationReview $review) => $this->payload($review));

        return response()->json(['data' => $reviews]);
    }

    public function show(Request $request, PaymentReconciliationReview $review): JsonResponse
    {
        $this->authorizePermission($request, 'payments.view');
        $review->load([
            'assignee:id,name', 'resolver:id,name', 'actions' => fn ($query) => $query->with('actor:id,name')->orderBy('id'),
            'webhookEvent',
        ]);

        return response()->json(['data' => $this->payload($review, true)]);
    }

    public function start(
        StartPaymentReconciliationReviewRequest $request,
        PaymentReconciliationReview $review,
    ): JsonResponse {
        return $this->respond(function () use ($request, $review) {
            return $this->reconciliations->begin(
                $review,
                $request->user(),
                $request->validated('idempotency_key'),
            );
        });
    }

    public function decision(
        StorePaymentReconciliationDecisionRequest $request,
        PaymentReconciliationReview $review,
    ): JsonResponse {
        return $this->respond(function () use ($request, $review) {
            $data = $request->safe()->except('idempotency_key');

            return $this->reconciliations->decide(
                $review,
                $request->user(),
                $request->validated('idempotency_key'),
                $data,
            );
        });
    }

    private function respond(callable $callback): JsonResponse
    {
        try {
            $result = $callback();
        } catch (PaymentReconciliationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode,
            ], $exception->httpStatus);
        }
        $result['review']->load(['assignee:id,name', 'resolver:id,name']);

        return response()->json([
            'data' => $this->payload($result['review']),
            'action' => $this->actionPayload($result['action']),
            'replayed' => $result['replayed'],
        ], $result['replayed'] ? 200 : 201);
    }

    private function payload(PaymentReconciliationReview $review, bool $withHistory = false): array
    {
        $data = [
            'id' => $review->id,
            'payment_id' => $review->payment_id,
            'order_id' => $review->order_id,
            'parent_review_id' => $review->parent_review_id,
            'reason' => $review->reason,
            'state' => $review->state,
            'latest_decision' => $review->latest_decision,
            'assigned_to' => $review->assignee ? ['id' => $review->assignee->id, 'name' => $review->assignee->name] : null,
            'resolved_by' => $review->resolver ? ['id' => $review->resolver->id, 'name' => $review->resolver->name] : null,
            'detected_at' => $review->detected_at,
            'review_started_at' => $review->review_started_at,
            'resolved_at' => $review->resolved_at,
        ];
        if ($withHistory) {
            $event = $review->webhookEvent;
            $data['webhook_event'] = $event ? [
                'id' => $event->id,
                'provider' => $event->provider,
                'event_type' => $event->event_type,
                'provider_transaction_id' => $event->provider_transaction_id,
                'status' => $event->status,
                'received_at' => $event->received_at,
                'processed_at' => $event->processed_at,
                'last_error' => $event->last_error,
                'metadata' => array_intersect_key($event->metadata ?? [], array_flip(['environment', 'provider_status', 'retryable'])),
            ] : null;
            $data['actions'] = $review->actions->map(fn ($action) => $this->actionPayload($action))->values();
        }

        return $data;
    }

    private function actionPayload($action): array
    {
        return [
            'id' => $action->id,
            'actor' => $action->actor ? ['id' => $action->actor->id, 'name' => $action->actor->name] : null,
            'action' => $action->action,
            'previous_state' => $action->previous_state,
            'new_state' => $action->new_state,
            'decision' => $action->decision,
            'justification' => $action->justification,
            'evidence_reference' => $action->evidence_reference,
            'canonical_payment_id' => $action->canonical_payment_id,
            'created_at' => $action->created_at,
        ];
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403, 'No tienes permisos para realizar esta acción.');
    }
}
