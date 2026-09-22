<?php

namespace App\Services\Reports;

use App\Models\Order;
use App\Models\Payment;
use App\Support\Business\BusinessContext;
use App\Support\Reports\ReportPeriod;
use App\Support\Reports\ReportScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PaymentReportService
{
    public function payments(array $filters): array
    {
        $period = ReportPeriod::from($filters);
        $query = $this->paymentsQuery($filters, $period);
        $completed = (clone $query)->where('payments.status', Payment::STATUS_COMPLETED);
        $sum = (clone $completed)->selectRaw('COALESCE(SUM(payments.amount),0) completed_amount, COUNT(*) completed_count')->first();
        $methods = (clone $completed)->selectRaw('payments.method, SUM(payments.amount) amount, COUNT(*) count')->groupBy('payments.method')->get()
            ->mapWithKeys(fn ($row) => [$row->method => ['amount' => (int) $row->amount, 'count' => (int) $row->count]])->all();
        $rows = $this->paymentsRowsQuery($filters, $period)
            ->orderBy('payments.'.($filters['sort'] ?? 'paid_at'), $filters['direction'] ?? 'desc')->orderByDesc('payments.id')
            ->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();

        return [
            'context' => ReportScope::response($filters), 'period' => $period->response(),
            'summary' => ['completed_amount' => (int) $sum->completed_amount, 'completed_count' => (int) $sum->completed_count, 'by_method' => $methods],
            'rows' => $rows,
        ];
    }

    public function receivables(array $filters): array
    {
        $period = isset($filters['date_from'], $filters['date_to']) ? ReportPeriod::from($filters) : null;
        $query = $this->receivablesQuery($filters, $period);
        $summary = DB::query()->fromSub(clone $query, 'balances')->selectRaw('COALESCE(SUM(outstanding),0) outstanding_total, COUNT(*) orders_with_balance')->first();
        $sort = $filters['sort'] ?? 'outstanding';
        $column = $sort === 'days_outstanding' ? 'confirmed_at' : $sort;
        $direction = $filters['direction'] ?? 'desc';
        if ($sort === 'days_outstanding') {
            $direction = $direction === 'desc' ? 'asc' : 'desc';
        }
        $today = CarbonImmutable::now(BusinessContext::TIMEZONE)->startOfDay();
        $rows = (clone $query)->orderBy($column, $direction)->orderByDesc('orders.id')
            ->paginate((int) ($filters['per_page'] ?? 25))->withQueryString()->through(fn ($row) => $this->receivableRow($row, $today));

        return [
            'context' => ReportScope::response($filters), ...($period ? ['period' => $period->response()] : []),
            'summary' => ['outstanding_total' => (int) $summary->outstanding_total, 'orders_with_balance' => (int) $summary->orders_with_balance],
            'rows' => $rows,
        ];
    }

    public function paymentsExportQuery(array $filters): Builder
    {
        return $this->paymentsRowsQuery($filters, ReportPeriod::from($filters))->orderBy('payments.paid_at')->orderBy('payments.id');
    }

    public function receivablesExportQuery(array $filters): Builder
    {
        $period = isset($filters['date_from'], $filters['date_to']) ? ReportPeriod::from($filters) : null;

        return $this->receivablesQuery($filters, $period)->orderBy('orders.confirmed_at')->orderBy('orders.id');
    }

    private function paymentsQuery(array $filters, ReportPeriod $period): Builder
    {
        return Payment::query()->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('payments.paid_at', '>=', $period->from)->where('payments.paid_at', '<', $period->toExclusive)
            ->when($filters['branch_id'] ?? null, fn (Builder $query, int $id) => $query->where('orders.branch_id', $id))
            ->when($filters['method'] ?? null, fn (Builder $query, string $value) => $query->where('payments.method', $value))
            ->when($filters['status'] ?? null, fn (Builder $query, string $value) => $query->where('payments.status', $value))
            ->when($filters['order_id'] ?? null, fn (Builder $query, int $id) => $query->where('payments.order_id', $id))
            ->when($filters['created_by'] ?? null, fn (Builder $query, int $id) => $query->where('payments.created_by', $id))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', function (Builder $query) use ($filters) {
                $search = '%'.trim($filters['search']).'%';
                $query->where(fn (Builder $nested) => $nested->where('orders.order_number', 'like', $search)->orWhere('payments.reference', 'like', $search));
            });
    }

    private function paymentsRowsQuery(array $filters, ReportPeriod $period): Builder
    {
        return $this->paymentsQuery($filters, $period)->leftJoin('users', 'users.id', '=', 'payments.created_by')
            ->leftJoin('branches', 'branches.id', '=', 'orders.branch_id')
            ->select(['payments.id', 'payments.paid_at', 'payments.order_id', 'orders.order_number', 'orders.customer_name', 'orders.branch_id', 'branches.code as branch_code', 'branches.name as branch_name', 'payments.amount', 'payments.method', 'payments.status', 'payments.provider', 'payments.reference', 'payments.created_by', 'users.name as operator_name']);
    }

    private function receivablesQuery(array $filters, ?ReportPeriod $period): Builder
    {
        $paid = Payment::query()->where('status', Payment::STATUS_COMPLETED)->selectRaw('order_id,SUM(amount) paid')->groupBy('order_id');
        $query = Order::query()->leftJoinSub($paid, 'paid', 'orders.id', '=', 'paid.order_id')
            ->leftJoin('users', 'users.id', '=', 'orders.created_by')->leftJoin('branches', 'branches.id', '=', 'orders.branch_id')
            ->whereIn('orders.status', [Order::STATUS_CONFIRMED, Order::STATUS_COMPLETED])->whereNotNull('orders.confirmed_at')
            ->select(['orders.id as order_id', 'orders.order_number', 'orders.branch_id', 'branches.code as branch_code', 'branches.name as branch_name', 'orders.customer_id', 'orders.customer_name', 'orders.total', 'orders.payment_status', 'orders.confirmed_at', 'orders.created_by', 'users.name as operator_name'])
            ->selectRaw('COALESCE(paid.paid,0) paid')->selectRaw('GREATEST(orders.total-COALESCE(paid.paid,0),0) outstanding')
            ->whereRaw('orders.total > COALESCE(paid.paid,0)')
            ->when($filters['branch_id'] ?? null, fn (Builder $builder, int $id) => $builder->where('orders.branch_id', $id))
            ->when($filters['customer_id'] ?? null, fn (Builder $builder, int $id) => $builder->where('orders.customer_id', $id))
            ->when($filters['created_by'] ?? null, fn (Builder $builder, int $id) => $builder->where('orders.created_by', $id))
            ->when($filters['balance_status'] ?? null, fn (Builder $builder, string $value) => $value === 'unpaid' ? $builder->whereRaw('COALESCE(paid.paid,0)=0') : $builder->whereRaw('COALESCE(paid.paid,0)>0'))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', function (Builder $builder) use ($filters) {
                $search = '%'.trim($filters['search']).'%';
                $builder->where(fn (Builder $nested) => $nested->where('orders.order_number', 'like', $search)->orWhere('orders.customer_name', 'like', $search));
            });
        if ($period) {
            $query->where('orders.confirmed_at', '>=', $period->from)->where('orders.confirmed_at', '<', $period->toExclusive);
        }

        return $query;
    }

    public function receivableRow(object $row, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now(BusinessContext::TIMEZONE)->startOfDay();
        $date = CarbonImmutable::parse($row->confirmed_at)->setTimezone(BusinessContext::TIMEZONE)->startOfDay();

        return [
            'order_id' => (int) $row->order_id, 'order_number' => $row->order_number, 'branch_id' => $row->branch_id === null ? null : (int) $row->branch_id,
            'branch' => $row->branch_id === null ? null : ['id' => (int) $row->branch_id, 'code' => $row->branch_code, 'name' => $row->branch_name],
            'customer_id' => $row->customer_id, 'customer_name' => $row->customer_name ?: 'Venta mostrador', 'total' => (int) $row->total,
            'paid' => (int) $row->paid, 'outstanding' => (int) $row->outstanding, 'payment_status' => $row->payment_status,
            'confirmed_at' => CarbonImmutable::parse($row->confirmed_at)->toIso8601String(), 'days_outstanding' => (int) $date->diffInDays($today),
            'created_by' => $row->created_by, 'operator_name' => $row->operator_name,
        ];
    }
}
