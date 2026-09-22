<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use App\Support\Business\BusinessContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    private const LIST_LIMIT = 5;

    private const VALID_ORDER_STATUSES = [Order::STATUS_CONFIRMED, Order::STATUS_COMPLETED];

    public function build(User $user, ?Branch $branch = null): array
    {
        $period = $this->periods();

        $response = [
            'context' => [
                'mode' => $branch ? 'branch' : 'general',
                'branch' => $branch ? $this->branchPayload($branch) : null,
            ],
            'period' => $this->periodPayload($period),
            'sales' => $this->sales($period, $branch),
            'payments' => $this->payments($period, $branch),
            'quotations' => $this->quotations($period, $branch),
            'customers' => $this->customers($period, $branch),
            'inventory' => $this->inventorySummary($branch),
            'recent_orders' => $this->recentOrders($branch),
            'expiring_quotations' => $this->expiringQuotations($period, $branch),
            'low_stock' => $this->lowStock($branch),
            'top_products' => $this->topProducts($period, $branch),
        ];

        if ($user->hasPermission('dashboard.financials')) {
            $response['financials'] = $this->financials($period, $branch);
        }

        return $response;
    }

    public function compare(array $branchIds): array
    {
        $ids = collect($branchIds)->map(fn ($id): int => (int) $id)->values();
        $branches = Branch::query()->whereKey($ids)->get(['id', 'code', 'name'])->keyBy('id');
        $period = $this->periods();
        $sales = $this->validOrders()->whereIn('branch_id', $ids)
            ->where('confirmed_at', '>=', $period['month'][0])->where('confirmed_at', '<', $period['month'][1])
            ->selectRaw('branch_id, COALESCE(SUM(total), 0) sales_total, COUNT(*) sales_count')->groupBy('branch_id')->get()->keyBy('branch_id');
        $units = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.item_type', 'product')->whereIn('orders.status', self::VALID_ORDER_STATUSES)
            ->whereIn('orders.branch_id', $ids)->whereNotNull('orders.confirmed_at')
            ->where('orders.confirmed_at', '>=', $period['month'][0])->where('orders.confirmed_at', '<', $period['month'][1])
            ->selectRaw('orders.branch_id, COALESCE(SUM(order_items.quantity), 0) product_units')->groupBy('orders.branch_id')->get()->keyBy('branch_id');
        $payments = Payment::query()->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('payments.status', Payment::STATUS_COMPLETED)->whereNotNull('payments.paid_at')->whereIn('orders.branch_id', $ids)
            ->where('payments.paid_at', '>=', $period['month'][0])->where('payments.paid_at', '<', $period['month'][1])
            ->selectRaw('orders.branch_id, COALESCE(SUM(payments.amount), 0) payments_received')->groupBy('orders.branch_id')->get()->keyBy('branch_id');
        $receivables = $this->receivablesQuery()->whereIn('orders.branch_id', $ids)
            ->selectRaw('orders.branch_id, COALESCE(SUM(GREATEST(orders.total - COALESCE(completed_payments.paid_amount, 0), 0)), 0) receivables')
            ->groupBy('orders.branch_id')->get()->keyBy('branch_id');
        $quotationCounts = Quotation::query()->whereIn('branch_id', $ids)
            ->where('created_at', '>=', $period['month'][0])->where('created_at', '<', $period['month'][1])
            ->selectRaw('branch_id, COUNT(*) quotations_count')->groupBy('branch_id')->get()->keyBy('branch_id');
        $resolved = $this->resolvedQuotations($period)->whereIn('branch_id', $ids)
            ->selectRaw("branch_id, COUNT(*) resolved_count, SUM(status = 'converted') converted_count")
            ->groupBy('branch_id')->get()->keyBy('branch_id');
        $customers = $this->validOrders()->whereIn('branch_id', $ids)->whereNotNull('customer_id')
            ->where('confirmed_at', '>=', $period['month'][0])->where('confirmed_at', '<', $period['month'][1])
            ->selectRaw('branch_id, COUNT(DISTINCT customer_id) customers_served')->groupBy('branch_id')->get()->keyBy('branch_id');
        $lowStock = $this->inventoryStocks()->whereIn('inventory_stocks.branch_id', $ids)
            ->whereColumn('inventory_stocks.quantity', '<=', 'inventory_stocks.minimum_quantity')
            ->selectRaw('inventory_stocks.branch_id, COUNT(*) low_stock_positions')->groupBy('inventory_stocks.branch_id')->get()->keyBy('branch_id');

        return [
            'period' => [
                'timezone' => BusinessContext::TIMEZONE,
                'date_from' => $period['month_local'][0]->toDateString(),
                'date_to' => $period['month_local'][1]->subDay()->toDateString(),
                'generated_at' => $period['now']->utc()->toIso8601String(),
            ],
            'branches' => $ids->map(function (int $id) use ($branches, $sales, $units, $payments, $receivables, $quotationCounts, $resolved, $customers, $lowStock): array {
                $branch = $branches->get($id);
                $salesCount = (int) ($sales->get($id)?->sales_count ?? 0);
                $salesTotal = (int) ($sales->get($id)?->sales_total ?? 0);
                $resolvedCount = (int) ($resolved->get($id)?->resolved_count ?? 0);
                $convertedCount = (int) ($resolved->get($id)?->converted_count ?? 0);

                return [
                    'branch' => $this->branchPayload($branch),
                    'sales_total' => $salesTotal,
                    'sales_count' => $salesCount,
                    'average_ticket' => $salesCount > 0 ? round($salesTotal / $salesCount, 2) : 0,
                    'product_units' => (int) ($units->get($id)?->product_units ?? 0),
                    'quotations_count' => (int) ($quotationCounts->get($id)?->quotations_count ?? 0),
                    'conversion_rate' => $resolvedCount > 0 ? round($convertedCount / $resolvedCount * 100, 2) : null,
                    'payments_received' => (int) ($payments->get($id)?->payments_received ?? 0),
                    'receivables' => (int) ($receivables->get($id)?->receivables ?? 0),
                    'customers_served' => (int) ($customers->get($id)?->customers_served ?? 0),
                    'low_stock_positions' => (int) ($lowStock->get($id)?->low_stock_positions ?? 0),
                ];
            })->all(),
        ];
    }

    private function periods(): array
    {
        $now = BusinessContext::now();
        $today = $now->startOfDay();
        $monthStart = $today->startOfMonth();
        $monthEnd = $monthStart->addMonth();

        return [
            'now' => $now,
            'today' => $today,
            'day' => [$today->utc(), $today->addDay()->utc()],
            'week' => [$today->startOfWeek()->utc(), $today->startOfWeek()->addWeek()->utc()],
            'month' => [$monthStart->utc(), $monthEnd->utc()],
            'month_local' => [$monthStart, $monthEnd],
            'last_30' => [$today->subDays(29)->utc(), $today->addDay()->utc()],
        ];
    }

    private function periodPayload(array $period): array
    {
        return ['timezone' => BusinessContext::TIMEZONE, 'generated_at' => $period['now']->utc()->toIso8601String(), 'week_starts_on' => 'monday'];
    }

    private function validOrders(?Branch $branch = null): Builder
    {
        return Order::query()->whereIn('status', self::VALID_ORDER_STATUSES)->whereNotNull('confirmed_at')
            ->when($branch, fn (Builder $query, Branch $selected) => $query->where('branch_id', $selected->id));
    }

    private function sales(array $period, ?Branch $branch): array
    {
        $origin = $this->validOrders($branch)->where('confirmed_at', '>=', $period['month'][0])->where('confirmed_at', '<', $period['month'][1])
            ->selectRaw('origin, COALESCE(SUM(total), 0) total')->groupBy('origin')->pluck('total', 'origin');
        $month = $this->salesPeriod($period['month'], $branch);

        return [
            'today' => $this->salesPeriod($period['day'], $branch),
            'week' => $this->salesPeriod($period['week'], $branch),
            'month' => $month + ['average_ticket' => $month['orders_count'] > 0 ? round($month['total'] / $month['orders_count'], 2) : 0],
            'by_origin_month' => ['crm' => (int) ($origin[Order::ORIGIN_CRM] ?? 0), 'ecommerce' => (int) ($origin[Order::ORIGIN_ECOMMERCE] ?? 0)],
            'last_30_days' => $this->lastThirtyDays($period, $branch),
            'product_units_month' => $this->productUnits($period, $branch),
        ];
    }

    private function salesPeriod(array $bounds, ?Branch $branch): array
    {
        $result = $this->validOrders($branch)->where('confirmed_at', '>=', $bounds[0])->where('confirmed_at', '<', $bounds[1])
            ->selectRaw('COALESCE(SUM(total), 0) total, COUNT(*) orders_count')->first();

        return ['total' => (int) $result->total, 'orders_count' => (int) $result->orders_count];
    }

    private function productUnits(array $period, ?Branch $branch): int
    {
        return (int) DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.item_type', 'product')->whereIn('orders.status', self::VALID_ORDER_STATUSES)->whereNotNull('orders.confirmed_at')
            ->where('orders.confirmed_at', '>=', $period['month'][0])->where('orders.confirmed_at', '<', $period['month'][1])
            ->when($branch, fn (QueryBuilder $query, Branch $selected) => $query->where('orders.branch_id', $selected->id))->sum('order_items.quantity');
    }

    private function lastThirtyDays(array $period, ?Branch $branch): array
    {
        $offset = $period['now']->format('P');
        $rows = $this->validOrders($branch)->where('confirmed_at', '>=', $period['last_30'][0])->where('confirmed_at', '<', $period['last_30'][1])
            ->selectRaw("DATE(CONVERT_TZ(confirmed_at, '+00:00', ?)) sale_date", [$offset])
            ->selectRaw('COALESCE(SUM(total), 0) total, COUNT(*) orders_count')->groupBy('sale_date')->get()->keyBy('sale_date');

        return collect(range(29, 0))->map(function (int $daysAgo) use ($period, $rows): array {
            $date = $period['today']->subDays($daysAgo)->toDateString();
            $row = $rows->get($date);

            return ['date' => $date, 'total' => (int) ($row?->total ?? 0), 'orders_count' => (int) ($row?->orders_count ?? 0)];
        })->all();
    }

    private function recentOrders(?Branch $branch): array
    {
        return $this->validOrders($branch)->select(['id', 'branch_id', 'order_number', 'customer_name', 'vehicle_brand_name', 'vehicle_model_name', 'vehicle_version_name', 'vehicle_year', 'total', 'status', 'payment_status', 'origin', 'confirmed_at'])
            ->with('branch:id,code,name')->withCount('items')->orderByDesc('confirmed_at')->orderByDesc('id')->limit(self::LIST_LIMIT)->get()
            ->map(fn (Order $order): array => [
                'id' => $order->id, 'order_number' => $order->order_number, 'customer_name' => $order->customer_name ?: 'Venta mostrador', 'vehicle_summary' => $this->vehicleSummary($order),
                'total' => $order->total, 'status' => $order->status, 'payment_status' => $order->payment_status, 'origin' => $order->origin,
                'confirmed_at' => $order->confirmed_at?->toIso8601String(), 'items_count' => $order->items_count,
                'branch' => $order->branch ? $this->branchPayload($order->branch) : null,
            ])->all();
    }

    private function payments(array $period, ?Branch $branch): array
    {
        $completed = Payment::query()->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('payments.status', Payment::STATUS_COMPLETED)->whereNotNull('payments.paid_at')
            ->when($branch, fn (Builder $query, Branch $selected) => $query->where('orders.branch_id', $selected->id));
        $balance = $this->receivablesQuery($branch)->selectRaw('COALESCE(SUM(GREATEST(orders.total - COALESCE(completed_payments.paid_amount, 0), 0)), 0) outstanding')
            ->selectRaw('SUM(CASE WHEN orders.total > COALESCE(completed_payments.paid_amount, 0) THEN 1 ELSE 0 END) orders_with_balance')->first();

        return [
            'received_today' => (int) (clone $completed)->where('payments.paid_at', '>=', $period['day'][0])->where('payments.paid_at', '<', $period['day'][1])->sum('payments.amount'),
            'received_month' => (int) (clone $completed)->where('payments.paid_at', '>=', $period['month'][0])->where('payments.paid_at', '<', $period['month'][1])->sum('payments.amount'),
            'outstanding' => (int) ($balance->outstanding ?? 0), 'orders_with_balance' => (int) ($balance->orders_with_balance ?? 0),
        ];
    }

    private function receivablesQuery(?Branch $branch = null): Builder
    {
        $paid = Payment::query()->where('status', Payment::STATUS_COMPLETED)->selectRaw('order_id, SUM(amount) paid_amount')->groupBy('order_id');

        return $this->validOrders($branch)->leftJoinSub($paid, 'completed_payments', 'orders.id', '=', 'completed_payments.order_id');
    }

    private function quotations(array $period, ?Branch $branch): array
    {
        $resolved = $this->resolvedQuotations($period, $branch);
        $resolvedCount = (clone $resolved)->count();
        $convertedCount = (clone $resolved)->where('status', Quotation::STATUS_CONVERTED)->count();

        return [
            'open' => $this->openQuotations($period, $branch)->count(), 'expiring_soon' => $this->expiringQuotationsQuery($period, $branch)->count(),
            'converted_month' => Quotation::query()->where('status', Quotation::STATUS_CONVERTED)->whereNotNull('order_id')
                ->where('converted_at', '>=', $period['month'][0])->where('converted_at', '<', $period['month'][1])
                ->when($branch, fn (Builder $query, Branch $selected) => $query->where('branch_id', $selected->id))->count(),
            'conversion_rate' => $resolvedCount > 0 ? round($convertedCount / $resolvedCount * 100, 2) : null,
            'conversion_definition' => 'resolved_quotations',
        ];
    }

    private function resolvedQuotations(array $period, ?Branch $branch = null): Builder
    {
        return Quotation::query()->when($branch, fn (Builder $query, Branch $selected) => $query->where('branch_id', $selected->id))
            ->where(function (Builder $query) use ($period) {
                $query->whereIn('status', [Quotation::STATUS_CONVERTED, Quotation::STATUS_REJECTED, Quotation::STATUS_EXPIRED])
                    ->orWhere(fn (Builder $expired) => $expired->whereIn('status', [Quotation::STATUS_DRAFT, Quotation::STATUS_SENT])->whereNotNull('valid_until')->whereDate('valid_until', '<', $period['today']->toDateString()));
            });
    }

    private function openQuotations(array $period, ?Branch $branch): Builder
    {
        return Quotation::query()->whereIn('status', [Quotation::STATUS_DRAFT, Quotation::STATUS_SENT])->whereNull('order_id')
            ->when($branch, fn (Builder $query, Branch $selected) => $query->where('branch_id', $selected->id))
            ->where(fn (Builder $query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $period['today']->toDateString()));
    }

    private function expiringQuotationsQuery(array $period, ?Branch $branch): Builder
    {
        return $this->openQuotations($period, $branch)->whereBetween('valid_until', [$period['today']->toDateString(), $period['today']->addDays(3)->toDateString()]);
    }

    private function expiringQuotations(array $period, ?Branch $branch): array
    {
        return $this->expiringQuotationsQuery($period, $branch)
            ->select(['id', 'branch_id', 'quotation_number', 'customer_name', 'vehicle_brand_name', 'vehicle_model_name', 'vehicle_version_name', 'vehicle_year', 'total', 'status', 'valid_until', 'created_at'])
            ->with('branch:id,code,name')->orderBy('valid_until')->orderBy('created_at')->orderBy('id')->limit(self::LIST_LIMIT)->get()
            ->map(fn (Quotation $quotation): array => [
                'id' => $quotation->id, 'quotation_number' => $quotation->quotation_number, 'customer_name' => $quotation->customer_name ?: 'Cotización mostrador',
                'vehicle_summary' => $this->vehicleSummary($quotation), 'total' => $quotation->total, 'status' => $quotation->status,
                'valid_until' => $quotation->valid_until?->toDateString(), 'branch' => $quotation->branch ? $this->branchPayload($quotation->branch) : null,
            ])->all();
    }

    private function customers(array $period, ?Branch $branch): array
    {
        $buyers = Customer::query()->whereHas('orders', fn (Builder $query) => $query->whereIn('status', self::VALID_ORDER_STATUSES)
            ->where('confirmed_at', '>=', $period['month'][0])->where('confirmed_at', '<', $period['month'][1])
            ->when($branch, fn (Builder $orders, Branch $selected) => $orders->where('branch_id', $selected->id)))->count();

        return [
            'total' => Customer::query()->count(), 'active' => Customer::query()->where('is_active', true)->count(),
            'new_month' => Customer::query()->where('created_at', '>=', $period['month'][0])->where('created_at', '<', $period['month'][1])->count(),
            'buyers_month' => $buyers, 'customers_served_month' => $buyers, 'registration_scope' => 'global',
        ];
    }

    private function inventorySummary(?Branch $branch): array
    {
        $stocks = $this->inventoryStocks($branch);

        return [
            'low_stock_count' => (clone $stocks)->where('inventory_stocks.quantity', '>', 0)->whereColumn('inventory_stocks.quantity', '<=', 'inventory_stocks.minimum_quantity')->count(),
            'out_of_stock_count' => (clone $stocks)->where('inventory_stocks.quantity', '<=', 0)->count(),
            'total_quantity' => (int) (clone $stocks)->sum('inventory_stocks.quantity'),
        ];
    }

    private function inventoryStocks(?Branch $branch = null): QueryBuilder
    {
        return DB::table('inventory_stocks')->join('branches', 'branches.id', '=', 'inventory_stocks.branch_id')
            ->join('inventory_items', 'inventory_items.id', '=', 'inventory_stocks.inventory_item_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'inventory_items.product_variant_id')
            ->join('products', 'products.id', '=', DB::raw('COALESCE(inventory_items.product_id, product_variants.product_id)'))
            ->where('products.is_active', true)
            ->where(fn (QueryBuilder $query) => $query
                ->where(fn (QueryBuilder $simple) => $simple->whereNotNull('inventory_items.product_id')->whereNotExists(fn (QueryBuilder $variants) => $variants->selectRaw('1')->from('product_variants as any_variant')->whereColumn('any_variant.product_id', 'inventory_items.product_id')))
                ->orWhere(fn (QueryBuilder $variant) => $variant->whereNotNull('inventory_items.product_variant_id')->where('product_variants.is_active', true)))
            ->when($branch, fn (QueryBuilder $query, Branch $selected) => $query->where('inventory_stocks.branch_id', $selected->id));
    }

    private function lowStock(?Branch $branch): array
    {
        return $this->inventoryStocks($branch)->whereColumn('inventory_stocks.quantity', '<=', 'inventory_stocks.minimum_quantity')
            ->select(['inventory_items.product_id', 'inventory_items.product_variant_id', 'products.name', 'products.id as resolved_product_id', 'product_variants.name as variant_name', 'branches.id as branch_id', 'branches.code as branch_code', 'branches.name as branch_name'])
            ->selectRaw("CASE WHEN inventory_items.product_variant_id IS NULL THEN 'product' ELSE 'variant' END type")
            ->selectRaw('COALESCE(product_variants.sku, products.sku) sku, inventory_stocks.quantity stock, inventory_stocks.minimum_quantity minimum_stock')
            ->orderByRaw('CASE WHEN inventory_stocks.quantity <= 0 THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN inventory_stocks.minimum_quantity > 0 THEN inventory_stocks.quantity / inventory_stocks.minimum_quantity ELSE inventory_stocks.quantity END')
            ->orderBy('branches.id')->orderBy('inventory_items.id')->limit(self::LIST_LIMIT)->get()
            ->map(fn ($item): array => [
                'type' => $item->type, 'product_id' => (int) $item->resolved_product_id,
                'product_variant_id' => $item->product_variant_id === null ? null : (int) $item->product_variant_id, 'name' => $item->name,
                'sku' => $item->sku, 'variant_name' => $item->variant_name, 'stock' => (int) $item->stock, 'minimum_stock' => (int) $item->minimum_stock,
                'status' => (int) $item->stock <= 0 ? 'out' : 'low', 'branch' => ['id' => (int) $item->branch_id, 'code' => $item->branch_code, 'name' => $item->branch_name],
            ])->all();
    }

    private function topProducts(array $period, ?Branch $branch): array
    {
        $key = "CASE WHEN order_items.product_id IS NOT NULL THEN CONCAT('id:', order_items.product_id) ELSE CONCAT('snapshot:', COALESCE(order_items.product_sku, ''), ':', order_items.product_name) END";

        return DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->where('order_items.item_type', 'product')
            ->whereIn('orders.status', self::VALID_ORDER_STATUSES)->where('orders.confirmed_at', '>=', $period['month'][0])->where('orders.confirmed_at', '<', $period['month'][1])
            ->when($branch, fn (QueryBuilder $query, Branch $selected) => $query->where('orders.branch_id', $selected->id))
            ->selectRaw("{$key} product_key")->selectRaw('MAX(order_items.product_id) product_id, MAX(order_items.product_name) product_name, MAX(order_items.product_sku) product_sku')
            ->selectRaw('SUM(order_items.quantity) quantity, SUM(order_items.total) revenue')->groupByRaw($key)->orderByDesc('quantity')->orderBy('product_key')->limit(self::LIST_LIMIT)->get()
            ->map(fn ($item): array => ['product_id' => $item->product_id !== null ? (int) $item->product_id : null, 'product_name' => $item->product_name, 'product_sku' => $item->product_sku, 'quantity' => (int) $item->quantity, 'revenue' => (int) $item->revenue])->all();
    }

    private function financials(array $period, ?Branch $branch): array
    {
        $row = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->whereIn('orders.status', self::VALID_ORDER_STATUSES)
            ->where('orders.confirmed_at', '>=', $period['month'][0])->where('orders.confirmed_at', '<', $period['month'][1])
            ->when($branch, fn (QueryBuilder $query, Branch $selected) => $query->where('orders.branch_id', $selected->id))
            ->selectRaw('COALESCE(SUM(order_items.total), 0) revenue')->selectRaw('COALESCE(SUM(CASE WHEN order_items.unit_cost IS NOT NULL THEN order_items.total ELSE 0 END), 0) known_revenue')
            ->selectRaw('COALESCE(SUM(CASE WHEN order_items.unit_cost IS NOT NULL THEN order_items.unit_cost * order_items.quantity ELSE 0 END), 0) cogs')->first();
        $revenue = (int) $row->revenue;
        $knownRevenue = (int) $row->known_revenue;
        $cogs = (int) $row->cogs;
        $profit = $knownRevenue - $cogs;

        return [
            'revenue_month' => $revenue, 'known_cost_revenue_month' => $knownRevenue, 'cogs_month' => $cogs,
            'gross_profit_on_known_cost_month' => $profit, 'gross_margin_percent' => $knownRevenue > 0 ? round($profit / $knownRevenue * 100, 2) : null,
            'cost_coverage_percent' => $revenue > 0 ? round($knownRevenue / $revenue * 100, 2) : 0,
        ];
    }

    private function branchPayload(Branch $branch): array
    {
        return ['id' => (int) $branch->id, 'code' => $branch->code, 'name' => $branch->name];
    }

    private function vehicleSummary(Order|Quotation $record): ?string
    {
        return Collection::make([$record->vehicle_brand_name, $record->vehicle_model_name, $record->vehicle_version_name, $record->vehicle_year])
            ->filter(fn ($value) => $value !== null && $value !== '')->implode(' ') ?: null;
    }
}
