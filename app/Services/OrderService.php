<?php

namespace App\Services;

use App\Exceptions\EcommerceShippingException;
use App\Models\Branch;
use App\Models\CrmNotification;
use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Models\Employee;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Quotation;
use App\Models\User;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use App\Models\VehicleVersion;
use App\Support\Business\BusinessContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private readonly CommercialItemResolver $items,
        private readonly InventoryService $inventory,
        private readonly InventoryItemResolver $inventoryItems,
        private readonly PaymentService $payments,
        private readonly CommissionService $commissions,
        private readonly CrmNotificationService $notifications,
        private readonly CommercialEmployeeContext $commercialContext,
        private readonly OrderTotalService $totals,
        private readonly EcommerceFulfillmentBranchResolver $fulfillment,
    ) {}

    public function createPublic(array $data, ?string $publicTokenHash = null, ?string $idempotencyKey = null, ?string $idempotencyFingerprint = null): Order
    {
        $order = $this->create($data, Order::ORIGIN_ECOMMERCE, null, Order::STATUS_PENDING, false, null, null, true, $publicTokenHash, $idempotencyKey, $idempotencyFingerprint);
        try {
            $this->notifications->distribute('orders.view', [
                'type' => CrmNotification::TYPE_ORDER_ECOMMERCE_PENDING,
                'severity' => CrmNotification::SEVERITY_INFO,
                'title' => 'Nueva orden ecommerce',
                'message' => "La orden {$order->order_number} está pendiente de confirmación.",
                'data' => ['order_id' => $order->id, 'order_number' => $order->order_number],
                'reference_type' => 'order',
                'reference_id' => $order->id,
                'dedupe_key' => "order_ecommerce_pending:{$order->id}",
            ]);
        } catch (\Throwable $exception) {
            Log::warning('No se pudo crear la notificación CRM de orden ecommerce.', ['order_id' => $order->id, 'error' => $exception->getMessage()]);
        }

        return $order;
    }

    public function createAdmin(array $data, User $user): Order
    {
        return DB::transaction(function () use ($data, $user) {
            [$branch, $seller] = $this->adminAssignment(
                (int) $data['branch_id'],
                isset($data['sales_employee_id']) ? (int) $data['sales_employee_id'] : null,
            );

            return $this->create(
                $data,
                Order::ORIGIN_CRM,
                $user,
                $data['status'] ?? Order::STATUS_CONFIRMED,
                true,
                $branch,
                $seller,
            );
        });
    }

    public function createOwn(array $data, User $user): Order
    {
        return DB::transaction(function () use ($data, $user) {
            $context = $this->commercialContext->resolve($user, true);

            return $this->create(
                $data,
                Order::ORIGIN_CRM,
                $user,
                Order::STATUS_PENDING,
                true,
                $context['branch'],
                $context['employee'],
            );
        });
    }

    private function create(
        array $data,
        string $origin,
        ?User $user,
        string $initialStatus,
        bool $admin,
        ?Branch $branch,
        ?Employee $seller,
        bool $publicResponse = false,
        ?string $publicTokenHash = null,
        ?string $idempotencyKey = null,
        ?string $idempotencyFingerprint = null,
    ): Order {
        return DB::transaction(function () use ($data, $origin, $user, $initialStatus, $admin, $branch, $seller, $publicResponse, $publicTokenHash, $idempotencyKey, $idempotencyFingerprint) {
            $resolvedItems = $this->items->resolve($data['items'], $admin, false);
            $subtotal = array_sum(array_column($resolvedItems, 'subtotal'));
            $discount = array_sum(array_column($resolvedItems, 'discount_amount'));
            $customer = isset($data['customer_id']) ? Customer::find($data['customer_id']) : null;
            $customerVehicle = isset($data['customer_vehicle_id']) ? CustomerVehicle::with(['customer', 'vehicleBrand', 'vehicleModel', 'vehicleVersion'])->find($data['customer_vehicle_id']) : null;
            if ($customerVehicle && $customer && (int) $customerVehicle->customer_id !== $customer->id) {
                throw ValidationException::withMessages(['customer_vehicle_id' => 'El vehículo no pertenece al cliente indicado.']);
            }
            $customer ??= $customerVehicle?->customer;
            $customerSnapshot = $this->resolveCustomer($data, $customer);
            $vehicle = $this->resolveVehicle($data, $customerVehicle);

            $order = Order::create([
                'order_number' => $this->generateOrderNumber(),
                'public_token_hash' => $publicTokenHash,
                'checkout_idempotency_key' => $idempotencyKey,
                'checkout_idempotency_fingerprint' => $idempotencyFingerprint,
                'branch_id' => $branch?->id,
                'sales_employee_id' => $seller?->id,
                'origin' => $origin,
                'user_id' => $data['user_id'] ?? null,
                'customer_id' => $customer?->id,
                'customer_vehicle_id' => $customerVehicle?->id,
                'created_by' => $user?->id,
                ...$customerSnapshot,
                'subtotal' => $subtotal,
                'discount_total' => $discount,
                'total' => $this->totals->calculate($subtotal, $discount, 0),
                'status' => Order::STATUS_PENDING,
                'payment_status' => Order::PAYMENT_UNPAID,
                ...$vehicle,
            ]);

            foreach ($resolvedItems as $item) {
                $order->items()->create($item);
            }
            $order->statusHistory()->create(['from_status' => null, 'to_status' => Order::STATUS_PENDING, 'changed_by' => $user?->id]);

            if ($initialStatus === Order::STATUS_CONFIRMED) {
                $this->transitionLocked($order, Order::STATUS_CONFIRMED, $user);
            }
            if (! empty($data['payment'])) {
                $this->payments->register($order, $data['payment'], $user);
            }

            return $publicResponse
                ? $this->loadPublicOrder($order->fresh())
                : $this->loadOrder($order->fresh());
        });
    }

    public function createFromQuotation(Quotation $quotation, User $user): Order
    {
        return DB::transaction(function () use ($quotation, $user) {
            if ($quotation->branch_id === null) {
                throw ValidationException::withMessages([
                    'branch_id' => 'La cotización debe tener una sede antes de convertirse.',
                ]);
            }
            $this->commercialContext->activeBranch((int) $quotation->branch_id, true);
            $resolvedItems = $this->items->resolveFrozen($quotation->items);
            $order = Order::create([
                'quotation_id' => $quotation->id,
                'branch_id' => $quotation->branch_id,
                'sales_employee_id' => $quotation->sales_employee_id,
                'order_number' => $this->generateOrderNumber(),
                'origin' => Order::ORIGIN_CRM,
                'customer_id' => $quotation->customer_id,
                'customer_vehicle_id' => $quotation->customer_vehicle_id,
                'created_by' => $user->id,
                'customer_name' => $quotation->customer_name,
                'customer_email' => $quotation->customer_email,
                'customer_phone' => $quotation->customer_phone,
                'customer_document' => $quotation->customer_document,
                'customer_city' => $quotation->customer_city,
                'customer_address' => $quotation->customer_address,
                'customer_notes' => $quotation->customer_notes,
                'subtotal' => array_sum(array_column($resolvedItems, 'subtotal')),
                'discount_total' => array_sum(array_column($resolvedItems, 'discount_amount')),
                'total' => $this->totals->calculate(
                    array_sum(array_column($resolvedItems, 'subtotal')),
                    array_sum(array_column($resolvedItems, 'discount_amount')),
                    0,
                ),
                'status' => Order::STATUS_PENDING,
                'payment_status' => Order::PAYMENT_UNPAID,
                'vehicle_brand_id' => $quotation->vehicle_brand_id,
                'vehicle_model_id' => $quotation->vehicle_model_id,
                'vehicle_version_id' => $quotation->vehicle_version_id,
                'vehicle_year' => $quotation->vehicle_year,
                'vehicle_plate' => $quotation->vehicle_plate,
                'vehicle_vin' => $quotation->vehicle_vin,
                'vehicle_color' => $quotation->vehicle_color,
                'vehicle_notes' => $quotation->vehicle_notes,
                'vehicle_brand_name' => $quotation->vehicle_brand_name,
                'vehicle_model_name' => $quotation->vehicle_model_name,
                'vehicle_version_name' => $quotation->vehicle_version_name,
            ]);
            foreach ($resolvedItems as $item) {
                $order->items()->create($item);
            }
            $order->statusHistory()->create(['from_status' => null, 'to_status' => Order::STATUS_PENDING, 'changed_by' => $user->id]);
            $this->transitionLocked($order, Order::STATUS_CONFIRMED, $user);

            return $this->loadOrder($order->fresh());
        });
    }

    public function transition(
        Order $order,
        string $status,
        ?User $user,
        ?string $reason = null,
        array $assignment = [],
    ): Order {
        return DB::transaction(function () use ($order, $status, $user, $reason, $assignment) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($status === Order::STATUS_CONFIRMED) {
                if ($locked->status === Order::STATUS_CONFIRMED) {
                    $this->assertFrozenAssignment($locked, $assignment);
                } elseif ($locked->status === Order::STATUS_PENDING) {
                    $this->prepareConfirmationAssignment($locked, $assignment);
                }
            }
            $this->transitionLocked($locked, $status, $user, $reason);

            return $this->loadOrder($locked->fresh());
        });
    }

    public function transitionOwn(Order $order, string $status, User $user): Order
    {
        return DB::transaction(function () use ($order, $status, $user) {
            $context = $this->commercialContext->resolve($user);
            $locked = Order::query()
                ->whereKey($order->id)
                ->where('sales_employee_id', $context['employee']->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedContext = $this->commercialContext->resolveForBranch(
                $user,
                (int) $locked->branch_id,
                true,
            );
            if ($lockedContext['employee']->id !== $context['employee']->id) {
                throw (new ModelNotFoundException)->setModel(Order::class, [$order->id]);
            }
            $this->transitionLocked($locked, $status, $user);

            return $this->loadOrder($locked->fresh());
        });
    }

    private function transitionLocked(Order $order, string $status, ?User $user, ?string $reason = null): void
    {
        if ($order->status === $status) {
            return;
        }
        $allowed = [
            Order::STATUS_PENDING => [Order::STATUS_CONFIRMED, Order::STATUS_CANCELLED],
            Order::STATUS_CONFIRMED => [Order::STATUS_COMPLETED, Order::STATUS_CANCELLED],
            Order::STATUS_COMPLETED => [], Order::STATUS_CANCELLED => [],
        ];
        if (! in_array($status, $allowed[$order->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'La transición de estado solicitada no es válida.']);
        }

        $from = $order->status;
        if ($status === Order::STATUS_CONFIRMED) {
            if ($order->branch_id === null) {
                throw ValidationException::withMessages([
                    'branch_id' => 'La orden debe tener una sede antes de confirmarse.',
                ]);
            }
            $this->commercialContext->activeBranch((int) $order->branch_id, true);
            $this->commitStock($order, $user);
        }
        if ($status === Order::STATUS_CANCELLED) {
            $this->reverseStock($order, $user);
        }

        $eventAt = now();
        $values = ['status' => $status];
        if ($status === Order::STATUS_CONFIRMED) {
            $values['confirmed_at'] = $eventAt;
        }
        if ($status === Order::STATUS_COMPLETED) {
            $values['completed_at'] = $eventAt;
        }
        if ($status === Order::STATUS_CANCELLED) {
            $values += ['cancelled_at' => $eventAt, 'cancelled_by' => $user?->id, 'cancel_reason' => $reason];
        }
        $order->update($values);

        if ($status === Order::STATUS_CONFIRMED) {
            $this->commissions->createPendingForOrder($order);
        }
        if ($status === Order::STATUS_COMPLETED) {
            $this->commissions->earnForOrder($order, $eventAt);
        }
        if ($status === Order::STATUS_CANCELLED) {
            $this->commissions->voidPendingForOrder($order, $user, voidedAt: $eventAt);
        }

        $order->statusHistory()->create(['from_status' => $from, 'to_status' => $status, 'changed_by' => $user?->id, 'reason' => $reason]);
    }

    private function commitStock(Order $order, ?User $user): void
    {
        if ($order->stock_committed_at) {
            return;
        }

        $branch = Branch::query()->find($order->branch_id);
        if (! $branch) {
            throw ValidationException::withMessages([
                'branch_id' => 'La orden debe tener una sede válida antes de confirmarse.',
            ]);
        }

        try {
            $branch = $this->fulfillment->revalidateUnderLock($order, $branch);
        } catch (EcommerceShippingException $exception) {
            throw ValidationException::withMessages([
                'quantity' => [$exception->getMessage(), $exception->errorCode],
            ]);
        }

        $items = $order->items()
            ->where('item_type', OrderItem::ITEM_TYPE_PRODUCT)
            ->with(['product', 'productVariant'])
            ->get()
            ->map(function (OrderItem $item): array {
                if (! $item->product) {
                    throw ValidationException::withMessages(['items' => 'Un producto de la orden ya no existe y no puede confirmarse.']);
                }

                $inventoryItem = $this->inventoryItems->resolveFrozen($item->product, $item->productVariant);

                return [
                    'order_item' => $item,
                    'inventory_item' => $inventoryItem,
                    'inventory_item_id' => $inventoryItem->id,
                ];
            })
            ->sortBy('inventory_item_id');

        foreach ($items as $resolved) {
            /** @var OrderItem $item */
            $item = $resolved['order_item'];
            if (! $item->product_id) {
                throw ValidationException::withMessages(['items' => 'Un producto de la orden ya no existe y no puede confirmarse.']);
            }
            $this->inventory->moveAtBranch(
                branch: $branch,
                product: $item->product,
                type: InventoryMovement::TYPE_SALE,
                quantity: $item->quantity,
                newStock: null,
                performedBy: $user,
                reason: 'Venta confirmada',
                variant: $item->productVariant,
                referenceType: Order::class,
                referenceId: $order->id,
                inventoryItem: $resolved['inventory_item'],
            );
        }
        $order->update(['stock_committed_at' => now()]);
    }

    private function reverseStock(Order $order, ?User $user): void
    {
        if (! $order->stock_committed_at || $order->stock_reverted_at) {
            return;
        }

        $branch = Branch::query()->find($order->branch_id);
        if (! $branch) {
            throw ValidationException::withMessages([
                'branch_id' => 'No existe la sede histórica de la orden.',
            ]);
        }

        $saleMovements = InventoryMovement::query()
            ->where('branch_id', $branch->id)
            ->where('type', InventoryMovement::TYPE_SALE)
            ->where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->with(['inventoryItem', 'product', 'productVariant'])
            ->orderBy('inventory_item_id')
            ->get();

        if (
            $saleMovements->isEmpty()
            && $order->items()->where('item_type', OrderItem::ITEM_TYPE_PRODUCT)->exists()
        ) {
            throw ValidationException::withMessages([
                'inventory' => 'No existen movimientos de venta para revertir esta orden.',
            ]);
        }

        foreach ($saleMovements as $saleMovement) {
            if (! $saleMovement->inventoryItem || ! $saleMovement->product) {
                throw ValidationException::withMessages([
                    'inventory' => 'La trazabilidad inventariable de la venta está incompleta.',
                ]);
            }
            $this->inventory->moveAtBranch(
                branch: $branch,
                product: $saleMovement->product,
                type: InventoryMovement::TYPE_SALE_REVERSAL,
                quantity: abs((int) $saleMovement->quantity_delta),
                newStock: null,
                performedBy: $user,
                reason: 'Venta cancelada',
                variant: $saleMovement->productVariant,
                referenceType: Order::class,
                referenceId: $order->id,
                inventoryItem: $saleMovement->inventoryItem,
            );
        }
        $order->update(['stock_reverted_at' => now()]);
    }

    /**
     * @return array{0: Branch, 1: Employee|null}
     */
    private function adminAssignment(int $branchId, ?int $salesEmployeeId): array
    {
        return $this->commercialContext->adminAssignment($branchId, $salesEmployeeId);
    }

    /**
     * Capture the branch and optional seller before the order becomes a sale.
     * Once confirmed, these fields are historical snapshots and are not changed.
     *
     * @param  array<string, mixed>  $assignment
     */
    private function prepareConfirmationAssignment(Order $order, array $assignment): void
    {
        if (array_key_exists('branch_id', $assignment) && $assignment['branch_id'] === null) {
            throw ValidationException::withMessages([
                'branch_id' => 'La orden debe tener una sede antes de confirmarse.',
            ]);
        }

        $branchId = array_key_exists('branch_id', $assignment)
            ? (int) $assignment['branch_id']
            : ($order->branch_id !== null ? (int) $order->branch_id : null);

        if ($branchId === null) {
            throw ValidationException::withMessages([
                'branch_id' => 'La orden debe tener una sede antes de confirmarse.',
            ]);
        }

        $salesEmployeeId = array_key_exists('sales_employee_id', $assignment)
            ? ($assignment['sales_employee_id'] !== null ? (int) $assignment['sales_employee_id'] : null)
            : ($order->sales_employee_id !== null ? (int) $order->sales_employee_id : null);

        [$branch, $seller] = $this->adminAssignment($branchId, $salesEmployeeId);

        $order->update([
            'branch_id' => $branch->id,
            'sales_employee_id' => $seller?->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $assignment
     */
    private function assertFrozenAssignment(Order $order, array $assignment): void
    {
        $errors = [];
        if (
            array_key_exists('branch_id', $assignment)
            && ($assignment['branch_id'] === null || (int) $assignment['branch_id'] !== (int) $order->branch_id)
        ) {
            $errors['branch_id'] = 'La sede de una venta confirmada no puede modificarse.';
        }
        if (
            array_key_exists('sales_employee_id', $assignment)
            && ($assignment['sales_employee_id'] === null
                ? $order->sales_employee_id !== null
                : (int) $assignment['sales_employee_id'] !== (int) $order->sales_employee_id)
        ) {
            $errors['sales_employee_id'] = 'El vendedor de una venta confirmada no puede modificarse.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function resolveCustomer(array $data, ?Customer $customer): array
    {
        if ($customer) {
            return [
                'customer_name' => $customer->name,
                'customer_email' => $customer->email,
                'customer_phone' => $customer->phone,
                'customer_document' => $customer->document,
                'customer_city' => $customer->city,
                'customer_address' => $customer->address,
                'customer_notes' => $data['customer_notes'] ?? $customer->notes,
            ];
        }

        return [
            'customer_name' => $data['customer_name'] ?? null,
            'customer_email' => $data['customer_email'] ?? null,
            'customer_phone' => $data['customer_phone'] ?? null,
            'customer_document' => $data['customer_document'] ?? null,
            'customer_city' => $data['customer_city'] ?? null,
            'customer_address' => $data['customer_address'] ?? null,
            'customer_notes' => $data['customer_notes'] ?? null,
        ];
    }

    private function resolveVehicle(array $data, ?CustomerVehicle $customerVehicle = null): array
    {
        if ($customerVehicle) {
            return [
                'vehicle_brand_id' => $customerVehicle->vehicle_brand_id,
                'vehicle_model_id' => $customerVehicle->vehicle_model_id,
                'vehicle_version_id' => $customerVehicle->vehicle_version_id,
                'vehicle_brand_name' => $customerVehicle->vehicleBrand?->name,
                'vehicle_model_name' => $customerVehicle->vehicleModel?->name,
                'vehicle_version_name' => $customerVehicle->vehicleVersion?->display_name,
                'vehicle_year' => $customerVehicle->year,
                'vehicle_plate' => $customerVehicle->plate,
                'vehicle_vin' => $customerVehicle->vin,
                'vehicle_color' => $customerVehicle->color,
                'vehicle_notes' => $customerVehicle->notes,
            ];
        }

        $brand = isset($data['vehicle_brand_id']) ? VehicleBrand::find($data['vehicle_brand_id']) : null;
        $model = isset($data['vehicle_model_id']) ? VehicleModel::find($data['vehicle_model_id']) : null;
        $version = isset($data['vehicle_version_id']) ? VehicleVersion::find($data['vehicle_version_id']) : null;
        if ($model && (! $brand || $model->vehicle_brand_id !== $brand->id)) {
            throw ValidationException::withMessages(['vehicle_model_id' => 'El modelo no pertenece a la marca indicada.']);
        }
        if ($version && (! $model || $version->vehicle_model_id !== $model->id)) {
            throw ValidationException::withMessages(['vehicle_version_id' => 'La versión no pertenece al modelo indicado.']);
        }

        return [
            'vehicle_brand_id' => $brand?->id, 'vehicle_model_id' => $model?->id, 'vehicle_version_id' => $version?->id,
            'vehicle_brand_name' => $brand?->name, 'vehicle_model_name' => $model?->name, 'vehicle_version_name' => $version?->display_name,
            'vehicle_year' => $data['vehicle_year'] ?? null, 'vehicle_plate' => $data['vehicle_plate'] ?? null,
            'vehicle_vin' => $data['vehicle_vin'] ?? null, 'vehicle_color' => $data['vehicle_color'] ?? null,
            'vehicle_notes' => $data['vehicle_notes'] ?? null,
        ];
    }

    private function generateOrderNumber(): string
    {
        do {
            $number = 'UG79-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (Order::where('order_number', $number)->exists());

        return $number;
    }

    public function loadOrder(Order $order): Order
    {
        return $order->load([
            'items.product',
            'items.productVariant',
            'items.service.category',
            'payments.creator',
            'charges',
            'shippingAddress',
            'statusHistory.changedBy',
            'user',
            'customer',
            'customerVehicle.vehicleBrand',
            'customerVehicle.vehicleModel',
            'customerVehicle.vehicleVersion',
            'creator',
            'canceller',
            'vehicleBrand',
            'vehicleModel',
            'vehicleVersion',
            'branch:id,code,name,city,is_active',
            'salesEmployee:id,branch_id,name,is_active',
            'salesEmployee.branch:id,code,name,city,is_active',
        ]);
    }

    public function loadPublicOrder(Order $order): Order
    {
        return $order->load('items');
    }

    public function applyPersonalFilters(Builder $query, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        $query
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $match) use ($search) {
                    $like = "%{$search}%";
                    $match->where('order_number', 'like', $like)
                        ->orWhere('customer_name', 'like', $like)
                        ->orWhere('customer_phone', 'like', $like)
                        ->orWhere('customer_email', 'like', $like);
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status));

        if ($filters['date_from'] ?? null) {
            $query->where(
                'created_at',
                '>=',
                CarbonImmutable::parse($filters['date_from'], BusinessContext::TIMEZONE)->startOfDay()->utc(),
            );
        }
        if ($filters['date_to'] ?? null) {
            $query->where(
                'created_at',
                '<',
                CarbonImmutable::parse($filters['date_to'], BusinessContext::TIMEZONE)->addDay()->startOfDay()->utc(),
            );
        }

        return $query;
    }

    public function loadPersonal(Order $order): Order
    {
        return $order->unsetRelations()->load([
            'branch:id,code,name,city,is_active',
            'salesEmployee:id,branch_id,name,is_active',
            'items',
            'payments',
        ]);
    }
}
