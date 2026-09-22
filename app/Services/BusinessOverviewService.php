<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Quotation;
use App\Models\User;
use App\Models\UserCapability;
use App\Support\Business\BusinessContext;
use App\Support\Reports\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class BusinessOverviewService
{
    private const TOP_PRODUCTS_LIMIT = 5;

    private const VALID_ORDER_STATUSES = [Order::STATUS_CONFIRMED, Order::STATUS_COMPLETED];

    public function __construct(private readonly CommissionService $commissions) {}

    /** @return array<string, mixed> */
    public function build(User $user, ?Employee $employee, array $filters): array
    {
        $period = $this->period($filters);

        if (! $employee) {
            return [
                'employee_linked' => false,
                'period' => $this->periodPayload($period),
                'employee' => null,
                'branch' => null,
                'company' => null,
                'my_branch' => null,
                'personal' => $this->unavailablePersonal(),
            ];
        }

        return [
            'employee_linked' => true,
            'period' => $this->periodPayload($period),
            'employee' => [
                'id' => (int) $employee->id,
                'name' => $employee->name,
            ],
            'branch' => $this->branchPayload($employee->branch),
            'company' => $this->safeMetrics($period),
            'my_branch' => $this->safeMetrics($period, $employee->branch),
            'personal' => $this->personal($user, $employee, $period),
        ];
    }

    private function period(array $filters): ReportPeriod
    {
        $month = BusinessContext::today()->startOfMonth();

        return ReportPeriod::from([
            'date_from' => $filters['from'] ?? $month->toDateString(),
            'date_to' => $filters['to'] ?? $month->endOfMonth()->toDateString(),
        ]);
    }

    private function periodPayload(ReportPeriod $period): array
    {
        return [
            'from' => $period->dateFrom,
            'to' => $period->dateTo,
            'timezone' => BusinessContext::TIMEZONE,
        ];
    }

    private function branchPayload(Branch $branch): array
    {
        return [
            'id' => (int) $branch->id,
            'code' => $branch->code,
            'name' => $branch->name,
            'city' => $branch->city,
            'is_active' => (bool) $branch->is_active,
        ];
    }

    private function unavailablePersonal(): array
    {
        return [
            'sales' => ['available' => false],
            'quotations' => ['available' => false],
            'commissions' => ['available' => false],
        ];
    }

    /** @return array<string, mixed> */
    private function safeMetrics(ReportPeriod $period, ?Branch $branch = null): array
    {
        $orders = $this->validOrders($period, $branch)
            ->selectRaw('COUNT(*) AS sales_count')
            ->selectRaw('COUNT(DISTINCT customer_id) AS customers_served')
            ->first();

        $items = $this->productItems($period, $branch);
        $productUnits = (int) (clone $items)->sum('order_items.quantity');
        $quotation = $this->quotationMetrics($period, $branch);

        return [
            'sales_count' => (int) ($orders?->sales_count ?? 0),
            'product_units' => $productUnits,
            'quotations_count' => $quotation['quotation_count'],
            'quotation_conversion_rate' => $quotation['conversion_rate'],
            'customers_served' => (int) ($orders?->customers_served ?? 0),
            'top_products' => $this->topProducts($items),
        ];
    }

    private function validOrders(ReportPeriod $period, ?Branch $branch = null): Builder
    {
        return Order::query()
            ->whereIn('status', self::VALID_ORDER_STATUSES)
            ->whereNotNull('confirmed_at')
            ->where('confirmed_at', '>=', $period->from)
            ->where('confirmed_at', '<', $period->toExclusive)
            ->when($branch, fn (Builder $query, Branch $selected) => $query->where('branch_id', $selected->id));
    }

    private function productItems(ReportPeriod $period, ?Branch $branch = null): QueryBuilder
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.item_type', OrderItem::ITEM_TYPE_PRODUCT)
            ->whereIn('orders.status', self::VALID_ORDER_STATUSES)
            ->whereNotNull('orders.confirmed_at')
            ->where('orders.confirmed_at', '>=', $period->from)
            ->where('orders.confirmed_at', '<', $period->toExclusive)
            ->when($branch, fn (QueryBuilder $query, Branch $selected) => $query->where('orders.branch_id', $selected->id));
    }

    private function quotationMetrics(
        ReportPeriod $period,
        ?Branch $branch = null,
        ?Employee $employee = null,
    ): array {
        $today = BusinessContext::today()->toDateString();
        $metrics = Quotation::query()
            ->where('created_at', '>=', $period->from)
            ->where('created_at', '<', $period->toExclusive)
            ->when($branch, fn (Builder $query, Branch $selected) => $query->where('branch_id', $selected->id))
            ->when($employee, fn (Builder $query, Employee $seller) => $query->where('sales_employee_id', $seller->id))
            ->selectRaw('COUNT(*) AS quotation_count')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS converted_count',
                [Quotation::STATUS_CONVERTED],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status IN (?, ?, ?) OR (status IN (?, ?) AND valid_until IS NOT NULL AND valid_until < ?) THEN 1 ELSE 0 END), 0) AS resolved_count',
                [
                    Quotation::STATUS_CONVERTED,
                    Quotation::STATUS_REJECTED,
                    Quotation::STATUS_EXPIRED,
                    Quotation::STATUS_DRAFT,
                    Quotation::STATUS_SENT,
                    $today,
                ],
            )
            ->first();

        $resolved = (int) ($metrics?->resolved_count ?? 0);
        $converted = (int) ($metrics?->converted_count ?? 0);

        return [
            'quotation_count' => (int) ($metrics?->quotation_count ?? 0),
            'converted_count' => $converted,
            'conversion_rate' => $resolved > 0 ? round($converted / $resolved * 100, 2) : 0,
        ];
    }

    private function topProducts(QueryBuilder $items): array
    {
        $key = "CASE WHEN order_items.product_id IS NOT NULL THEN CONCAT('id:', order_items.product_id) ELSE CONCAT('snapshot:', COALESCE(order_items.product_sku, ''), ':', order_items.product_name) END";

        return (clone $items)
            ->selectRaw("{$key} product_key")
            ->selectRaw('MAX(order_items.product_id) product_id')
            ->selectRaw('MAX(order_items.product_name) product_name')
            ->selectRaw('MAX(order_items.product_sku) sku')
            ->selectRaw('SUM(order_items.quantity) units')
            ->groupByRaw($key)
            ->orderByDesc('units')
            ->orderBy('product_key')
            ->limit(self::TOP_PRODUCTS_LIMIT)
            ->get()
            ->map(fn ($item): array => [
                'product_id' => $item->product_id === null ? null : (int) $item->product_id,
                'product_name' => $item->product_name,
                'sku' => $item->sku,
                'units' => (int) $item->units,
            ])
            ->all();
    }

    private function personal(User $user, Employee $employee, ReportPeriod $period): array
    {
        return [
            'sales' => $user->hasPermission(UserCapability::ORDERS_VIEW_OWN)
                ? ['available' => true, ...$this->personalSales($employee, $period)]
                : ['available' => false],
            'quotations' => $user->hasPermission(UserCapability::QUOTATIONS_VIEW_OWN)
                ? ['available' => true, ...$this->personalQuotations($employee, $period)]
                : ['available' => false],
            'commissions' => $user->hasPermission(UserCapability::COMMISSIONS_VIEW_OWN)
                ? ['available' => true, ...$this->personalCommissions($employee, $period)]
                : ['available' => false],
        ];
    }

    private function personalSales(Employee $employee, ReportPeriod $period): array
    {
        $sales = $this->validOrders($period)
            ->where('sales_employee_id', $employee->id)
            ->selectRaw('COUNT(*) AS sales_count')
            ->selectRaw('COALESCE(SUM(total), 0) AS sales_total')
            ->first();

        $units = (int) $this->productItems($period)
            ->where('orders.sales_employee_id', $employee->id)
            ->sum('order_items.quantity');

        return [
            'sales_count' => (int) ($sales?->sales_count ?? 0),
            'product_units' => $units,
            'sales_total' => (int) ($sales?->sales_total ?? 0),
        ];
    }

    private function personalQuotations(Employee $employee, ReportPeriod $period): array
    {
        $metrics = $this->quotationMetrics($period, employee: $employee);

        return [
            'quotation_count' => $metrics['quotation_count'],
            'converted_count' => $metrics['converted_count'],
            'conversion_rate' => $metrics['conversion_rate'],
        ];
    }

    private function personalCommissions(Employee $employee, ReportPeriod $period): array
    {
        $summary = $this->commissions->summaryForPeriod($employee, $period->from, $period->toExclusive);

        return [
            'pending_amount' => $summary['pending_amount'],
            'earned_amount' => $summary['earned_amount'],
            'voided_amount' => $summary['voided_amount'],
            'earned_units' => $summary['earned_units'],
        ];
    }
}
