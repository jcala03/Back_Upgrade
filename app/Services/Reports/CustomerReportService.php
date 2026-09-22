<?php

namespace App\Services\Reports;

use App\Models\Customer;
use App\Models\Order;
use App\Support\Reports\ReportPeriod;
use App\Support\Reports\ReportScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class CustomerReportService
{
    public function report(array $filters): array
    {
        $period = ReportPeriod::from($filters);
        $sales = $this->customerSalesQuery($filters, $period);
        $query = $this->customersQuery($filters, $sales);
        $buyers = DB::query()->fromSub(clone $sales, 'buyers')->count();
        $summary = [
            'registered_total' => Customer::query()->count(),
            'active_total' => Customer::query()->where('is_active', true)->count(),
            'new_in_period' => Customer::query()->where('created_at', '>=', $period->from)->where('created_at', '<', $period->toExclusive)->count(),
            'buyers_in_period' => $buyers,
            'customers_served' => $buyers,
            'registration_scope' => 'global',
        ];
        $sort = $filters['sort'] ?? 'total_spent';
        $rows = (clone $query)->orderBy($sort, $filters['direction'] ?? 'desc')->orderBy('customers.id')
            ->paginate((int) ($filters['per_page'] ?? 25))->withQueryString()->through(fn ($row) => $this->row($row));

        return [
            'context' => ReportScope::response($filters), 'period' => $period->response(), 'summary' => $summary,
            'rows' => $rows, 'purchase_metrics_scope' => 'selected_period',
            'branch_semantics' => isset($filters['branch_id']) ? 'customers_with_commercial_activity' : 'global_customer_registry',
        ];
    }

    public function exportQuery(array $filters): Builder
    {
        $period = ReportPeriod::from($filters);

        return $this->customersQuery($filters, $this->customerSalesQuery($filters, $period))
            ->orderBy('customers.id');
    }

    private function customerSalesQuery(array $filters, ReportPeriod $period): QueryBuilder
    {
        return DB::table('orders')->whereIn('status', [Order::STATUS_CONFIRMED, Order::STATUS_COMPLETED])->whereNotNull('customer_id')
            ->where('confirmed_at', '>=', $period->from)->where('confirmed_at', '<', $period->toExclusive)
            ->when($filters['branch_id'] ?? null, fn (QueryBuilder $query, int $id) => $query->where('branch_id', $id))
            ->selectRaw('customer_id,COUNT(*) orders_count,SUM(total) total_spent,MAX(confirmed_at) last_purchase_at')->groupBy('customer_id');
    }

    private function customersQuery(array $filters, QueryBuilder $sales): Builder
    {
        return Customer::query()->leftJoinSub($sales, 'sales', 'customers.id', '=', 'sales.customer_id')
            ->select(['customers.id', 'customers.id as customer_id', 'customers.name', 'customers.is_active', 'customers.created_at'])
            ->selectRaw('COALESCE(sales.orders_count,0) orders_count, COALESCE(sales.total_spent,0) total_spent, sales.last_purchase_at')
            ->withCount('vehicles')
            ->when(isset($filters['branch_id']), fn (Builder $query) => $query->whereNotNull('sales.customer_id'))
            ->when(array_key_exists('is_active', $filters), fn (Builder $query) => $query->where('customers.is_active', (bool) $filters['is_active']))
            ->when(($filters['buyer'] ?? 'all') === 'yes', fn (Builder $query) => $query->whereNotNull('sales.customer_id'))
            ->when(($filters['buyer'] ?? 'all') === 'no', fn (Builder $query) => $query->whereNull('sales.customer_id'))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', function (Builder $query) use ($filters) {
                $search = '%'.trim($filters['search']).'%';
                $query->where(fn (Builder $nested) => $nested->where('customers.name', 'like', $search)->orWhere('customers.phone', 'like', $search)->orWhere('customers.email', 'like', $search)->orWhere('customers.document', 'like', $search));
            });
    }

    private function row(object $row): array
    {
        return [
            'customer_id' => (int) $row->customer_id, 'name' => $row->name, 'is_active' => (bool) $row->is_active,
            'created_at' => $row->created_at?->toIso8601String(), 'orders_count' => (int) $row->orders_count,
            'total_spent' => (int) $row->total_spent, 'average_ticket' => (int) $row->orders_count ? round((int) $row->total_spent / (int) $row->orders_count, 2) : 0,
            'last_purchase_at' => $row->last_purchase_at, 'vehicles_count' => (int) $row->vehicles_count,
        ];
    }
}
