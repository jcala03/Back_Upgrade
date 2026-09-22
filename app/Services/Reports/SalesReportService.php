<?php

namespace App\Services\Reports;

use App\Models\Order;
use App\Models\User;
use App\Support\Reports\ReportPeriod;
use App\Support\Reports\ReportScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class SalesReportService
{
    private const VALID_STATUSES = [Order::STATUS_CONFIRMED, Order::STATUS_COMPLETED];

    public function sales(array $filters, User $user): array
    {
        $period = ReportPeriod::from($filters);
        $query = $this->ordersQuery($filters, $period);
        $summaryRow = (clone $query)->selectRaw('COUNT(*) orders_count, COALESCE(SUM(total),0) sales_total, COALESCE(SUM(discount_total),0) discount_total')->first();
        $count = (int) $summaryRow->orders_count;
        $summary = ['orders_count' => $count, 'sales_total' => (int) $summaryRow->sales_total, 'average_ticket' => $count ? round((int) $summaryRow->sales_total / $count, 2) : 0, 'discount_total' => (int) $summaryRow->discount_total];
        $origin = (clone $query)->selectRaw('origin, COUNT(*) orders_count, COALESCE(SUM(total),0) total')->groupBy('origin')->get()->keyBy('origin');
        $rows = (clone $query)->select(['orders.id', 'orders.branch_id', 'orders.sales_employee_id', 'order_number', 'confirmed_at', 'origin', 'status', 'payment_status', 'customer_id', 'customer_name', 'vehicle_brand_name', 'vehicle_model_name', 'vehicle_version_name', 'vehicle_year', 'subtotal', 'discount_total', 'total', 'created_by', 'quotation_id'])
            ->with(['creator:id,name', 'branch:id,code,name', 'salesEmployee:id,name'])->withCount('items')
            ->orderBy('orders.'.($filters['sort'] ?? 'confirmed_at'), $filters['direction'] ?? 'desc')->orderByDesc('orders.id')
            ->paginate((int) ($filters['per_page'] ?? 25))->withQueryString()->through(fn (Order $order) => $this->salesRow($order));

        $response = [
            'context' => ReportScope::response($filters), 'period' => $period->response(), 'summary' => $summary,
            'breakdown' => [
                'crm' => ['orders_count' => (int) ($origin[Order::ORIGIN_CRM]?->orders_count ?? 0), 'total' => (int) ($origin[Order::ORIGIN_CRM]?->total ?? 0)],
                'ecommerce' => ['orders_count' => (int) ($origin[Order::ORIGIN_ECOMMERCE]?->orders_count ?? 0), 'total' => (int) ($origin[Order::ORIGIN_ECOMMERCE]?->total ?? 0)],
            ],
            'by_day' => $this->byDay($query, $period), 'rows' => $rows,
        ];
        if ($user->hasPermission('reports.financials')) {
            $response['financials'] = $this->financials($query);
        }

        return $response;
    }

    public function products(array $filters, User $user): array
    {
        $period = ReportPeriod::from($filters);
        $base = $this->productSalesQuery($filters, $period);
        $summaryRow = (clone $base)->selectRaw('COALESCE(SUM(quantity),0) units_sold, COALESCE(SUM(order_items.total),0) revenue, COALESCE(SUM(discount_amount),0) discount_total, COUNT(DISTINCT order_id) distinct_orders')->first();
        $groupBySku = ($filters['group_by'] ?? 'product') === 'sku';
        $rowsQuery = $this->productsRowsQuery($filters, $period);
        $rows = (clone $rowsQuery)->orderBy($filters['sort'] ?? 'quantity', $filters['direction'] ?? 'desc')->orderBy('product_key')
            ->paginate((int) ($filters['per_page'] ?? 25))->withQueryString()->through(fn ($row) => $this->productRow($row, $user, $groupBySku));
        $response = [
            'context' => ReportScope::response($filters), 'period' => $period->response(), 'group_by' => $groupBySku ? 'sku' : 'product',
            'summary' => ['units_sold' => (int) $summaryRow->units_sold, 'revenue' => (int) $summaryRow->revenue, 'discount_total' => (int) $summaryRow->discount_total, 'distinct_orders' => (int) $summaryRow->distinct_orders],
            'rows' => $rows,
        ];
        if ($user->hasPermission('reports.financials')) {
            $financial = (clone $base)->selectRaw('COALESCE(SUM(order_items.total),0) revenue, COALESCE(SUM(CASE WHEN unit_cost IS NOT NULL THEN order_items.total ELSE 0 END),0) known_revenue, COALESCE(SUM(CASE WHEN unit_cost IS NOT NULL THEN unit_cost*quantity ELSE 0 END),0) cogs')->first();
            $response['financials'] = $this->financialValues((int) $financial->revenue, (int) $financial->known_revenue, (int) $financial->cogs);
        }

        return $response;
    }

    public function salesExportQuery(array $filters): Builder
    {
        $period = ReportPeriod::from($filters);

        return $this->ordersQuery($filters, $period)
            ->leftJoin('branches', 'branches.id', '=', 'orders.branch_id')
            ->leftJoin('employees', 'employees.id', '=', 'orders.sales_employee_id')
            ->leftJoin('users', 'users.id', '=', 'orders.created_by')
            ->select(['orders.id', 'orders.order_number', 'orders.confirmed_at', 'branches.name as branch_name', 'employees.name as seller_name', 'users.name as operator_name', 'orders.customer_name', 'orders.origin', 'orders.status', 'orders.payment_status', 'orders.subtotal', 'orders.discount_total', 'orders.total'])
            ->selectSub(fn (QueryBuilder $items) => $items->from('order_items')->whereColumn('order_items.order_id', 'orders.id')->selectRaw('COALESCE(SUM(CASE WHEN unit_cost IS NOT NULL THEN unit_cost * quantity ELSE 0 END),0)'), 'cogs')
            ->selectSub(fn (QueryBuilder $items) => $items->from('order_items')->whereColumn('order_items.order_id', 'orders.id')->selectRaw('COALESCE(SUM(CASE WHEN unit_cost IS NOT NULL THEN total ELSE 0 END),0)'), 'known_cost_revenue')
            ->orderBy('orders.confirmed_at')->orderBy('orders.id');
    }

    public function productsExportQuery(array $filters): QueryBuilder
    {
        $period = ReportPeriod::from($filters);

        return $this->productsRowsQuery($filters, $period)->orderByDesc('quantity')->orderBy('product_key');
    }

    private function ordersQuery(array $filters, ReportPeriod $period): Builder
    {
        return Order::query()->whereIn('orders.status', self::VALID_STATUSES)->whereNotNull('orders.confirmed_at')
            ->where('orders.confirmed_at', '>=', $period->from)->where('orders.confirmed_at', '<', $period->toExclusive)
            ->when($filters['branch_id'] ?? null, fn (Builder $query, int $id) => $query->where('orders.branch_id', $id))
            ->when($filters['origin'] ?? null, fn (Builder $query, string $value) => $query->where('orders.origin', $value))
            ->when($filters['payment_status'] ?? null, fn (Builder $query, string $value) => $query->where('orders.payment_status', $value))
            ->when($filters['customer_id'] ?? null, fn (Builder $query, int $id) => $query->where('orders.customer_id', $id))
            ->when($filters['created_by'] ?? null, fn (Builder $query, int $id) => $query->where('orders.created_by', $id))
            ->when($filters['product_id'] ?? null, fn (Builder $query, int $id) => $query->whereHas('items', fn (Builder $items) => $items->where('product_id', $id)))
            ->when($filters['product_variant_id'] ?? null, fn (Builder $query, int $id) => $query->whereHas('items', fn (Builder $items) => $items->where('product_variant_id', $id)))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', function (Builder $query) use ($filters) {
                $search = '%'.trim($filters['search']).'%';
                $query->where(fn (Builder $nested) => $nested->where('orders.order_number', 'like', $search)->orWhere('orders.customer_name', 'like', $search)->orWhere('orders.vehicle_plate', 'like', $search));
            });
    }

    private function productSalesQuery(array $filters, ReportPeriod $period): QueryBuilder
    {
        return DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.item_type', 'product')->whereIn('orders.status', self::VALID_STATUSES)->whereNotNull('orders.confirmed_at')
            ->where('orders.confirmed_at', '>=', $period->from)->where('orders.confirmed_at', '<', $period->toExclusive)
            ->when($filters['branch_id'] ?? null, fn (QueryBuilder $query, int $id) => $query->where('orders.branch_id', $id))
            ->when($filters['origin'] ?? null, fn (QueryBuilder $query, string $value) => $query->where('orders.origin', $value))
            ->when($filters['product_id'] ?? null, fn (QueryBuilder $query, int $id) => $query->where('order_items.product_id', $id))
            ->when($filters['product_variant_id'] ?? null, fn (QueryBuilder $query, int $id) => $query->where('order_items.product_variant_id', $id))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', function (QueryBuilder $query) use ($filters) {
                $search = '%'.trim($filters['search']).'%';
                $query->where(fn (QueryBuilder $nested) => $nested
                    ->where('order_items.product_name', 'like', $search)
                    ->orWhere('order_items.product_sku', 'like', $search)
                    ->orWhere('order_items.variant_name', 'like', $search)
                    ->orWhere('order_items.variant_sku', 'like', $search));
            });
    }

    private function productsRowsQuery(array $filters, ReportPeriod $period): QueryBuilder
    {
        $base = $this->productSalesQuery($filters, $period);
        $groupBySku = ($filters['group_by'] ?? 'product') === 'sku';
        $key = $groupBySku
            ? "CASE WHEN order_items.product_variant_id IS NOT NULL THEN CONCAT('variant:',order_items.product_variant_id) WHEN order_items.product_id IS NOT NULL THEN CONCAT('product:',order_items.product_id) ELSE CONCAT('snapshot:',COALESCE(order_items.variant_sku,''),':',COALESCE(order_items.variant_name,''),':',COALESCE(order_items.product_sku,''),':',order_items.product_name) END"
            : "CASE WHEN order_items.product_id IS NOT NULL THEN CONCAT('product:',order_items.product_id) ELSE CONCAT('snapshot:',COALESCE(order_items.product_sku,''),':',order_items.product_name) END";
        $grouped = (clone $base)->selectRaw("{$key} product_key, MAX(order_items.product_id) product_id, MAX(order_items.product_variant_id) product_variant_id, MAX(product_name) product_name, MAX(product_sku) product_sku, MAX(variant_name) variant_name, MAX(variant_sku) variant_sku")
            ->selectRaw('SUM(quantity) quantity, COUNT(DISTINCT order_id) orders_count, SUM(order_items.total) revenue, SUM(discount_amount) discount_total')
            ->selectRaw('SUM(CASE WHEN unit_cost IS NOT NULL THEN order_items.total ELSE 0 END) known_cost_revenue, SUM(CASE WHEN unit_cost IS NOT NULL THEN unit_cost * quantity ELSE 0 END) cogs')
            ->groupByRaw($key);
        $stockKey = $groupBySku
            ? "CASE WHEN inventory_items.product_variant_id IS NOT NULL THEN CONCAT('variant:',inventory_items.product_variant_id) ELSE CONCAT('product:',inventory_items.product_id) END"
            : "CONCAT('product:',COALESCE(inventory_items.product_id,product_variants.product_id))";
        $stocks = DB::table('inventory_stocks')->join('inventory_items', 'inventory_items.id', '=', 'inventory_stocks.inventory_item_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'inventory_items.product_variant_id')
            ->when($filters['branch_id'] ?? null, fn (QueryBuilder $query, int $id) => $query->where('inventory_stocks.branch_id', $id))
            ->selectRaw("{$stockKey} product_key, SUM(inventory_stocks.quantity) stock")->groupByRaw($stockKey);

        return DB::query()->fromSub($grouped, 'products_report')->leftJoinSub($stocks, 'report_stock', 'report_stock.product_key', '=', 'products_report.product_key')
            ->select('products_report.*')->selectRaw('COALESCE(report_stock.stock,0) stock');
    }

    private function productRow(object $row, User $user, bool $groupBySku): array
    {
        $item = [
            'product_id' => $row->product_id === null ? null : (int) $row->product_id, 'product_name' => $row->product_name,
            'product_sku' => $row->product_sku, 'quantity' => (int) $row->quantity, 'orders_count' => (int) $row->orders_count,
            'revenue' => (int) $row->revenue, 'discount_total' => (int) $row->discount_total, 'stock' => (int) $row->stock,
        ];
        if ($groupBySku) {
            $item += ['product_variant_id' => $row->product_variant_id === null ? null : (int) $row->product_variant_id, 'variant_name' => $row->variant_name, 'variant_sku' => $row->variant_sku];
        }
        if ($user->hasPermission('reports.financials')) {
            $item['financials'] = $this->financialValues((int) $row->revenue, (int) $row->known_cost_revenue, (int) $row->cogs);
        }

        return $item;
    }

    private function salesRow(Order $order): array
    {
        return [
            'id' => $order->id, 'order_number' => $order->order_number, 'confirmed_at' => $order->confirmed_at?->toIso8601String(), 'origin' => $order->origin,
            'status' => $order->status, 'payment_status' => $order->payment_status, 'customer_id' => $order->customer_id,
            'customer_name' => $order->customer_name ?: 'Venta mostrador', 'vehicle_summary' => $this->vehicle($order), 'items_count' => $order->items_count,
            'subtotal' => $order->subtotal, 'discount_total' => $order->discount_total, 'total' => $order->total, 'created_by' => $order->created_by,
            'operator_name' => $order->creator?->name, 'sales_employee_id' => $order->sales_employee_id, 'seller_name' => $order->salesEmployee?->name,
            'quotation_id' => $order->quotation_id,
            'branch' => $order->branch ? ['id' => (int) $order->branch->id, 'code' => $order->branch->code, 'name' => $order->branch->name] : null,
        ];
    }

    private function byDay(Builder $query, ReportPeriod $period): array
    {
        $rows = (clone $query)->selectRaw("DATE(CONVERT_TZ(confirmed_at,'+00:00','-05:00')) report_date, COUNT(*) orders_count, SUM(total) total")->groupBy('report_date')->get()->keyBy('report_date');

        return collect($period->days())->map(fn ($date) => ['date' => $date, 'orders_count' => (int) ($rows[$date]?->orders_count ?? 0), 'total' => (int) ($rows[$date]?->total ?? 0)])->all();
    }

    private function financials(Builder $orders): array
    {
        $ids = (clone $orders)->select('orders.id');
        $row = DB::table('order_items')->whereIn('order_id', $ids)->selectRaw('COALESCE(SUM(total),0) revenue, COALESCE(SUM(CASE WHEN unit_cost IS NOT NULL THEN total ELSE 0 END),0) known_revenue, COALESCE(SUM(CASE WHEN unit_cost IS NOT NULL THEN unit_cost*quantity ELSE 0 END),0) cogs')->first();

        return $this->financialValues((int) $row->revenue, (int) $row->known_revenue, (int) $row->cogs);
    }

    private function financialValues(int $revenue, int $known, int $cogs): array
    {
        $profit = $known - $cogs;

        return ['revenue' => $revenue, 'known_cost_revenue' => $known, 'cogs' => $cogs, 'gross_profit_on_known_cost' => $profit, 'gross_margin_percent' => $known ? round($profit / $known * 100, 2) : null, 'cost_coverage_percent' => $revenue ? round($known / $revenue * 100, 2) : 0];
    }

    private function vehicle(Order $order): ?string
    {
        return collect([$order->vehicle_brand_name, $order->vehicle_model_name, $order->vehicle_version_name, $order->vehicle_year])->filter(fn ($value) => $value !== null && $value !== '')->implode(' ') ?: null;
    }
}
