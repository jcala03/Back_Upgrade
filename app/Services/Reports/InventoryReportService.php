<?php

namespace App\Services\Reports;

use App\Models\InventoryMovement;
use App\Models\User;
use App\Support\Reports\ReportPeriod;
use App\Support\Reports\ReportScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class InventoryReportService
{
    public function inventory(array $filters, User $user): array
    {
        $query = $this->inventoryRowsQuery($filters);
        $summary = DB::query()->fromSub(clone $query, 'inventory_report')
            ->selectRaw("COUNT(*) units_count, COALESCE(SUM(status='normal'),0) normal_count, COALESCE(SUM(status='low'),0) low_count, COALESCE(SUM(status='out'),0) out_count, COALESCE(SUM(stock),0) total_quantity, COUNT(DISTINCT CASE WHEN status IN ('low','out') THEN branch_id END) low_stock_branch_count")
            ->first();
        $rows = (clone $query)->orderBy($this->inventorySort($filters['sort'] ?? 'name'), $filters['direction'] ?? 'asc')
            ->orderBy('branch_id')->orderBy('inventory_item_id')->paginate((int) ($filters['per_page'] ?? 25))->withQueryString()
            ->through(fn ($row) => $this->inventoryRow($row, $user));

        return [
            'context' => ReportScope::response($filters),
            'summary' => [
                'units_count' => (int) $summary->units_count, 'normal_count' => (int) $summary->normal_count,
                'low_count' => (int) $summary->low_count, 'out_count' => (int) $summary->out_count,
                'total_quantity' => (int) $summary->total_quantity, 'low_stock_branch_count' => (int) $summary->low_stock_branch_count,
            ],
            'rows' => $rows, 'historical_reconciliation' => false, 'stock_authority' => 'inventory_stocks',
        ];
    }

    public function movements(array $filters): array
    {
        $period = ReportPeriod::from($filters);
        $query = $this->movementsQuery($filters, $period);
        $summary = (clone $query)->selectRaw('COUNT(*) movements_count, COALESCE(SUM(CASE WHEN quantity_delta>0 THEN quantity_delta ELSE 0 END),0) units_in, COALESCE(SUM(CASE WHEN quantity_delta<0 THEN -quantity_delta ELSE 0 END),0) units_out')->first();
        $types = (clone $query)->selectRaw('inventory_movements.type,COUNT(*) count')->groupBy('inventory_movements.type')->pluck('count', 'type')->map(fn ($value) => (int) $value)->all();
        $rows = $this->movementRowsQuery($filters, $period)
            ->orderBy('inventory_movements.'.($filters['sort'] ?? 'created_at'), $filters['direction'] ?? 'desc')->orderByDesc('inventory_movements.id')
            ->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();

        return [
            'context' => ReportScope::response($filters), 'period' => $period->response(),
            'summary' => ['movements_count' => (int) $summary->movements_count, 'units_in' => (int) $summary->units_in, 'units_out' => (int) $summary->units_out, 'by_type' => $types],
            'rows' => $rows, 'historical_reconciliation' => false,
        ];
    }

    public function inventoryExportQuery(array $filters): QueryBuilder
    {
        return $this->inventoryRowsQuery($filters)->orderBy('branch_id')->orderBy('inventory_item_id');
    }

    public function movementsExportQuery(array $filters): Builder
    {
        return $this->movementRowsQuery($filters, ReportPeriod::from($filters))->orderBy('inventory_movements.created_at')->orderBy('inventory_movements.id');
    }

    private function inventoryRowsQuery(array $filters): QueryBuilder
    {
        $query = DB::table('inventory_stocks')->join('branches', 'branches.id', '=', 'inventory_stocks.branch_id')
            ->join('inventory_items', 'inventory_items.id', '=', 'inventory_stocks.inventory_item_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'inventory_items.product_variant_id')
            ->join('products', 'products.id', '=', DB::raw('COALESCE(inventory_items.product_id, product_variants.product_id)'))
            ->where('products.is_active', true)
            ->where(fn (QueryBuilder $builder) => $builder
                ->where(fn (QueryBuilder $simple) => $simple->whereNotNull('inventory_items.product_id')->whereNotExists(fn (QueryBuilder $variants) => $variants->selectRaw('1')->from('product_variants as any_variant')->whereColumn('any_variant.product_id', 'inventory_items.product_id')))
                ->orWhere(fn (QueryBuilder $variant) => $variant->whereNotNull('inventory_items.product_variant_id')->where('product_variants.is_active', true)))
            ->when($filters['branch_id'] ?? null, fn (QueryBuilder $builder, int $id) => $builder->where('inventory_stocks.branch_id', $id))
            ->when($filters['product_id'] ?? null, fn (QueryBuilder $builder, int $id) => $builder->where('products.id', $id))
            ->when($filters['product_variant_id'] ?? null, fn (QueryBuilder $builder, int $id) => $builder->where('inventory_items.product_variant_id', $id))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', function (QueryBuilder $builder) use ($filters) {
                $search = '%'.trim($filters['search']).'%';
                $builder->where(fn (QueryBuilder $nested) => $nested->where('products.name', 'like', $search)->orWhere('products.sku', 'like', $search)->orWhere('product_variants.name', 'like', $search)->orWhere('product_variants.sku', 'like', $search));
            })
            ->select(['inventory_stocks.id', 'inventory_stocks.inventory_item_id', 'inventory_stocks.branch_id', 'branches.code as branch_code', 'branches.name as branch_name', 'products.id as product_id', 'inventory_items.product_variant_id', 'products.name', 'products.sku as product_sku', 'product_variants.name as variant_name', 'product_variants.sku as variant_sku', 'inventory_stocks.quantity as stock', 'inventory_stocks.minimum_quantity as minimum_stock'])
            ->selectRaw("CASE WHEN inventory_items.product_variant_id IS NULL THEN 'product' ELSE 'variant' END type")
            ->selectRaw("CASE WHEN inventory_stocks.quantity<=0 THEN 'out' WHEN inventory_stocks.quantity<=inventory_stocks.minimum_quantity THEN 'low' ELSE 'normal' END status")
            ->selectRaw('COALESCE(product_variants.sku,products.sku) sku, COALESCE(product_variants.price,products.price) sale_price, COALESCE(product_variants.total_cost,products.total_cost) current_cost');

        return match ($filters['status'] ?? 'all') {
            'normal' => $query->whereColumn('inventory_stocks.quantity', '>', 'inventory_stocks.minimum_quantity'),
            'low' => $query->where('inventory_stocks.quantity', '>', 0)->whereColumn('inventory_stocks.quantity', '<=', 'inventory_stocks.minimum_quantity'),
            'out' => $query->where('inventory_stocks.quantity', '<=', 0),
            default => $query,
        };
    }

    private function movementsQuery(array $filters, ReportPeriod $period): Builder
    {
        return InventoryMovement::query()->join('products', 'products.id', '=', 'inventory_movements.product_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'inventory_movements.product_variant_id')
            ->where('inventory_movements.created_at', '>=', $period->from)->where('inventory_movements.created_at', '<', $period->toExclusive)
            ->when($filters['branch_id'] ?? null, fn (Builder $builder, int $id) => $builder->where('inventory_movements.branch_id', $id))
            ->when($filters['type'] ?? null, fn (Builder $builder, string $value) => $builder->where('inventory_movements.type', $value))
            ->when($filters['product_id'] ?? null, fn (Builder $builder, int $id) => $builder->where('inventory_movements.product_id', $id))
            ->when($filters['product_variant_id'] ?? null, fn (Builder $builder, int $id) => $builder->where('inventory_movements.product_variant_id', $id))
            ->when($filters['created_by'] ?? null, fn (Builder $builder, int $id) => $builder->where('inventory_movements.created_by', $id))
            ->when($filters['reference_type'] ?? null, fn (Builder $builder, string $value) => $builder->where('inventory_movements.reference_type', $value))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', function (Builder $builder) use ($filters) {
                $search = '%'.trim($filters['search']).'%';
                $builder->where(fn (Builder $nested) => $nested->where('products.name', 'like', $search)->orWhere('products.sku', 'like', $search)->orWhere('product_variants.name', 'like', $search)->orWhere('product_variants.sku', 'like', $search));
            });
    }

    private function movementRowsQuery(array $filters, ReportPeriod $period): Builder
    {
        return $this->movementsQuery($filters, $period)->leftJoin('users', 'users.id', '=', 'inventory_movements.created_by')
            ->leftJoin('branches', 'branches.id', '=', 'inventory_movements.branch_id')
            ->select(['inventory_movements.id', 'inventory_movements.created_at', 'inventory_movements.branch_id', 'branches.code as branch_code', 'branches.name as branch_name', 'inventory_movements.type', 'inventory_movements.product_id', 'inventory_movements.product_variant_id', 'products.name as product_name', DB::raw('COALESCE(product_variants.sku,products.sku) sku'), 'product_variants.name as variant_name', 'inventory_movements.quantity_delta', 'inventory_movements.stock_before', 'inventory_movements.stock_after', 'inventory_movements.reason', 'inventory_movements.created_by', 'users.name as operator_name', 'inventory_movements.reference_type', 'inventory_movements.reference_id']);
    }

    private function inventoryRow(object $row, User $user): array
    {
        $item = [
            'type' => $row->type, 'product_id' => (int) $row->product_id,
            'product_variant_id' => $row->product_variant_id === null ? null : (int) $row->product_variant_id,
            'name' => $row->name, 'sku' => $row->sku, 'variant_name' => $row->variant_name, 'stock' => (int) $row->stock,
            'minimum_stock' => (int) $row->minimum_stock, 'status' => $row->status, 'sale_price' => (int) $row->sale_price,
            'branch' => ['id' => (int) $row->branch_id, 'code' => $row->branch_code, 'name' => $row->branch_name],
        ];
        if ($user->hasPermission('reports.financials')) {
            $item['current_cost'] = (int) $row->current_cost;
        }

        return $item;
    }

    private function inventorySort(string $sort): string
    {
        return match ($sort) {
            'stock' => 'inventory_stocks.quantity',
            'sku' => 'sku',
            default => 'products.name',
        };
    }
}
