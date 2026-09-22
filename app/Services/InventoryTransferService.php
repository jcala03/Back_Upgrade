<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Business\BusinessContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryTransferService
{
    public function __construct(
        private readonly InventoryItemResolver $items,
        private readonly InventoryService $inventory,
    ) {}

    public function paginate(array $filters): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return InventoryTransfer::query()
            ->with($this->relations())
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['source_branch_id'] ?? null, fn (Builder $query, int $branchId) => $query->where('source_branch_id', $branchId))
            ->when($filters['destination_branch_id'] ?? null, fn (Builder $query, int $branchId) => $query->where('destination_branch_id', $branchId))
            ->when($filters['branch_id'] ?? null, fn (Builder $query, int $branchId) => $query->where(
                fn (Builder $branches) => $branches
                    ->where('source_branch_id', $branchId)
                    ->orWhere('destination_branch_id', $branchId)
            ))
            ->when($filters['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('requested_at', '>=', $date))
            ->when($filters['to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('requested_at', '<=', $date))
            ->when($search !== '', fn (Builder $query) => $query->where('number', 'like', "%{$search}%"))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();
    }

    /**
     * @return array{transfer: InventoryTransfer, created: bool}
     */
    public function request(array $data, User $user): array
    {
        $clientRequestKey = $this->nullableTrim($data['client_request_key'] ?? null);

        if ($clientRequestKey) {
            $existing = InventoryTransfer::query()->where('client_request_key', $clientRequestKey)->first();
            if ($existing) {
                $this->assertReplayMatches($existing, $data);

                return ['transfer' => $this->load($existing), 'created' => false];
            }
        }

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $transfer = DB::transaction(function () use ($data, $user, $clientRequestKey) {
                    if ($clientRequestKey) {
                        $existing = InventoryTransfer::query()
                            ->where('client_request_key', $clientRequestKey)
                            ->lockForUpdate()
                            ->first();
                        if ($existing) {
                            $this->assertReplayMatches($existing, $data);

                            return $existing;
                        }
                    }

                    $branchIds = [(int) $data['source_branch_id'], (int) $data['destination_branch_id']];
                    if ($branchIds[0] === $branchIds[1]) {
                        throw ValidationException::withMessages([
                            'destination_branch_id' => 'La sede destino debe ser diferente de la sede origen.',
                        ]);
                    }

                    $branches = Branch::query()
                        ->whereIn('id', $branchIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');
                    $source = $branches->get($branchIds[0]);
                    $destination = $branches->get($branchIds[1]);

                    if (! $source?->is_active) {
                        throw ValidationException::withMessages(['source_branch_id' => 'La sede origen no está activa.']);
                    }
                    if (! $destination?->is_active) {
                        throw ValidationException::withMessages(['destination_branch_id' => 'La sede destino no está activa.']);
                    }

                    $resolvedItems = $this->resolveRequestedItems($data['items']);
                    $transfer = InventoryTransfer::create([
                        'number' => $this->nextNumber(),
                        'source_branch_id' => $source->id,
                        'destination_branch_id' => $destination->id,
                        'status' => InventoryTransfer::STATUS_REQUESTED,
                        'requested_by' => $user->id,
                        'requested_at' => now(),
                        'notes' => $this->nullableTrim($data['notes'] ?? null),
                        'client_request_key' => $clientRequestKey,
                    ]);

                    foreach ($resolvedItems as $item) {
                        $transfer->items()->create($item);
                    }

                    return $transfer;
                }, 3);

                return ['transfer' => $this->load($transfer), 'created' => $transfer->wasRecentlyCreated];
            } catch (QueryException $exception) {
                if (! $this->isDuplicateKey($exception)) {
                    throw $exception;
                }

                if ($clientRequestKey) {
                    $existing = InventoryTransfer::query()->where('client_request_key', $clientRequestKey)->first();
                    if ($existing) {
                        $this->assertReplayMatches($existing, $data);

                        return ['transfer' => $this->load($existing), 'created' => false];
                    }
                }

                if ($attempt === 5) {
                    throw $exception;
                }
            }
        }

        throw ValidationException::withMessages(['number' => 'No fue posible generar el número de transferencia.']);
    }

    public function dispatch(InventoryTransfer $transfer, User $user): InventoryTransfer
    {
        return DB::transaction(function () use ($transfer, $user) {
            $locked = InventoryTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($locked->status === InventoryTransfer::STATUS_IN_TRANSIT) {
                return $this->load($locked);
            }
            $this->assertStatus($locked, InventoryTransfer::STATUS_REQUESTED, 'Solo una transferencia solicitada puede despacharse.');

            $source = Branch::query()->whereKey($locked->source_branch_id)->lockForUpdate()->firstOrFail();
            if (! $source->is_active) {
                throw ValidationException::withMessages(['source_branch_id' => 'La sede origen no está activa.']);
            }

            $items = $locked->items()
                ->with(['inventoryItem.product', 'inventoryItem.productVariant.product', 'product', 'productVariant.product'])
                ->orderBy('inventory_item_id')
                ->lockForUpdate()
                ->get();

            foreach ($items as $item) {
                [$product, $variant] = $this->catalogSelection($item);
                $this->inventory->moveAtBranch(
                    branch: $source,
                    product: $product,
                    type: InventoryMovement::TYPE_TRANSFER_OUT,
                    quantity: $item->quantity,
                    newStock: null,
                    performedBy: $user,
                    reason: "Despacho de transferencia {$locked->number}",
                    variant: $variant,
                    referenceType: InventoryTransfer::class,
                    referenceId: $locked->id,
                    transferItem: $item,
                );
            }

            $locked->update([
                'status' => InventoryTransfer::STATUS_IN_TRANSIT,
                'dispatched_by' => $user->id,
                'dispatched_at' => now(),
            ]);

            return $this->load($locked);
        }, 3);
    }

    public function receive(InventoryTransfer $transfer, User $user): InventoryTransfer
    {
        return DB::transaction(function () use ($transfer, $user) {
            $locked = InventoryTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($locked->status === InventoryTransfer::STATUS_RECEIVED) {
                return $this->load($locked);
            }
            $this->assertStatus($locked, InventoryTransfer::STATUS_IN_TRANSIT, 'Solo una transferencia en tránsito puede recibirse.');

            $destination = Branch::query()->whereKey($locked->destination_branch_id)->lockForUpdate()->firstOrFail();
            if (! $destination->is_active) {
                throw ValidationException::withMessages(['destination_branch_id' => 'La sede destino no está activa.']);
            }

            $items = $locked->items()
                ->with(['inventoryItem.product', 'inventoryItem.productVariant.product', 'product', 'productVariant.product'])
                ->orderBy('inventory_item_id')
                ->lockForUpdate()
                ->get();

            foreach ($items as $item) {
                [$product, $variant] = $this->catalogSelection($item);
                $this->inventory->moveAtBranch(
                    branch: $destination,
                    product: $product,
                    type: InventoryMovement::TYPE_TRANSFER_IN,
                    quantity: $item->quantity,
                    newStock: null,
                    performedBy: $user,
                    reason: "Recepción de transferencia {$locked->number}",
                    variant: $variant,
                    referenceType: InventoryTransfer::class,
                    referenceId: $locked->id,
                    transferItem: $item,
                );
            }

            $locked->update([
                'status' => InventoryTransfer::STATUS_RECEIVED,
                'received_by' => $user->id,
                'received_at' => now(),
            ]);

            return $this->load($locked);
        }, 3);
    }

    public function cancel(InventoryTransfer $transfer, string $reason, User $user): InventoryTransfer
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($transfer, $reason, $user) {
            $locked = InventoryTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($locked->status === InventoryTransfer::STATUS_CANCELLED) {
                if ($locked->cancellation_reason !== $reason) {
                    throw ValidationException::withMessages([
                        'reason' => 'La transferencia ya fue cancelada con una razón diferente.',
                    ]);
                }

                return $this->load($locked);
            }
            $this->assertStatus($locked, InventoryTransfer::STATUS_REQUESTED, 'Solo una transferencia solicitada puede cancelarse.');

            $locked->update([
                'status' => InventoryTransfer::STATUS_CANCELLED,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            return $this->load($locked);
        }, 3);
    }

    public function load(InventoryTransfer $transfer): InventoryTransfer
    {
        return $transfer->fresh($this->relations()) ?? $transfer->load($this->relations());
    }

    private function resolveRequestedItems(array $items): array
    {
        $resolved = [];

        foreach ($items as $index => $requested) {
            $product = Product::query()
                ->whereKey($requested['product_id'])
                ->where('is_active', true)
                ->first();
            if (! $product) {
                throw ValidationException::withMessages(["items.{$index}.product_id" => 'El producto no está activo o no existe.']);
            }

            $variant = null;
            if (! empty($requested['product_variant_id'])) {
                $variant = ProductVariant::query()
                    ->whereKey($requested['product_variant_id'])
                    ->where('product_id', $product->id)
                    ->where('is_active', true)
                    ->first();
                if (! $variant) {
                    throw ValidationException::withMessages(["items.{$index}.product_variant_id" => 'La variante no pertenece al producto o no está activa.']);
                }
            }

            $inventoryItem = $this->items->resolve($product, $variant);
            if (isset($resolved[$inventoryItem->id])) {
                throw ValidationException::withMessages(["items.{$index}" => 'El mismo artículo no puede repetirse en la transferencia.']);
            }

            $resolved[$inventoryItem->id] = [
                'inventory_item_id' => $inventoryItem->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'product_name_snapshot' => $product->name,
                'variant_name_snapshot' => $variant?->display_name,
                'sku_snapshot' => $variant?->sku ?? $product->sku,
                'quantity' => (int) $requested['quantity'],
            ];
        }

        ksort($resolved);

        return array_values($resolved);
    }

    private function nextNumber(): string
    {
        $prefix = 'TRF-'.BusinessContext::now()->format('Ymd').'-';
        $last = InventoryTransfer::query()
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->lockForUpdate()
            ->value('number');
        $sequence = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    private function assertReplayMatches(InventoryTransfer $transfer, array $data): void
    {
        $expected = collect($data['items'] ?? [])->map(fn (array $item) => [
            (int) ($item['product_id'] ?? 0),
            isset($item['product_variant_id']) ? (int) $item['product_variant_id'] : null,
            (int) ($item['quantity'] ?? 0),
        ])->sort()->values()->all();
        $actual = $transfer->items()->get()->map(fn (InventoryTransferItem $item) => [
            (int) $item->product_id,
            $item->product_variant_id ? (int) $item->product_variant_id : null,
            (int) $item->quantity,
        ])->sort()->values()->all();

        if ((int) $transfer->source_branch_id !== (int) ($data['source_branch_id'] ?? 0)
            || (int) $transfer->destination_branch_id !== (int) ($data['destination_branch_id'] ?? 0)
            || $expected !== $actual) {
            throw ValidationException::withMessages([
                'client_request_key' => 'La clave de solicitud ya fue utilizada con una transferencia diferente.',
            ]);
        }
    }

    private function catalogSelection(InventoryTransferItem $item): array
    {
        $variant = $item->productVariant ?? $item->inventoryItem?->productVariant;
        $product = $item->product ?? $item->inventoryItem?->product ?? $variant?->product;

        if (! $product) {
            throw ValidationException::withMessages(['items' => 'Un artículo de la transferencia ya no está disponible.']);
        }

        return [$product, $variant];
    }

    private function assertStatus(InventoryTransfer $transfer, string $expected, string $message): void
    {
        if ($transfer->status !== $expected) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    private function relations(): array
    {
        return [
            'sourceBranch',
            'destinationBranch',
            'items.inventoryItem.product',
            'items.inventoryItem.productVariant.product',
            'items.product',
            'items.productVariant.product',
            'requester',
            'dispatcher',
            'receiver',
            'canceller',
        ];
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
