<?php

namespace Tests\Support;

use App\Models\CrmNotification;
use App\Models\EmployeeCommission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/** Scope legacy ledger assertions to this transaction, without clearing persistent QA. */
trait TracksCommissionQa
{
    private array $qaStartIds = [];

    public function setUpTracksCommissionQa(): void
    {
        foreach (['employee_commissions', 'payments', 'crm_notifications', 'inventory_movements'] as $table) {
            $this->qaStartIds[$table] = (int) DB::table($table)->max('id');
        }
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function newCommissions(): Builder
    {
        return EmployeeCommission::query()->where('id', '>', $this->qaStartIds['employee_commissions']);
    }

    private function assertNewDatabaseCount(string $table, int $expected): void
    {
        $query = DB::table($table)->where('id', '>', $this->qaStartIds[$table]);
        if ($table === 'crm_notifications') {
            $query->where('type', CrmNotification::TYPE_COMMISSION_EARNED);
        }
        $this->assertSame($expected, $query->count(), 'New QA rows in '.$table);
    }
}
