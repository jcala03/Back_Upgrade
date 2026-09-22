<?php

namespace App\Support\Reports;

use App\Models\Branch;

final class ReportScope
{
    public static function branch(array $filters): ?Branch
    {
        return isset($filters['branch_id'])
            ? Branch::query()->findOrFail((int) $filters['branch_id'])
            : null;
    }

    public static function response(array $filters, ?ReportPeriod $period = null): array
    {
        $branch = self::branch($filters);

        return [
            'mode' => $branch ? 'branch' : 'general',
            'branch' => $branch ? ['id' => (int) $branch->id, 'code' => $branch->code, 'name' => $branch->name] : null,
            ...($period ? ['period' => $period->response()] : []),
        ];
    }
}
