<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInventoryMovementRequest;
use App\Http\Requests\UpdateInventoryMinimumRequest;
use App\Http\Resources\AdminProductResource;
use App\Http\Resources\AdminProductVariantResource;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function overview(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'inventory.view');
        $products = Product::query()
            ->with($this->productRelations())
            ->latest()
            ->get();
        $productPayloads = $products
            ->map(fn (Product $product): array => $this->inventoryProductPayload($product, $request));

        $stats = [
            'total_products' => $productPayloads->count(),
            'available_products' => $productPayloads
                ->where('stock_status', 'available')
                ->count(),
            'low_stock_products' => $productPayloads
                ->where('stock_status', 'low_stock')
                ->count(),
            'out_of_stock_products' => $productPayloads
                ->where('stock_status', 'out_of_stock')
                ->count(),
        ];

        return response()->json([
            'data' => [
                'stats' => $stats,
                'products' => $productPayloads,
            ],
        ]);
    }

    public function stocks(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'inventory.view');
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'search' => ['nullable', 'string', 'max:160'],
            'low_stock' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $stocks = InventoryStock::query()
            ->with($this->stockRelations())
            ->when($filters['branch_id'] ?? null, fn (Builder $query, int $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['product_id'] ?? null, function (Builder $query, int $productId) {
                $query->whereHas('inventoryItem', fn (Builder $item) => $item
                    ->where('product_id', $productId)
                    ->orWhereHas('productVariant', fn (Builder $variant) => $variant->where('product_id', $productId)));
            })
            ->when($filters['product_variant_id'] ?? null, fn (Builder $query, int $variantId) => $query
                ->whereHas('inventoryItem', fn (Builder $item) => $item->where('product_variant_id', $variantId)))
            ->when($search !== '', function (Builder $query) use ($search) {
                $like = "%{$search}%";
                $query->whereHas('inventoryItem', fn (Builder $item) => $item
                    ->whereHas('product', fn (Builder $product) => $product
                        ->where('name', 'like', $like)
                        ->orWhere('sku', 'like', $like))
                    ->orWhereHas('productVariant', fn (Builder $variant) => $variant
                        ->where('name', 'like', $like)
                        ->orWhere('sku', 'like', $like)
                        ->orWhereHas('product', fn (Builder $product) => $product
                            ->where('name', 'like', $like)
                            ->orWhere('sku', 'like', $like))));
            })
            ->when(array_key_exists('low_stock', $filters), function (Builder $query) use ($filters) {
                if ($filters['low_stock']) {
                    $query->where('quantity', '>', 0)->whereColumn('quantity', '<=', 'minimum_quantity');

                    return;
                }

                $query->where(fn (Builder $stock) => $stock
                    ->where('quantity', '<=', 0)
                    ->orWhereColumn('quantity', '>', 'minimum_quantity'));
            })
            ->orderBy('branch_id')
            ->orderBy('inventory_item_id')
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        $stocks->through(fn (InventoryStock $stock) => $this->stockPayload($stock));

        return response()->json(['data' => $stocks]);
    }

    public function movements(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'inventory.view');
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'inventory_item_id' => ['nullable', 'integer', 'exists:inventory_items,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'type' => ['nullable', Rule::in(InventoryMovement::types())],
        ]);
        $movements = InventoryMovement::query()
            ->with([
                'branch:id,code,name,city,is_active',
                'inventoryItem.product',
                'inventoryItem.productVariant.product',
                'product',
                'productVariant.vehicleMultimediaSystem.vehicleBrand',
                'creator',
            ])
            ->when($filters['branch_id'] ?? null, fn (Builder $query, int $id) => $query->where('branch_id', $id))
            ->when($filters['inventory_item_id'] ?? null, fn (Builder $query, int $id) => $query->where('inventory_item_id', $id))
            ->when($filters['product_id'] ?? null, fn (Builder $query, int $id) => $query->where('product_id', $id))
            ->when($filters['product_variant_id'] ?? null, fn (Builder $query, int $id) => $query->where('product_variant_id', $id))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->latest()
            ->orderByDesc('id')
            ->limit(150)
            ->get();

        return response()->json([
            'data' => $movements,
        ]);
    }

    public function storeMovement(StoreInventoryMovementRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $branch = Branch::findOrFail($validated['branch_id']);
        $product = Product::findOrFail($validated['product_id']);
        $variant = ! empty($validated['product_variant_id'])
            ? ProductVariant::findOrFail($validated['product_variant_id'])
            : null;

        if (! $variant && $product->variants()->exists()) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'Este producto maneja variantes. Selecciona la variante que quieres ajustar.',
            ]);
        }

        $movement = $this->inventory->moveAtBranch(
            branch: $branch,
            product: $product,
            type: $validated['type'],
            quantity: $validated['quantity'] ?? null,
            newStock: $validated['new_stock'] ?? null,
            performedBy: $request->user(),
            reason: $this->nullableTrim($validated['reason'] ?? null),
            notes: $this->nullableTrim($validated['notes'] ?? null),
            variant: $variant,
        );

        return response()->json([
            'message' => 'Movimiento registrado correctamente.',
            'data' => $movement->fresh([
                'branch:id,code,name,city,is_active',
                'inventoryItem.product',
                'inventoryItem.productVariant.product',
                'product',
                'productVariant.vehicleMultimediaSystem.vehicleBrand',
                'creator',
            ]),
        ], 201);
    }

    public function updateMinimum(UpdateInventoryMinimumRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $branch = Branch::findOrFail($validated['branch_id']);
        $product = Product::findOrFail($validated['product_id']);
        $variant = ! empty($validated['product_variant_id'])
            ? ProductVariant::findOrFail($validated['product_variant_id'])
            : null;

        if (! $variant && $product->variants()->exists()) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'Este producto maneja variantes. Selecciona la variante cuyo mínimo quieres actualizar.',
            ]);
        }

        $stock = $this->inventory->updateMinimumStock(
            branch: $branch,
            product: $product,
            minimumQuantity: $validated['minimum_quantity'],
            performedBy: $request->user(),
            variant: $variant,
        );

        return response()->json([
            'message' => 'Stock mínimo actualizado correctamente.',
            'data' => $this->stockPayload($stock->load($this->stockRelations())),
        ]);
    }

    private function productRelations(): array
    {
        return [
            'productCategory.fields',
            'productBrand',
            'specValues.field',
            'vehicleCompatibilities.vehicleBrand',
            'vehicleCompatibilities.vehicleModel',
            'vehicleCompatibilities.vehicleVersion',
            'variants.product',
            'inventoryItem.stocks',
            'variants.inventoryItem.stocks',
            'variants.vehicleMultimediaSystem.vehicleBrand',
            'variants.specValues.field',
            'variants.vehicleCompatibilities.vehicleBrand',
            'variants.vehicleCompatibilities.vehicleModel',
            'variants.vehicleCompatibilities.vehicleVersion',
            'variants.vehicleCompatibilities.vehicleMultimediaSystem',
        ];
    }

    private function inventoryProductPayload(Product $product, Request $request): array
    {
        $payload = (new AdminProductResource($product))->resolve($request);
        unset($payload['commission_enabled'], $payload['commission_amount']);

        if ($product->variants->isEmpty()) {
            $summary = $this->inventorySummary($product->inventoryItem?->stocks ?? collect());
        } else {
            $activeVariants = $product->variants->where('is_active', true);
            $variantSummaries = $activeVariants
                ->map(fn (ProductVariant $variant): array => $this->inventorySummary(
                    $variant->inventoryItem?->stocks ?? collect(),
                ));
            $total = $variantSummaries->sum('stock_total');
            $status = $total <= 0
                ? 'out_of_stock'
                : ($variantSummaries->contains(fn (array $summary): bool => $summary['stock_status'] !== 'available')
                    ? 'low_stock'
                    : 'available');
            $summary = [
                'stock_total' => $total,
                'stock_status' => $status,
                'is_low_stock' => $status === 'low_stock',
            ];
        }

        $payload['stock'] = $summary['stock_total'];
        $payload['stock_total'] = $summary['stock_total'];
        $payload['stock_status'] = $summary['stock_status'];
        $payload['is_low_stock'] = $summary['is_low_stock'];
        $payload['total_variant_stock'] = $product->variants->isEmpty() ? null : $summary['stock_total'];
        $payload['variants'] = $product->variants
            ->map(function (ProductVariant $variant) use ($request): array {
                $variantPayload = (new AdminProductVariantResource($variant))->resolve($request);
                $summary = $this->inventorySummary($variant->inventoryItem?->stocks ?? collect());
                $variantPayload['stock'] = $summary['stock_total'];
                $variantPayload['stock_total'] = $summary['stock_total'];
                $variantPayload['stock_status'] = $summary['stock_status'];
                $variantPayload['is_low_stock'] = $summary['is_low_stock'];

                return $variantPayload;
            })
            ->values()
            ->all();

        return $payload;
    }

    private function inventorySummary(iterable $stocks): array
    {
        $positions = collect($stocks);
        $total = (int) $positions->sum('quantity');
        $hasCriticalPosition = $positions->contains(
            fn (InventoryStock $stock): bool => $stock->quantity <= $stock->minimum_quantity,
        );
        $status = $total <= 0
            ? 'out_of_stock'
            : ($hasCriticalPosition ? 'low_stock' : 'available');

        return [
            'stock_total' => $total,
            'stock_status' => $status,
            'is_low_stock' => $status === 'low_stock',
        ];
    }

    private function stockRelations(): array
    {
        return [
            'branch:id,code,name,city,is_active',
            'inventoryItem.product:id,name,sku,is_active',
            'inventoryItem.productVariant:id,product_id,name,sku,is_active',
            'inventoryItem.productVariant.product:id,name,sku,is_active',
        ];
    }

    private function stockPayload(InventoryStock $stock): array
    {
        $item = $stock->inventoryItem;
        $variant = $item->productVariant;
        $product = $item->product ?? $variant?->product;
        $status = $stock->quantity <= 0
            ? 'out'
            : ($stock->quantity <= $stock->minimum_quantity ? 'low' : 'normal');

        return [
            'id' => $stock->id,
            'branch_id' => $stock->branch_id,
            'inventory_item_id' => $stock->inventory_item_id,
            'product_id' => $product?->id,
            'product_variant_id' => $variant?->id,
            'type' => $variant ? 'variant' : 'product',
            'branch' => $stock->branch?->only(['id', 'code', 'name', 'city', 'is_active']),
            'product' => $product?->only(['id', 'name', 'sku', 'is_active']),
            'product_variant' => $variant?->only(['id', 'product_id', 'name', 'sku', 'is_active']),
            'quantity' => (int) $stock->quantity,
            'minimum_quantity' => (int) $stock->minimum_quantity,
            'is_low_stock' => $status === 'low',
            'status' => $status,
            'created_at' => $stock->created_at,
            'updated_at' => $stock->updated_at,
        ];
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403, 'No tienes permisos para realizar esta acción.');
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
