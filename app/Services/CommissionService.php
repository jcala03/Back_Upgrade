<?php

namespace App\Services;

use App\Models\CrmNotification;
use App\Models\Employee;
use App\Models\EmployeeCommission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\Business\BusinessContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

class CommissionService
{
    public function __construct(private readonly CrmNotificationService $notifications) {}

    /**
     * Freeze one pending ledger row per eligible product OrderItem.
     *
     * The caller is expected to invoke this while the confirming Order is
     * locked. The nested transaction keeps direct service use atomic too.
     *
     * @return Collection<int, EmployeeCommission>
     */
    public function createPendingForOrder(Order $order): Collection
    {
        if ($order->sales_employee_id === null) {
            return new Collection;
        }
        if ($order->status !== Order::STATUS_CONFIRMED) {
            throw new LogicException('Pending commissions can only be generated for a confirmed order.');
        }
        if ($order->branch_id === null) {
            throw new LogicException('A seller commission requires the historical order branch.');
        }

        return DB::transaction(function () use ($order): Collection {
            $items = OrderItem::query()
                ->where('order_id', $order->id)
                ->where('item_type', OrderItem::ITEM_TYPE_PRODUCT)
                ->orderBy('id')
                ->sharedLock()
                ->get();

            $products = Product::query()
                ->whereIn('id', $items->pluck('product_id')->filter()->unique())
                ->orderBy('id')
                ->sharedLock()
                ->get()
                ->keyBy('id');

            $commissions = new Collection;

            foreach ($items as $item) {
                /** @var Product|null $product */
                $product = $products->get($item->product_id);
                if (! $product?->generatesCommission()) {
                    continue;
                }

                $unitCommission = $product->commissionAmount();
                if ($unitCommission === null || $unitCommission <= 0) {
                    continue;
                }

                $quantity = (int) $item->quantity;
                if ($quantity <= 0) {
                    throw new LogicException('A commissionable OrderItem must have a positive quantity.');
                }
                if ($unitCommission > intdiv(PHP_INT_MAX, $quantity)) {
                    throw new LogicException('The commission amount exceeds the supported integer range.');
                }

                $identity = [
                    'order_item_id' => $item->id,
                    'employee_id' => $order->sales_employee_id,
                ];
                $snapshot = [
                    'branch_id' => $order->branch_id,
                    'order_id' => $order->id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'product_name_snapshot' => $item->product_name ?? $product->name,
                    'variant_name_snapshot' => $item->variant_name,
                    'sku_snapshot' => $item->product_variant_id !== null
                        ? ($item->variant_sku ?? $item->product_sku)
                        : $item->product_sku,
                    'quantity' => $quantity,
                    'unit_commission' => $unitCommission,
                    'amount' => $quantity * $unitCommission,
                    'status' => EmployeeCommission::STATUS_PENDING,
                ];
                $commission = EmployeeCommission::query()->firstOrCreate(
                    $identity,
                    $snapshot,
                );

                if (! $commission->wasRecentlyCreated) {
                    $this->assertImmutableSnapshot($commission, $identity + $snapshot);
                }

                $commissions->push($commission);
            }

            return $commissions;
        });
    }

    /**
     * Transition only pending rows. An idempotent retry returns an empty set.
     *
     * @return Collection<int, EmployeeCommission>
     */
    public function earnForOrder(Order $order, ?CarbonInterface $earnedAt = null): Collection
    {
        if ($order->status !== Order::STATUS_COMPLETED) {
            throw new LogicException('Commissions can only be earned by a completed order.');
        }

        $commissions = DB::transaction(function () use ($order, $earnedAt): Collection {
            $commissions = EmployeeCommission::query()
                ->where('order_id', $order->id)
                ->where('status', EmployeeCommission::STATUS_PENDING)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $timestamp = $earnedAt ?? now();

            foreach ($commissions as $commission) {
                $commission->update([
                    'status' => EmployeeCommission::STATUS_EARNED,
                    'earned_at' => $timestamp,
                ]);
            }

            return $commissions;
        });

        $this->notifyEarned($commissions);

        return $commissions;
    }

    /**
     * Void only pending rows, preserving the immutable monetary snapshots.
     *
     * @return Collection<int, EmployeeCommission>
     */
    public function voidPendingForOrder(
        Order $order,
        ?User $actor = null,
        string $reason = EmployeeCommission::VOID_REASON_ORDER_CANCELLED,
        ?CarbonInterface $voidedAt = null,
    ): Collection {
        if ($order->status !== Order::STATUS_CANCELLED) {
            throw new LogicException('Commissions can only be voided by a cancelled order.');
        }

        return DB::transaction(function () use ($order, $actor, $reason, $voidedAt): Collection {
            $commissions = EmployeeCommission::query()
                ->where('order_id', $order->id)
                ->where('status', EmployeeCommission::STATUS_PENDING)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $timestamp = $voidedAt ?? now();

            foreach ($commissions as $commission) {
                $commission->update([
                    'status' => EmployeeCommission::STATUS_VOIDED,
                    'voided_at' => $timestamp,
                    'voided_by' => $actor?->id,
                    'void_reason' => $reason,
                ]);
            }

            return $commissions;
        });
    }

    public function paginateAdmin(array $filters): LengthAwarePaginator
    {
        return $this->applyListFilters(EmployeeCommission::query(), $filters)
            ->when($filters['employee_id'] ?? null, fn (Builder $query, $value) => $query->where('employee_id', $value))
            ->when($filters['branch_id'] ?? null, fn (Builder $query, $value) => $query->where('branch_id', $value))
            ->when($filters['order_id'] ?? null, fn (Builder $query, $value) => $query->where('order_id', $value))
            ->when($filters['product_id'] ?? null, fn (Builder $query, $value) => $query->where('product_id', $value))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $term = '%'.addcslashes(trim($search), '\\%_').'%';
                $query->where(function (Builder $match) use ($term): void {
                    $match->where('product_name_snapshot', 'like', $term)
                        ->orWhere('variant_name_snapshot', 'like', $term)
                        ->orWhere('sku_snapshot', 'like', $term)
                        ->orWhereHas('order', fn (Builder $order) => $order->where('order_number', 'like', $term))
                        ->orWhereHas('employee', fn (Builder $employee) => $employee->where('name', 'like', $term));
                });
            })
            ->with($this->relations())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100))
            ->withQueryString();
    }

    public function paginateForEmployee(Employee $employee, array $filters): LengthAwarePaginator
    {
        return $this->applyListFilters(
            EmployeeCommission::query()->where('employee_id', $employee->id),
            $filters,
        )
            ->with($this->relations())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100))
            ->withQueryString();
    }

    public function load(EmployeeCommission $commission): EmployeeCommission
    {
        return $commission->load($this->relations());
    }

    /**
     * Current-month event summary. Pending uses created_at, earned uses earned_at,
     * and voided uses voided_at; every boundary is a Bogotá day converted to UTC.
     *
     * @return array<string, mixed>
     */
    public function currentMonthSummary(?Employee $employee): array
    {
        $month = BusinessContext::now()->startOfMonth();
        $nextMonth = $month->addMonth();
        $fromUtc = $month->utc()->toDateTimeString();
        $untilUtc = $nextMonth->utc()->toDateTimeString();

        $totals = $this->summaryForPeriod($employee, $fromUtc, $untilUtc);

        return [
            'period' => [
                'from' => $month->toDateString(),
                'to' => $nextMonth->subDay()->toDateString(),
                'timezone' => BusinessContext::TIMEZONE,
            ],
            'current_month' => [
                'pending_amount' => $totals['pending_amount'],
                'earned_amount' => $totals['earned_amount'],
                'voided_amount' => $totals['voided_amount'],
                'total_rows' => $totals['total_rows'],
                'total_units' => $totals['total_units'],
            ],
            'date_basis' => [
                'pending' => 'created_at',
                'earned' => 'earned_at',
                'voided' => 'voided_at',
            ],
        ];
    }

    /**
     * Summarize immutable ledger events for one employee and a UTC half-open period.
     * Pending uses created_at, earned uses earned_at, and voided uses voided_at.
     *
     * @return array{pending_amount: int, earned_amount: int, voided_amount: int, earned_units: int, total_rows: int, total_units: int}
     */
    public function summaryForPeriod(
        ?Employee $employee,
        CarbonInterface|string $fromUtc,
        CarbonInterface|string $untilUtc,
    ): array {
        $from = $fromUtc instanceof CarbonInterface ? $fromUtc->toDateTimeString() : $fromUtc;
        $until = $untilUtc instanceof CarbonInterface ? $untilUtc->toDateTimeString() : $untilUtc;

        $totals = EmployeeCommission::query()
            ->where('employee_id', $employee?->id ?? 0)
            ->where(function (Builder $query) use ($from, $until): void {
                $query->where(function (Builder $pending) use ($from, $until): void {
                    $pending->where('status', EmployeeCommission::STATUS_PENDING)
                        ->where('created_at', '>=', $from)
                        ->where('created_at', '<', $until);
                })->orWhere(function (Builder $earned) use ($from, $until): void {
                    $earned->where('status', EmployeeCommission::STATUS_EARNED)
                        ->where('earned_at', '>=', $from)
                        ->where('earned_at', '<', $until);
                })->orWhere(function (Builder $voided) use ($from, $until): void {
                    $voided->where('status', EmployeeCommission::STATUS_VOIDED)
                        ->where('voided_at', '>=', $from)
                        ->where('voided_at', '<', $until);
                });
            })
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status = ? AND created_at >= ? AND created_at < ? THEN amount ELSE 0 END), 0) AS pending_amount',
                [EmployeeCommission::STATUS_PENDING, $from, $until],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status = ? AND earned_at >= ? AND earned_at < ? THEN amount ELSE 0 END), 0) AS earned_amount',
                [EmployeeCommission::STATUS_EARNED, $from, $until],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status = ? AND voided_at >= ? AND voided_at < ? THEN amount ELSE 0 END), 0) AS voided_amount',
                [EmployeeCommission::STATUS_VOIDED, $from, $until],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status = ? AND earned_at >= ? AND earned_at < ? THEN quantity ELSE 0 END), 0) AS earned_units',
                [EmployeeCommission::STATUS_EARNED, $from, $until],
            )
            ->selectRaw('COALESCE(SUM(quantity), 0) AS total_units')
            ->selectRaw('COUNT(*) AS total_rows')
            ->first();

        return [
            'pending_amount' => (int) ($totals?->pending_amount ?? 0),
            'earned_amount' => (int) ($totals?->earned_amount ?? 0),
            'voided_amount' => (int) ($totals?->voided_amount ?? 0),
            'earned_units' => (int) ($totals?->earned_units ?? 0),
            'total_rows' => (int) ($totals?->total_rows ?? 0),
            'total_units' => (int) ($totals?->total_units ?? 0),
        ];
    }

    private function applyListFilters(Builder $query, array $filters): Builder
    {
        $query->when(
            $filters['status'] ?? null,
            fn (Builder $builder, $status) => $builder->where('status', $status),
        );

        if ($from = $filters['date_from'] ?? null) {
            $query->where('created_at', '>=', $this->businessDayStartUtc($from));
        }
        if ($to = $filters['date_to'] ?? null) {
            $query->where('created_at', '<', $this->businessDayStartUtc($to)->addDay());
        }

        return $query;
    }

    private function businessDayStartUtc(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, BusinessContext::TIMEZONE)
            ->startOfDay()
            ->utc();
    }

    /** @param Collection<int, EmployeeCommission> $commissions */
    private function notifyEarned(Collection $commissions): void
    {
        if ($commissions->isEmpty()) {
            return;
        }

        $commissions->loadMissing([
            'employee.user',
            'order:id,order_number',
        ]);

        foreach ($commissions as $commission) {
            $user = $commission->employee?->user;
            if (! $user?->is_active) {
                continue;
            }

            $orderNumber = $commission->order?->order_number ?? (string) $commission->order_id;

            try {
                $this->notifications->createFor($user, [
                    'type' => CrmNotification::TYPE_COMMISSION_EARNED,
                    'severity' => CrmNotification::SEVERITY_SUCCESS,
                    'title' => 'Comisión ganada',
                    'message' => 'Ganaste una comisión de '.number_format($commission->amount, 0, ',', '.')." COP por la venta {$orderNumber}.",
                    'data' => [
                        'commission_id' => $commission->id,
                        'order_id' => $commission->order_id,
                        'order_number' => $commission->order?->order_number,
                        'amount' => $commission->amount,
                    ],
                    'reference_type' => 'order',
                    'reference_id' => $commission->order_id,
                    'dedupe_key' => "commission_earned:{$commission->id}",
                ]);
            } catch (\Throwable $exception) {
                Log::warning('Commission earned notification failed.', [
                    'commission_id' => $commission->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }

    /** @param array<string, mixed> $expected */
    private function assertImmutableSnapshot(EmployeeCommission $commission, array $expected): void
    {
        $integerFields = [
            'employee_id', 'branch_id', 'order_id', 'order_item_id', 'product_id',
            'product_variant_id', 'quantity', 'unit_commission', 'amount',
        ];
        $snapshotFields = [
            ...$integerFields,
            'product_name_snapshot', 'variant_name_snapshot', 'sku_snapshot',
        ];

        foreach ($snapshotFields as $field) {
            $actual = $commission->getAttribute($field);
            $wanted = $expected[$field] ?? null;
            if (in_array($field, $integerFields, true)) {
                $actual = $actual === null ? null : (int) $actual;
                $wanted = $wanted === null ? null : (int) $wanted;
            }
            if ($actual !== $wanted) {
                throw new LogicException("Existing commission {$commission->id} has a conflicting immutable {$field} snapshot.");
            }
        }
    }

    /** @return array<int, string> */
    private function relations(): array
    {
        return [
            'employee:id,name',
            'branch:id,code,name',
            'order:id,order_number',
        ];
    }
}
