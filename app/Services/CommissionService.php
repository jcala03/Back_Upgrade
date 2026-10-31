<?php

namespace App\Services;

use App\Models\CrmNotification;
use App\Models\Employee;
use App\Models\EmployeeCommission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentReconciliationReview;
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
        $commissions = DB::transaction(function () use ($order, $earnedAt): Collection {
            // Serialize completion, later payments and retries on the same order.
            // Never trust a stale caller's payment_status or total.
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (! $this->fullyPaidAndCompleted($order)) {
                return new Collection;
            }
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

    private function fullyPaidAndCompleted(Order $order): bool
    {
        if ($order->status !== Order::STATUS_COMPLETED
            || $order->payment_status !== Order::PAYMENT_PAID
            || (int) $order->total <= 0
            || $order->reconciliationReviews()->where('state', '!=', PaymentReconciliationReview::STATE_RESOLVED)->exists()
            || $order->payments()->whereNotNull('reconciliation_required_at')->exists()
            || $order->payments()->whereHas('reconciliationReviews', fn (Builder $reviews) => $reviews
                ->where('state', '!=', PaymentReconciliationReview::STATE_RESOLVED))->exists()) {
            return false;
        }

        // Bounded subtraction avoids integer overflow. No payment row joins that
        // could count a credit twice, and no provider calls or state changes.
        $remaining = (int) $order->total;
        foreach ($order->payments()->where('status', Payment::STATUS_COMPLETED)->get() as $payment) {
            if ($payment->currency !== $order->currency || (int) $payment->amount <= 0) {
                return false;
            }
            $remaining -= min($remaining, (int) $payment->amount);
        }

        return $remaining === 0;
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
        return $this->adminQuery($filters)
            ->withExists($this->reviewRelation())
            ->with($this->relations())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100))
            ->withQueryString();
    }

    private function adminQuery(array $filters): Builder
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
            });
    }

    public function paginateForEmployee(Employee $employee, array $filters): LengthAwarePaginator
    {
        return $this->applyListFilters(
            EmployeeCommission::query()->where('employee_id', $employee->id),
            $filters,
        )
            ->withExists($this->reviewRelation())
            ->with($this->relations())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100))
            ->withQueryString();
    }

    public function load(EmployeeCommission $commission): EmployeeCommission
    {
        return $commission->load($this->relations())->loadExists($this->reviewRelation());
    }

    /** Gross historical earnings remain intact; this is a review flag, not a reversal. */
    private function reviewRelation(): array
    {
        return ['order as financial_review_required' => fn (Builder $order) => $order
            ->where(function (Builder $risk): void {
                $risk->where('payment_status', '!=', Order::PAYMENT_PAID)
                    ->orWhere('status', '!=', Order::STATUS_COMPLETED)
                    ->orWhereHas('payments', fn (Builder $payments) => $payments
                        ->where('status', Payment::STATUS_REFUNDED)
                        ->orWhereNotNull('reconciliation_required_at')
                        ->orWhereHas('reconciliationReviews', fn (Builder $reviews) => $reviews
                            ->where('state', '!=', PaymentReconciliationReview::STATE_RESOLVED)))
                    ->orWhereHas('reconciliationReviews', fn (Builder $reviews) => $reviews
                        ->where('state', '!=', PaymentReconciliationReview::STATE_RESOLVED));
            })];
    }

    public function summary(?Employee $employee, array $filters = [], bool $admin = false): array
    {
        // Keep totals and seller breakdown in the same database read snapshot.
        return DB::transaction(fn () => $this->summarySnapshot($employee, $filters, $admin));
    }

    private function summarySnapshot(?Employee $employee, array $filters, bool $admin): array
    {
        $filters = $this->periodFilters($filters);
        $query = $admin ? $this->adminQuery($filters) : $this->applyListFilters(
            EmployeeCommission::query()->where('employee_id', $employee?->id ?? 0), $filters,
        );
        $totals = $this->aggregate(clone $query);
        $byEmployee = [];
        if ($admin) {
            $groups = $this->aggregateQuery(clone $query)->addSelect('employee_id')->groupBy('employee_id')->get();
            $employees = Employee::query()->whereIn('id', $groups->pluck('employee_id'))->pluck('name', 'id');
            $reviews = $this->reviewQuery(clone $query)->selectRaw('employee_id, SUM(amount) AS review_amount')
                ->groupBy('employee_id')->pluck('review_amount', 'employee_id');
            foreach ($groups as $group) {
                $byEmployee[] = [
                    'employee' => ['id' => (int) $group->employee_id, 'name' => $employees[$group->employee_id] ?? 'Vendedor histórico'],
                    ...$this->integerTotals($group),
                    'review_amount' => (int) ($reviews[$group->employee_id] ?? 0),
                ];
            }
        }

        return [
            'period' => ['from' => $filters['date_from'], 'to' => $filters['date_to'], 'timezone' => BusinessContext::TIMEZONE],
            // Retained for legacy consumers. New consumers use the period-neutral key.
            'current_month' => $totals,
            'totals' => $totals,
            'by_employee' => $byEmployee,
            'date_basis' => ['pending' => 'created_at', 'earned' => 'earned_at', 'voided' => 'voided_at'],
        ];
    }

    private function periodFilters(array $filters): array
    {
        $month = BusinessContext::now()->startOfMonth();

        return [...$filters, 'date_basis' => 'event',
            'date_from' => $filters['date_from'] ?? $month->toDateString(),
            'date_to' => $filters['date_to'] ?? $month->endOfMonth()->toDateString()];
    }

    private function eventPeriod(Builder $query, string $from, string $until): Builder
    {
        return $query->where(function (Builder $events) use ($from, $until): void {
            foreach (['pending' => 'created_at', 'earned' => 'earned_at', 'voided' => 'voided_at'] as $status => $date) {
                $events->orWhere(fn (Builder $event) => $event->where('status', $status)->where($date, '>=', $from)->where($date, '<', $until));
            }
        });
    }

    private function aggregateQuery(Builder $query): Builder
    {
        foreach (['pending', 'earned', 'voided'] as $status) {
            $query->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN amount ELSE 0 END), 0) AS '.$status.'_amount', [$status]);
        }

        return $query->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN quantity ELSE 0 END), 0) AS earned_units', ['earned'])
            ->selectRaw('COUNT(*) AS total_rows, COALESCE(SUM(quantity), 0) AS total_units, COUNT(DISTINCT order_id) AS sales_count');
    }

    private function integerTotals($row): array
    {
        $totals = [];
        foreach (['pending_amount', 'earned_amount', 'voided_amount', 'earned_units', 'total_rows', 'total_units', 'sales_count'] as $field) {
            $totals[$field] = (int) ($row?->$field ?? 0);
        }

        return $totals;
    }

    private function reviewQuery(Builder $query): Builder
    {
        $relation = $this->reviewRelation();

        return $query->where('status', EmployeeCommission::STATUS_EARNED)->whereHas('order', reset($relation));
    }

    private function aggregate(Builder $query): array
    {
        return [...$this->integerTotals($this->aggregateQuery(clone $query)->first()),
            'review_amount' => (int) $this->reviewQuery(clone $query)->sum('amount')];
    }

    /**
     * Current-month event summary. Pending uses created_at, earned uses earned_at,
     * and voided uses voided_at; every boundary is a Bogotá day converted to UTC.
     *
     * @return array<string, mixed>
     */
    public function currentMonthSummary(?Employee $employee): array
    {
        return $this->summary($employee);
    }

    /** Event summary used by the existing personal business overview. */
    public function summaryForPeriod(
        ?Employee $employee,
        CarbonInterface|string $fromUtc,
        CarbonInterface|string $untilUtc,
    ): array {
        $from = $fromUtc instanceof CarbonInterface ? $fromUtc->toDateTimeString() : $fromUtc;
        $until = $untilUtc instanceof CarbonInterface ? $untilUtc->toDateTimeString() : $untilUtc;

        return $this->aggregate($this->eventPeriod(
            EmployeeCommission::query()->where('employee_id', $employee?->id ?? 0), $from, $until,
        ));
    }

    private function applyListFilters(Builder $query, array $filters): Builder
    {
        $query->when($filters['status'] ?? null, fn (Builder $builder, $status) => $builder->where('status', $status));
        if (($filters['date_basis'] ?? 'created_at') === 'event') {
            $filters = $this->periodFilters($filters);

            return $this->eventPeriod($query,
                $this->businessDayStartUtc($filters['date_from'])->toDateTimeString(),
                $this->businessDayStartUtc($filters['date_to'])->addDay()->toDateTimeString(),
            );
        }
        // Preserve the legacy created_at API unless event dates are requested.
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
