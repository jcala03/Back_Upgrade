<?php

namespace App\Services\Reports;

use App\Models\Quotation;
use App\Models\QuotationStatusHistory;
use App\Support\Business\BusinessContext;
use App\Support\Reports\ReportPeriod;
use App\Support\Reports\ReportScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class QuotationReportService
{
    public function report(array $filters): array
    {
        $period = ReportPeriod::from($filters);
        $today = CarbonImmutable::now(BusinessContext::TIMEZONE)->toDateString();
        $query = $this->quotationsQuery($filters, $period, $today);
        $rows = (clone $query)->select(['id', 'branch_id', 'sales_employee_id', 'quotation_number', 'created_at', 'valid_until', 'status', 'customer_id', 'customer_name', 'vehicle_brand_name', 'vehicle_model_name', 'vehicle_version_name', 'vehicle_year', 'subtotal', 'discount_total', 'total', 'created_by', 'converted_at', 'converted_by', 'order_id'])
            ->with(['creator:id,name', 'order:id,order_number', 'branch:id,code,name', 'salesEmployee:id,name'])
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25))->withQueryString()->through(fn (Quotation $quotation) => $this->row($quotation, $today));
        $created = Quotation::query()->where('created_at', '>=', $period->from)->where('created_at', '<', $period->toExclusive)
            ->when($filters['branch_id'] ?? null, fn (Builder $builder, int $id) => $builder->where('branch_id', $id));
        $sent = QuotationStatusHistory::query()->where('to_status', Quotation::STATUS_SENT)
            ->where('quotation_status_histories.created_at', '>=', $period->from)->where('quotation_status_histories.created_at', '<', $period->toExclusive)
            ->when($filters['branch_id'] ?? null, fn (Builder $builder, int $id) => $builder->whereHas('quotation', fn (Builder $quotation) => $quotation->where('branch_id', $id)))->count();
        $rejected = QuotationStatusHistory::query()->where('to_status', Quotation::STATUS_REJECTED)
            ->where('quotation_status_histories.created_at', '>=', $period->from)->where('quotation_status_histories.created_at', '<', $period->toExclusive)
            ->when($filters['branch_id'] ?? null, fn (Builder $builder, int $id) => $builder->whereHas('quotation', fn (Builder $quotation) => $quotation->where('branch_id', $id)))->count();
        $converted = Quotation::query()->whereNotNull('order_id')->where('converted_at', '>=', $period->from)->where('converted_at', '<', $period->toExclusive)
            ->when($filters['branch_id'] ?? null, fn (Builder $builder, int $id) => $builder->where('branch_id', $id));
        $expired = Quotation::query()->whereIn('status', [Quotation::STATUS_DRAFT, Quotation::STATUS_SENT, Quotation::STATUS_EXPIRED])->whereNull('order_id')
            ->whereBetween('valid_until', [$period->dateFrom, $period->dateTo])->where('valid_until', '<', $today)
            ->when($filters['branch_id'] ?? null, fn (Builder $builder, int $id) => $builder->where('branch_id', $id));
        $convertedCount = (clone $converted)->count();
        $expiredCount = (clone $expired)->count();
        $resolved = $convertedCount + $rejected + $expiredCount;

        return [
            'context' => ReportScope::response($filters), 'period' => $period->response(),
            'summary' => [
                'created_in_period' => (clone $created)->count(), 'sent_in_period' => $sent, 'converted_in_period' => $convertedCount,
                'rejected_in_period' => $rejected, 'expired_in_period' => $expiredCount, 'resolved_in_period' => $resolved,
                'conversion_rate_resolved' => $resolved ? round($convertedCount / $resolved * 100, 2) : null,
                'total_quoted_created_in_period' => (int) (clone $created)->sum('total'), 'total_converted_in_period' => (int) (clone $converted)->sum('total'),
            ],
            'rows' => $rows,
        ];
    }

    public function exportQuery(array $filters): Builder
    {
        $period = ReportPeriod::from($filters);
        $today = CarbonImmutable::now(BusinessContext::TIMEZONE)->toDateString();

        return $this->quotationsQuery($filters, $period, $today)
            ->leftJoin('branches', 'branches.id', '=', 'quotations.branch_id')
            ->leftJoin('employees', 'employees.id', '=', 'quotations.sales_employee_id')
            ->leftJoin('users', 'users.id', '=', 'quotations.created_by')
            ->leftJoin('orders', 'orders.id', '=', 'quotations.order_id')
            ->select(['quotations.id', 'quotations.quotation_number', 'quotations.created_at', 'quotations.valid_until', 'quotations.status', 'quotations.customer_name', 'quotations.subtotal', 'quotations.discount_total', 'quotations.total', 'quotations.converted_at', 'branches.name as branch_name', 'employees.name as seller_name', 'users.name as operator_name', 'orders.order_number'])
            ->orderBy('quotations.created_at')->orderBy('quotations.id');
    }

    private function quotationsQuery(array $filters, ReportPeriod $period, string $today): Builder
    {
        $query = Quotation::query()->where('quotations.created_at', '>=', $period->from)->where('quotations.created_at', '<', $period->toExclusive)
            ->when($filters['branch_id'] ?? null, fn (Builder $builder, int $id) => $builder->where('quotations.branch_id', $id))
            ->when($filters['customer_id'] ?? null, fn (Builder $builder, int $id) => $builder->where('quotations.customer_id', $id))
            ->when($filters['created_by'] ?? null, fn (Builder $builder, int $id) => $builder->where('quotations.created_by', $id))
            ->when(($filters['conversion'] ?? 'all') === 'converted', fn (Builder $builder) => $builder->whereNotNull('quotations.order_id'))
            ->when(($filters['conversion'] ?? 'all') === 'not_converted', fn (Builder $builder) => $builder->whereNull('quotations.order_id'))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', function (Builder $builder) use ($filters) {
                $search = '%'.trim($filters['search']).'%';
                $builder->where(fn (Builder $nested) => $nested->where('quotations.quotation_number', 'like', $search)->orWhere('quotations.customer_name', 'like', $search));
            });
        if (isset($filters['status'])) {
            $this->effectiveStatusFilter($query, $filters['status'], $today);
        }

        return $query;
    }

    private function effectiveStatusFilter(Builder $query, string $status, string $today): void
    {
        if ($status === Quotation::STATUS_EXPIRED) {
            $query->where(fn (Builder $builder) => $builder->where('quotations.status', Quotation::STATUS_EXPIRED)
                ->orWhere(fn (Builder $expired) => $expired->whereIn('quotations.status', [Quotation::STATUS_DRAFT, Quotation::STATUS_SENT])->whereNotNull('valid_until')->where('valid_until', '<', $today)));

            return;
        }
        if (in_array($status, [Quotation::STATUS_DRAFT, Quotation::STATUS_SENT], true)) {
            $query->where('quotations.status', $status)->where(fn (Builder $builder) => $builder->whereNull('valid_until')->orWhere('valid_until', '>=', $today));

            return;
        }
        $query->where('quotations.status', $status);
    }

    private function row(Quotation $quotation, string $today): array
    {
        return [
            'id' => $quotation->id, 'quotation_number' => $quotation->quotation_number, 'created_at' => $quotation->created_at?->toIso8601String(),
            'valid_until' => $quotation->valid_until?->toDateString(), 'status' => $this->effectiveStatus($quotation, $today),
            'customer_id' => $quotation->customer_id, 'customer_name' => $quotation->customer_name ?: 'Cotización mostrador',
            'vehicle_summary' => $this->vehicle($quotation), 'total' => $quotation->total, 'created_by' => $quotation->created_by,
            'operator_name' => $quotation->creator?->name, 'sales_employee_id' => $quotation->sales_employee_id, 'seller_name' => $quotation->salesEmployee?->name,
            'converted_at' => $quotation->converted_at?->toIso8601String(), 'converted_by' => $quotation->converted_by,
            'order_id' => $quotation->order_id, 'order_number' => $quotation->order?->order_number,
            'branch' => $quotation->branch ? ['id' => (int) $quotation->branch->id, 'code' => $quotation->branch->code, 'name' => $quotation->branch->name] : null,
        ];
    }

    private function effectiveStatus(Quotation $quotation, string $today): string
    {
        return in_array($quotation->status, [Quotation::STATUS_DRAFT, Quotation::STATUS_SENT], true)
            && $quotation->valid_until !== null && $quotation->valid_until->toDateString() < $today
                ? Quotation::STATUS_EXPIRED
                : $quotation->status;
    }

    private function vehicle(Quotation $quotation): ?string
    {
        return collect([$quotation->vehicle_brand_name, $quotation->vehicle_model_name, $quotation->vehicle_version_name, $quotation->vehicle_year])->filter(fn ($value) => $value !== null && $value !== '')->implode(' ') ?: null;
    }
}
