<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelInventoryTransferRequest;
use App\Http\Requests\StoreInventoryTransferRequest;
use App\Models\InventoryTransfer;
use App\Services\InventoryTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryTransferController extends Controller
{
    public function __construct(private readonly InventoryTransferService $transfers) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'inventory_transfers.view');
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(InventoryTransfer::statuses())],
            'source_branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'destination_branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:160'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $transfers = $this->transfers->paginate($filters);
        $transfers->through(fn (InventoryTransfer $transfer) => $this->payload($transfer));

        return response()->json(['data' => $transfers]);
    }

    public function store(StoreInventoryTransferRequest $request): JsonResponse
    {
        $result = $this->transfers->request($request->validated(), $request->user());

        return response()->json([
            'message' => $result['created']
                ? 'Transferencia solicitada correctamente.'
                : 'La transferencia ya había sido solicitada.',
            'data' => $this->payload($result['transfer']),
        ], $result['created'] ? 201 : 200);
    }

    public function show(Request $request, InventoryTransfer $transfer): JsonResponse
    {
        $this->authorizePermission($request, 'inventory_transfers.view');

        return response()->json(['data' => $this->payload($this->transfers->load($transfer))]);
    }

    public function dispatch(Request $request, InventoryTransfer $transfer): JsonResponse
    {
        $this->authorizePermission($request, 'inventory_transfers.dispatch');
        $updated = $this->transfers->dispatch($transfer, $request->user());

        return response()->json([
            'message' => 'Transferencia despachada correctamente.',
            'data' => $this->payload($this->transfers->load($updated)),
        ]);
    }

    public function receive(Request $request, InventoryTransfer $transfer): JsonResponse
    {
        $this->authorizePermission($request, 'inventory_transfers.receive');
        $updated = $this->transfers->receive($transfer, $request->user());

        return response()->json([
            'message' => 'Transferencia recibida correctamente.',
            'data' => $this->payload($this->transfers->load($updated)),
        ]);
    }

    public function cancel(CancelInventoryTransferRequest $request, InventoryTransfer $transfer): JsonResponse
    {
        $updated = $this->transfers->cancel(
            $transfer,
            $request->validated('reason'),
            $request->user(),
        );

        return response()->json([
            'message' => 'Transferencia cancelada correctamente.',
            'data' => $this->payload($this->transfers->load($updated)),
        ]);
    }

    private function payload(InventoryTransfer $transfer): array
    {
        return [
            ...$transfer->only([
                'id', 'number', 'status', 'source_branch_id', 'destination_branch_id',
                'requested_by', 'dispatched_by', 'received_by', 'cancelled_by',
                'requested_at', 'dispatched_at', 'received_at', 'cancelled_at',
                'cancellation_reason', 'notes', 'created_at', 'updated_at',
            ]),
            'source_branch' => $transfer->sourceBranch?->only(['id', 'code', 'name', 'city', 'is_active']),
            'destination_branch' => $transfer->destinationBranch?->only(['id', 'code', 'name', 'city', 'is_active']),
            'items' => $transfer->items->map(function ($item): array {
                $variant = $item->productVariant ?? $item->inventoryItem?->productVariant;
                $product = $item->product ?? $item->inventoryItem?->product ?? $variant?->product;

                return [
                    ...$item->only([
                        'id', 'inventory_item_id', 'product_id', 'product_variant_id',
                        'product_name_snapshot', 'variant_name_snapshot', 'sku_snapshot', 'quantity',
                    ]),
                    'product' => $product?->only(['id', 'name', 'sku', 'is_active']),
                    'product_variant' => $variant?->only(['id', 'product_id', 'name', 'sku', 'is_active']),
                ];
            })->values()->all(),
            'requester' => $transfer->requester?->only(['id', 'name']),
            'dispatcher' => $transfer->dispatcher?->only(['id', 'name']),
            'receiver' => $transfer->receiver?->only(['id', 'name']),
            'canceller' => $transfer->canceller?->only(['id', 'name']),
        ];
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403, 'No tienes permisos para realizar esta acción.');
    }
}
